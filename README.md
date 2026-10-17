# 365CMS

> **Stand:** 2026-10-17 | **Version:** 3.5.2 | **Status:** Stable | **Website:** [365cms.de](https://365cms.de/)

## English

### What is 365CMS?

365CMS is a self-hosted, framework-less PHP CMS and portal platform for content, members, themes, plugins, SEO, privacy and day-to-day operations. The runtime is located in [`CMS/`](CMS/); the public documentation is maintained in [`DOC/`](DOC/).

| Key fact | Value |
|---|---|
| Core version | `3.5.2` ([`CMS/core/Version.php`](CMS/core/Version.php), released 2026-10-17, status `stable`) |
| PHP | `8.4.1+` (`CMS\Version::MIN_PHP`, `CMS_MIN_PHP_VERSION`) |
| Database | MySQL / MariaDB via PDO, table prefix `cms_`, schema version `v23` |
| Shipped theme / plugin | `cms-default` (Meridian CMS Default) / `cms-importer` (WordPress importer) |

### Core capabilities

| Area | Current runtime capability |
|---|---|
| Content | Pages, posts, Editor.js blocks (SunEditor as legacy option), revisions, categories, tags, scheduled publishing, DE/EN content, hub sites, site tables, TOC |
| Administration | Dashboard, users, groups, roles/capabilities, settings, media library, menus, themes, customizer, fonts, module manager |
| Members | Member area under `/member`: dashboard, profile, security (MFA, passkeys, sessions), media, messages, notifications, favorites, privacy, subscriptions, plugin sections |
| SEO & legal | Meta/OG/schema, sitemaps, redirects/404 monitor, analytics with consent, legal-text generator, cookie manager, GDPR export/deletion requests |
| Security | CSRF tokens per action, capability checks, CSP with nonces and Trusted Types, rate limits, MFA/TOTP, WebAuthn, LDAP, audit log, hardened uploads |
| AI (optional) | Admin-only AI services: translation of Editor.js content and SEO metadata with provider policy and quotas – no public AI routes |
| Extensions | Plugins in `CMS/plugins/`, themes in `CMS/themes/`, marketplace with SHA-256-verified installs |
| Operations | Cache/performance, logs, backups/restore, cron (`cron.php`), mail queue (SMTP or Microsoft Graph), monitoring, schema and core updates |

### Requirements

- PHP `8.4.1+` with `pdo`, `pdo_mysql`, `mbstring`, `json`, `curl`, `gd`, `zip`
- MySQL- or MariaDB-compatible database
- Web server (Apache with `.htaccess` or nginx with equivalent rules) serving `CMS/`
- Writable `config/`, `uploads/`, `cache/`, `logs/`, `backups/`

### Quick start

```text
1. Deploy the contents of CMS/ to the web root (or point the web root to CMS/).
2. Open /install.php in the browser and follow the steps (environment, database, site, administrator).
3. The installer writes CMS/config/app.php and locks itself afterwards.
4. Log in at /cms-login, open /admin and review settings, mail and backups.
5. Set up the cron job (php CMS/cron.php --task=all).
```

Never ship installation-specific `config/`, `uploads/`, `cache/`, `logs/` or `backups/` in a release archive.

### Documentation

| Topic | Link |
|---|---|
| Documentation hub | [`DOC/README.md`](DOC/README.md) |
| Documentation index | [`DOC/INDEX.md`](DOC/INDEX.md) |
| Installation | [`DOC/INSTALLATION.md`](DOC/INSTALLATION.md) |
| Architecture / core status | [`DOC/core/ARCHITECTURE.md`](DOC/core/ARCHITECTURE.md) · [`DOC/core/STATUS.md`](DOC/core/STATUS.md) |
| Admin panel | [`DOC/admin/README.md`](DOC/admin/README.md) |
| Plugin / theme development | [`DOC/plugins/PLUGIN-DEVELOPMENT.md`](DOC/plugins/PLUGIN-DEVELOPMENT.md) · [`DOC/theme/THEME-DEVELOPMENT.md`](DOC/theme/THEME-DEVELOPMENT.md) |
| Workflows | [`DOC/workflow/`](DOC/workflow/) |
| Audit reports | [`DOC/audit/README.md`](DOC/audit/README.md) · [`DOC/checks/README.md`](DOC/checks/README.md) |
| Release packages | [`RELEASE/README-3.5.2.md`](RELEASE/README-3.5.2.md) |
| Release history | [`Changelog.md`](Changelog.md) |
| Community rules | [`CODE_OF_CONDUCT.md`](CODE_OF_CONDUCT.md) |

### Contributing and security

Keep changes focused, document behavior changes in `DOC/` and [`Changelog.md`](Changelog.md), and validate PHP syntax (`php -l`) before opening a pull request. Report security issues privately to the maintainers instead of publishing exploit details in a public issue.

## Deutsch

### Was ist 365CMS?

365CMS ist ein selbst gehostetes, frameworkfreies PHP-CMS und Portal-System für Inhalte, Mitglieder, Themes, Plugins, SEO, Datenschutz und den laufenden Betrieb. Die Runtime liegt unter [`CMS/`](CMS/), die öffentliche Projektdokumentation unter [`DOC/`](DOC/).

| Eckdaten | Wert |
|---|---|
| Core-Version | `3.5.2` ([`CMS/core/Version.php`](CMS/core/Version.php), veröffentlicht 2026-10-17, Status `stable`) |
| PHP | `8.4.1+` (`CMS\Version::MIN_PHP`, `CMS_MIN_PHP_VERSION`) |
| Datenbank | MySQL / MariaDB über PDO, Tabellenpräfix `cms_`, Schema-Version `v23` |
| Mitgeliefertes Theme / Plugin | `cms-default` (Meridian CMS Default) / `cms-importer` (WordPress-Import) |

### Zentrale Funktionen

| Bereich | Aktuelle Runtime-Funktion |
|---|---|
| Inhalte | Seiten, Beiträge, Editor.js-Blöcke (SunEditor als Legacy-Option), Revisionen, Kategorien, Tags, geplante Veröffentlichung, DE/EN-Inhalte, Hub-Sites, Site-Tabellen, Inhaltsverzeichnis |
| Administration | Dashboard, Benutzer, Gruppen, Rollen/Capabilities, Einstellungen, Medienbibliothek, Menüs, Themes, Customizer, Schriften, Modul-Manager |
| Mitglieder | Mitgliederbereich unter `/member`: Dashboard, Profil, Sicherheit (MFA, Passkeys, Sitzungen), Medien, Nachrichten, Benachrichtigungen, Favoriten, Datenschutz, Abos, Plugin-Bereiche |
| SEO & Recht | Meta/OG/Schema, Sitemaps, Weiterleitungen/404-Monitor, Analytics mit Einwilligung, Rechtstexte-Generator, Cookie-Manager, DSGVO-Auskunft/-Löschung |
| Sicherheit | CSRF-Token je Aktion, Capability-Prüfungen, CSP mit Nonces und Trusted Types, Rate-Limits, MFA/TOTP, WebAuthn, LDAP, Audit-Log, gehärtete Uploads |
| KI (optional) | AI Services nur im Admin: Übersetzung von Editor.js-Inhalten und SEO-Metadaten mit Provider-Policy und Quotas – keine öffentlichen AI-Routen |
| Erweiterungen | Plugins in `CMS/plugins/`, Themes in `CMS/themes/`, Marketplace mit SHA-256-geprüfter Installation |
| Betrieb | Cache/Performance, Logs, Backups/Wiederherstellung, Cron (`cron.php`), Mail-Queue (SMTP oder Microsoft Graph), Monitoring, Schema- und Core-Updates |

### Voraussetzungen

- PHP `8.4.1+` mit `pdo`, `pdo_mysql`, `mbstring`, `json`, `curl`, `gd`, `zip`
- MySQL- oder MariaDB-kompatible Datenbank
- Webserver (Apache mit `.htaccess` oder nginx mit gleichwertigen Regeln), der `CMS/` ausliefert
- Schreibrechte für `config/`, `uploads/`, `cache/`, `logs/`, `backups/`

### Schnellstart

```text
1. Inhalt von CMS/ in den Webroot kopieren (oder Webroot auf CMS/ zeigen lassen).
2. /install.php im Browser öffnen und den Schritten folgen (Umgebung, Datenbank, Website, Administrator).
3. Der Installer schreibt CMS/config/app.php und sperrt sich danach selbst.
4. Unter /cms-login anmelden, /admin öffnen, Einstellungen, Mailversand und Backups prüfen.
5. Cronjob einrichten (php CMS/cron.php --task=all).
```

Installationsspezifische Verzeichnisse (`config/`, `uploads/`, `cache/`, `logs/`, `backups/`) gehören nie in ein Release-Archiv. Details: [`DOC/INSTALLATION.md`](DOC/INSTALLATION.md) und [`DOC/workflow/UPDATE-DEPLOYMENT-WORKFLOW.md`](DOC/workflow/UPDATE-DEPLOYMENT-WORKFLOW.md).

### Dokumentation

Einstieg ist [`DOC/README.md`](DOC/README.md), die vollständige Liste steht in [`DOC/INDEX.md`](DOC/INDEX.md). Bekannte Abweichungen zwischen Code und erwartetem Verhalten sind in [`DOC/core/STATUS.md`](DOC/core/STATUS.md) („Bekannte Lücken“) gesammelt. Audit-Berichte: [`DOC/audit/README.md`](DOC/audit/README.md) (aktuell) und [`DOC/checks/README.md`](DOC/checks/README.md) (frühere Audits); Release-Pakete: [`RELEASE/README-3.5.2.md`](RELEASE/README-3.5.2.md).

### Mitwirken und Sicherheit

Bitte Änderungen fokussiert halten, Verhaltensänderungen in `DOC/` und [`Changelog.md`](Changelog.md) dokumentieren und die PHP-Syntax (`php -l`) vor einem Pull Request prüfen. Es gilt der [`CODE_OF_CONDUCT.md`](CODE_OF_CONDUCT.md); Sicherheitsprobleme bitte privat an die Maintainer melden.
