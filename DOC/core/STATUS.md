# 365CMS Projektdokumentation | Core: Implementierungsstatus

> **Stand:** 2026-11-23 | **Version:** 4.2.0 | **Schema:** v27 | **Status:** Stable

## English (summary)

Runtime snapshot verified against 4.2.0 on 2026-11-23. The Community module is implemented as a core module with public and member routes, admin management, Community-specific access/moderation services, retention/privacy integration and schema `v27`. Lists use keyset pagination without `OFFSET`; Community values are stored in UTC and access decisions fail closed.

## Deutsch

### Versionsstand

| Merkmal | Wert | Quelle |
|---|---|---|
| Core-Version | `4.2.0`, Release `2026-11-23`, Status `stable` | `CMS/core/Version.php`, `CMS/update.json` |
| Schema | `v27` (118 Tabellen: 90 bis `v26` plus 28 des Community-Moduls, `FEATURE_TABLES_V27`) | `SchemaManager::SCHEMA_VERSION`, `MigrationManager::SCHEMA_VERSION`, `SchemaManager::FEATURE_TABLES_V26`, `SchemaManager::FEATURE_TABLES_V27`, [DATABASE-SCHEMA.md](DATABASE-SCHEMA.md) |
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
| Zentrale CLI (`CMS/cli.php`), Echtzeit-Alerting (`alert.raised`) | **geplant für 4.3.0** | [../../DOC_PLAN/specs/v4.1/06-CLI-ALERTING.md](../../DOC_PLAN/specs/v4.1/06-CLI-ALERTING.md) |
| Community-Modul (Forum, Moderation, Anhänge, Benachrichtigungen) | produktiv; Kernmodul `community`, Schema `v27`, standardmäßig deaktiviert | [../member/COMMUNITY.md](../member/COMMUNITY.md), [../admin/community/README.md](../admin/community/README.md) |
| JSON-API | lesend + Admin-Listen, Session oder optional Bearer-Token (`JWT_SECRET`) | [API-REFERENCE.md](API-REFERENCE.md) |

### Offene Punkte

| Punkt | Stand | Weiterführend |
|---|---|---|
| Signierte Update-Pakete (OFFEN-27) | offen; Prüfsumme (SHA-256) wird bereits erzwungen | [../audit/FOLGEAUDIT-2026-11-09.md](../audit/FOLGEAUDIT-2026-11-09.md) |
| Zentrale CLI und Echtzeit-Alerting | Release 4.3.0 | [../../DOC_PLAN/specs/v4.1/06-CLI-ALERTING.md](../../DOC_PLAN/specs/v4.1/06-CLI-ALERTING.md) |
| E-Rechnung (ZUGFeRD/XRechnung) | nicht enthalten | [../admin/subscription/INVOICES.md](../admin/subscription/INVOICES.md) |

Behobene Befunde früherer Prüfungen stehen im [Changelog](../../Changelog.md) und in den Berichten unter [../audit/](../audit/README.md); das Prüfprotokoll des aktuellen Releases ist [../audit/RELEASE-AUDIT-4.1.0-2026-11-15.md](../audit/RELEASE-AUDIT-4.1.0-2026-11-15.md).

### Community 4.2.0 – umgesetzte Architektur

- `CoreModuleService` verwaltet den standardmäßig ausgeschalteten Schalter `community`; `CommunityModule::isEnabled()` legt darüber eine fail-closed-Abfrage. Bei einer nicht lesbaren Modulkonfiguration ist Community aus.
- `CommunityAccess` ist die einzige Entscheidungsstelle für Lesen, Schreiben und Moderation. Es vereinigt Modulstatus, Konto/SSO-Reife, Sperren, sechs globale Capabilities, Forenrechte, Vertrauensstufe, Zustände, Gründe, Rate-Limits und den Filter `cms_community_can`.
- Die Lesecontroller verwenden `ReadScope` zur Request-Lokalisierung wiederholter Foren-/Themen-Lesen; große Listen verwenden `Cursor`/Keyset statt SQL-`OFFSET`.
- Schreibvorgänge laufen über `WriteGate` und `ContentGuard`; `CaptchaGate` nutzt ausschließlich lokale Verfahren (`pow`, `image`, `math`) des Core.
- Öffentliche Adressen (`/forum`, englisch `/en/forum`) liefern Berechtigungsfehler als 404. Interaktionen liegen unter `/forum/api/*`; Dateien liegen privat und werden nur nach erneuter Rechteprüfung ausgeliefert. Öffentliche Themenseiten erhalten JSON-LD.
- `TopicTrashService`/`CommunityContentProvider` verbinden Themen mit dem zentralen Papierkorb. Zähler folgen der einheitlichen Semantik S1 in `CounterBook`.
- `CommunityRetention`, `CommunityPrivacy` und `CommunityLegal` registrieren Aufbewahrung, Export/Löschung (`anonymize` oder `delete`) und den Abschnitt „8a. Forum (Community)“ unabhängig vom aktuellen Modulschalter.

### Grundsatz dieser Dokumentation

Dokumentiert wird, was im ausgelieferten Code nachvollziehbar ist: Pfade, Klassen, Routen, Tabellen und Hooks lassen sich in `CMS/` finden. Wo Code und Doku abweichen, gilt der Code.

### Verwandte Dokumente

[README.md](README.md) · [../README.md](../README.md) · [../../Changelog.md](../../Changelog.md)
