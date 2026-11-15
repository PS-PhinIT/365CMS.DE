# 365CMS Projektdokumentation | Core: Implementierungsstatus

> **Stand:** 2026-11-15 | **Version:** 4.1.0 | **Schema:** v26 | **Status:** Stable

## English (summary)

Snapshot of the runtime state verified against the source code on 2026-10-02 and rechecked for 4.0.0 on 2026-11-09: version and schema constants, feature areas and their maturity, the changes recorded since the 3.4.00 release (bundled in release 3.5.0), and a list of known gaps and inconsistencies found while updating the documentation. Release 4.1.0 (version `4.1.0`, schema `v26`) closes the gaps listed in "In 4.1.0 geschlossene Lücken"; CLI and alerting move to 4.3.0, the community module is planned for 4.2.0 (schema `v27`).

## Deutsch

### Versionsstand

| Merkmal | Wert | Quelle |
|---|---|---|
| Core-Version | `4.1.0`, Release `2026-11-15`, Status `stable` | `CMS/core/Version.php`, `CMS/update.json` |
| Schema | `v26` (90 Tabellen: 76 bis `v25` plus 14 neu in `v26`) | `SchemaManager::SCHEMA_VERSION`, `MigrationManager::SCHEMA_VERSION`, `SchemaManager::FEATURE_TABLES_V26`, [DATABASE-SCHEMA.md](DATABASE-SCHEMA.md) |
| PHP | ≥ 8.4.1 | `CMS_MIN_PHP_VERSION` in `CMS/config.php` und `CMS\Version::MIN_PHP` (es zählt das Maximum, `Version::minimumPhp()`), `min_php` in `update.json` |
| Datenbank | MySQL ≥ 5.7 / MariaDB (empfohlen MySQL 8.0+ / MariaDB 10.6+) | Update-Preflight |
| Standard-Theme | `cms-default` 1.1.0 („Meridian CMS Default“) | `CMS/themes/cms-default/theme.json` |
| Mitgeliefertes Plugin | `cms-importer` 3.0.3 | `CMS/plugins/cms-importer/cms-importer.php` |
| Änderungshistorie | alle Releases | [../../Changelog.md](../../Changelog.md) |


### Funktionsbereiche

| Bereich | Status | Dokumentation |
|---|---|---|
| Seiten/Beiträge (DE/EN, Revisionen mit Vergleich/Wiederherstellen, Planung, Veröffentlichungs-Checkliste, Autosave) | produktiv | [../admin/pages-posts/](../admin/pages-posts/README.md) |
| Admin-Oberfläche (Seitenleiste, Site-Umschalter, Plugin-Zuweisung je Site, Systemhinweise) | produktiv | [../admin/README.md](../admin/README.md) |
| Editor.js / SunEditor | produktiv | [../assets/editorjs/README.md](../assets/editorjs/README.md) |
| Medien (Bibliothek, WebP, Thumbnails) | produktiv | [../admin/media/MEDIA.md](../admin/media/MEDIA.md) |
| Medien: AVIF-Fassungen, `<picture>`-Ausgabe, Verwendungsnachweis | produktiv | [../admin/media/MEDIA-TOOLS.md](../admin/media/MEDIA-TOOLS.md) |
| Vier-Augen-Prinzip, Vorschau-Links, EditorJS Block Vorlagen, Ablauf und Wiedervorlage | produktiv | [../admin/pages-posts/REVIEW.md](../admin/pages-posts/REVIEW.md), [../admin/pages-posts/BLOCK-TEMPLATES.md](../admin/pages-posts/BLOCK-TEMPLATES.md), [../admin/pages-posts/EDITORIAL-CALENDAR.md](../admin/pages-posts/EDITORIAL-CALENDAR.md) |
| Benutzer, Rollen, Gruppen | produktiv | [../admin/users-groups/](../admin/users-groups/README.md) |
| Anmeldung (Passwort, TOTP, Passkeys, LDAP) | produktiv | [SECURITY.md](SECURITY.md) |
| Passwort-Richtlinie, MFA-Zwang je Rolle, Microsoft Entra ID (SSO) | produktiv | [../admin/security/LOGIN-THRESHOLDS.md](../admin/security/LOGIN-THRESHOLDS.md), [../admin/security/SSO-ENTRA.md](../admin/security/SSO-ENTRA.md) |
| Mitgliederbereich | produktiv | [../member/README.md](../member/README.md) |
| Aboverwaltung | produktiv, mit Online-Zahlung (Stripe, Mollie) | [../admin/subscription/](../admin/subscription/README.md), [../admin/subscription/PAYMENTS.md](../admin/subscription/PAYMENTS.md) |
| SEO-Suite, Sitemaps, Weiterleitungen | produktiv | [../admin/seo/](../admin/seo/README.md) |
| Rechtstexte, Cookie-Consent, DSGVO-Anfragen | produktiv | [../admin/legal/](../admin/legal/README.md) |
| Firewall, Anti-Spam, Audit | produktiv | [../admin/security/](../admin/security/README.md) |
| KI-Dienste | produktiv (nur Admin, keine Auto-Veröffentlichung) | [../ai/AI-SERVICES.md](../ai/AI-SERVICES.md) |
| Backups, Updates, Monitoring, Cron | produktiv, mit S3-Offsite-Kopien | [../admin/system-settings/](../admin/system-settings/README.md) |
| Ausgehende Webhooks | produktiv | [../admin/system-settings/WEBHOOKS.md](../admin/system-settings/WEBHOOKS.md) |
| Zentrale CLI (`CMS/cli.php`), Echtzeit-Alerting (`alert.raised`) | **geplant für 4.3.0** (Bereich 6, nicht Teil von 4.1.0) | [../workflow/specs/v4.1/06-CLI-ALERTING.md](../workflow/specs/v4.1/06-CLI-ALERTING.md) |
| Community-Modul (Forum, Gruppen, Moderation) | **geplant für 4.2.0**, Schema `v27`, noch kein Code | [../workflow/specs/community/README.md](../workflow/specs/community/README.md) |
| JSON-API | lesend + Admin-Listen, Session oder optional Bearer-Token (`JWT_SECRET`) | [API-REFERENCE.md](API-REFERENCE.md) |

### Offene Punkte

| Punkt | Stand | Weiterführend |
|---|---|---|
| Signierte Update-Pakete (OFFEN-27) | offen; Prüfsumme (SHA-256) wird bereits erzwungen | [../audit/FOLGEAUDIT-2026-11-09.md](../audit/FOLGEAUDIT-2026-11-09.md) |
| Zentrale CLI und Echtzeit-Alerting | Release 4.3.0 | [../workflow/specs/v4.1/06-CLI-ALERTING.md](../workflow/specs/v4.1/06-CLI-ALERTING.md) |
| Community-Modul (Forum) | Release 4.2.0, Schema `v27` | [../workflow/specs/community/IMPLEMENTATION-PLAN.md](../workflow/specs/community/IMPLEMENTATION-PLAN.md) |
| E-Rechnung (ZUGFeRD/XRechnung) | nicht enthalten | [../admin/subscription/INVOICES.md](../admin/subscription/INVOICES.md) |

Behobene Befunde früherer Prüfungen stehen im [Changelog](../../Changelog.md) und in den Berichten unter [../audit/](../audit/README.md); das Prüfprotokoll des aktuellen Releases ist [../audit/RELEASE-AUDIT-4.1.0-2026-11-15.md](../audit/RELEASE-AUDIT-4.1.0-2026-11-15.md).

### Grundsatz dieser Dokumentation

Dokumentiert wird, was im ausgelieferten Code nachvollziehbar ist: Pfade, Klassen, Routen, Tabellen und Hooks lassen sich in `CMS/` finden. Wo Code und Doku abweichen, gilt der Code.

### Verwandte Dokumente

[README.md](README.md) · [../README.md](../README.md) · [../../Changelog.md](../../Changelog.md)
