# 365CMS – Projektdokumentation | Abschnitt: INDEX
> **Stand:** 2026-11-15 | **Version:** 4.1.0 | **Status:** Stable | **Update:** 2026-11-15

## Table of contents | Inhaltsverzeichnis

- [Quick start](#quick-start--schnellstart)
- [Core](#core--kernsystem)
- [Admin](#admin--admin-bereich)
- [Member](#member--mitgliederbereich)
- [Themes and plugins](#themes-and-plugins--themes-und-plugins)
- [Assets, workflows and audits](#assets-workflows-and-audits--assets-workflows-und-audits)
- [Roadmap 4.2.0 / 4.3.0](#roadmap-420--430--planung-und-spezifikationen)
- [Further documents](#further-documents--weitere-dokumente)
- [Task shortcuts](#task-shortcuts--direktlinks-für-häufige-aufgaben)

---

## Quick start | Schnellstart

**English**

Use this index to reach the canonical documents. Product version in code is `4.1.0` (schema `v26`). Runtime lives in [`CMS/`](../CMS/). Documentation lives in [`DOC/`](./). Known code/doc gaps are listed in [core/STATUS.md](core/STATUS.md).

**Deutsch**

Dieser Index führt zu den kanonischen Dokumenten. Die Produktversion im Code ist `4.1.0` (Schema `v26`). Die Runtime liegt in [`CMS/`](../CMS/). Die Dokumentation liegt in [`DOC/`](./). Bekannte Lücken zwischen Code und Doku stehen in [core/STATUS.md](core/STATUS.md).

| Goal / Ziel | Document / Dokument |
|---|---|
| Project overview | [README.md](README.md) |
| Developer reference | [DEVLIST.md](DEVLIST.md) |
| Runtime structure map | [FILELIST.md](FILELIST.md) |
| Repository layout | [FILESTRUCTUR.md](FILESTRUCTUR.md) |
| CMS runtime inventory | [CMSFILESTRUCTUR.md](CMSFILESTRUCTUR.md) |
| Installation | [INSTALLATION.md](INSTALLATION.md) |
| Root README | [../README.md](../README.md) |
| Changelog | [../Changelog.md](../Changelog.md) |
| Status / known gaps | [core/STATUS.md](core/STATUS.md), [audit/README.md](audit/README.md) |

---

## Core | Kernsystem

**English**

Core documents describe bootstrap, routing, schema, services, hooks, APIs, and security.

**Deutsch**

Die Core-Dokumente beschreiben Bootstrap, Routing, Schema, Services, Hooks, APIs und Sicherheit.

| Document / Dokument | Purpose / Zweck |
|---|---|
| [core/README.md](core/README.md) | Core documentation entry |
| [core/ARCHITECTURE.md](core/ARCHITECTURE.md) | Bootstrap, routing, services, modules |
| [core/CORE-CLASSES.md](core/CORE-CLASSES.md) | Central core classes |
| [core/DATABASE-SCHEMA.md](core/DATABASE-SCHEMA.md) | Base schema and module tables (schema `v26`, 90 tables) |
| [core/HOOKS-REFERENCE.md](core/HOOKS-REFERENCE.md) | Actions, filters, integration points |
| [core/API-REFERENCE.md](core/API-REFERENCE.md) | Technical interfaces |
| [core/SERVICES.md](core/SERVICES.md) | Service layer |
| [core/CONTENT-TYPE-PROVIDERS.md](core/CONTENT-TYPE-PROVIDERS.md) | Content type providers for trash, export/import and search |
| [core/SECURITY.md](core/SECURITY.md) | Core security model |
| [core/STATUS.md](core/STATUS.md) | Implementation and operations status |
| [core/STRUCTURE.md](core/STRUCTURE.md) | Core structure notes |

---

## Admin | Admin-Bereich

**English**

Admin docs follow `CMS/admin/` (entry PHP files, `modules/`, `views/`, `partials/`). Login branding is `/admin/cms-loginpage`. Runtime logs are `/admin/cms-logs`.

**Deutsch**

Die Admin-Dokumente folgen `CMS/admin/` (PHP-Einstiege, `modules/`, `views/`, `partials/`). Auth-Branding liegt unter `/admin/cms-loginpage`. Laufzeitlogs liegen unter `/admin/cms-logs`.

| Document / Dokument | Purpose / Zweck |
|---|---|
| [admin/README.md](admin/README.md) | Navigation (sidebar profiles, admin shell) and area overview |
| [admin/ADMIN-MENU.md](admin/ADMIN-MENU.md) | Admin menu: profiles Classic/Structured/Custom, page catalogue, sidebar editor, tile pages, plugin menu placement |
| [admin/GUIDE.md](admin/GUIDE.md) | Operator guide |
| [admin/FILESTRUCTURE.md](admin/FILESTRUCTURE.md) | Admin file structure and routing |
| [admin/PANEL-INTEGRATION.md](admin/PANEL-INTEGRATION.md) | Custom admin page integration |
| [admin/ADMIN-API-AJAX.md](admin/ADMIN-API-AJAX.md) | Admin API / AJAX (incl. `/admin/admin-ui`, `/admin/post-editor-api`) |
| [admin/PRUEF-CHECKLISTE.md](admin/PRUEF-CHECKLISTE.md) | Admin review checklist |

### Admin areas | Wichtige Teilbereiche

| Area / Bereich | Document / Dokument |
|---|---|
| Dashboard | [admin/dashboard/README.md](admin/dashboard/README.md) |
| Pages & posts (editor, publish checklist, revisions: [POSTS.md](admin/pages-posts/POSTS.md)) | [admin/pages-posts/README.md](admin/pages-posts/README.md) |
| Media | [admin/media/README.md](admin/media/README.md) |
| Users & groups | [admin/users-groups/README.md](admin/users-groups/README.md) |
| Auth settings | [admin/users-groups/AUTH-SETTINGS.md](admin/users-groups/AUTH-SETTINGS.md) |
| Themes & design | [admin/themes-design/README.md](admin/themes-design/README.md) |
| CMS login page | [admin/themes-design/CMS-LOGINPAGE.md](admin/themes-design/CMS-LOGINPAGE.md) |
| Plugins, plugin assignment per site | [admin/plugins/PLUGINS.md](admin/plugins/PLUGINS.md) |
| SEO | [admin/seo/SEO.md](admin/seo/SEO.md) |
| Legal | [admin/legal/README.md](admin/legal/README.md) |
| Security | [admin/security/README.md](admin/security/README.md) |
| Login thresholds and lockout | [admin/security/LOGIN-THRESHOLDS.md](admin/security/LOGIN-THRESHOLDS.md) |
| Sign-in with Microsoft Entra ID (SSO, 4.1.0) | [admin/security/SSO-ENTRA.md](admin/security/SSO-ENTRA.md) |
| Four-eyes review, preview links (4.1.0) | [admin/pages-posts/REVIEW.md](admin/pages-posts/REVIEW.md) |
| EditorJS block templates (4.1.0) | [admin/pages-posts/BLOCK-TEMPLATES.md](admin/pages-posts/BLOCK-TEMPLATES.md) |
| Editorial calendar, expiry and review dates (4.0.0 / 4.1.0) | [admin/pages-posts/EDITORIAL-CALENDAR.md](admin/pages-posts/EDITORIAL-CALENDAR.md) |
| Outbound webhooks (4.1.0) | [admin/system-settings/WEBHOOKS.md](admin/system-settings/WEBHOOKS.md) |
| Online payments Stripe / Mollie (4.1.0) | [admin/subscription/PAYMENTS.md](admin/subscription/PAYMENTS.md) |
| Invoices and credit notes (4.1.0) | [admin/subscription/INVOICES.md](admin/subscription/INVOICES.md) |
| Roles and capabilities (RBAC) | [admin/users-groups/RBAC.md](admin/users-groups/RBAC.md) |
| Performance | [admin/performance/PERFORMANCE.md](admin/performance/PERFORMANCE.md) |
| System & maintenance | [admin/system-settings/README.md](admin/system-settings/README.md) |
| Toolbar: cache & maintenance mode | [admin/system-settings/TOOLBAR.md](admin/system-settings/TOOLBAR.md) |
| Trash (pages, posts, media, hub sites, downloads, forms, knowledge base) | [admin/pages-posts/TRASH.md](admin/pages-posts/TRASH.md) |
| Media tools (regenerate thumbnails, image credits) | [admin/media/MEDIA-TOOLS.md](admin/media/MEDIA-TOOLS.md) |
| Accessibility statement | [admin/legal/ACCESSIBILITY.md](admin/legal/ACCESSIBILITY.md) |
| Forms & submissions (builder, file uploads) | [admin/pages-posts/FORMS.md](admin/pages-posts/FORMS.md) |
| Retention periods (all deletion rules, dry run) | [admin/legal/DATA-RETENTION.md](admin/legal/DATA-RETENTION.md) |
| Recent changes (revision timeline of posts and pages) | [admin/pages-posts/CHANGES.md](admin/pages-posts/CHANGES.md) |
| Translation status | [admin/pages-posts/TRANSLATIONS.md](admin/pages-posts/TRANSLATIONS.md) |
| Content import/export (ZIP) | [admin/system-settings/CONTENT-EXCHANGE.md](admin/system-settings/CONTENT-EXCHANGE.md) |
| Search index & search terms | [admin/system-settings/SEARCH-INDEX.md](admin/system-settings/SEARCH-INDEX.md) |
| MultiSites (admin context per domain: accent, site switcher, plugin assignment, menu layout) | [admin/system-settings/MULTISITES.md](admin/system-settings/MULTISITES.md) |
| AI services (admin) | [admin/system-settings/AI-SERVICES.md](admin/system-settings/AI-SERVICES.md) |
| Mail & mail queue | [admin/system-settings/MAIL.md](admin/system-settings/MAIL.md) |
| Feeds (aggregator, RSS output) | [admin/system-settings/FEEDS.md](admin/system-settings/FEEDS.md) |
| Downloads | [admin/media/DOWNLOADS.md](admin/media/DOWNLOADS.md) |
| Newsletter (double opt-in, campaigns, RFC 8058 unsubscribe) | [admin/pages-posts/NEWSLETTER.md](admin/pages-posts/NEWSLETTER.md) |
| Knowledge base (glossary, automatic term linking) | [admin/pages-posts/KNOWLEDGEBASE.md](admin/pages-posts/KNOWLEDGEBASE.md) |
| Core modules | [admin/system-settings/MODULES.md](admin/system-settings/MODULES.md) |
| Backups / updates / monitoring | [admin/system-settings/BACKUP.md](admin/system-settings/BACKUP.md) · [UPDATES.md](admin/system-settings/UPDATES.md) · [MONITORING.md](admin/system-settings/MONITORING.md) |
| Diagnostics | [admin/diagnose/DIAGNOSE.md](admin/diagnose/DIAGNOSE.md) |
| System info | [admin/info/INFO.md](admin/info/INFO.md) |
| Landing pages | [admin/landing-page/LANDING-PAGE.md](admin/landing-page/LANDING-PAGE.md) |
| Subscriptions | [admin/subscription/SUBSCRIPTION-SYSTEM.md](admin/subscription/SUBSCRIPTION-SYSTEM.md) |
| Member dashboard (admin) | [admin/member/README.md](admin/member/README.md) |

### AI

**English**

Canonical AI concept documentation, including provider scope and Editor.js translation, is [ai/AI-SERVICES.md](ai/AI-SERVICES.md). Related: [ai/AI-ASSETS.md](ai/AI-ASSETS.md).

**Deutsch**

Die kanonische AI-Konzeptdokumentation mit Provider-Scope und Editor.js-Übersetzung ist [ai/AI-SERVICES.md](ai/AI-SERVICES.md). Ergänzend: [ai/AI-ASSETS.md](ai/AI-ASSETS.md).

---

## Member | Mitgliederbereich

**English**

Member documentation is **not** limited to a single README. These files exist:

**Deutsch**

Die Member-Dokumentation ist **nicht** nur in einer README gebündelt. Diese Dateien existieren:

| Document / Dokument | Purpose / Zweck |
|---|---|
| [member/README.md](member/README.md) | `/member` overview |
| [member/MEMBER-DASHBOARD.md](member/MEMBER-DASHBOARD.md) | Member dashboard |
| [member/MEMBER-ROUTES.md](member/MEMBER-ROUTES.md) | Member routes |
| [member/MEMBER-SECURITY.md](member/MEMBER-SECURITY.md) | Member security |

Runtime files are under [`CMS/member/`](../CMS/member/).

---

## Themes and plugins | Themes und Plugins

**English**

Runtime currently loads themes from `CMS/themes/` (`cms-default`) and plugins from `CMS/plugins/` (`cms-importer`). Separate workspace repos `365CMS.DE-THEME` and `365CMS.DE-PLUGINS` are source trees, not automatic runtime paths.

**Deutsch**

Die Runtime lädt Themes aus `CMS/themes/` (`cms-default`) und Plugins aus `CMS/plugins/` (`cms-importer`). Die separaten Workspace-Repos `365CMS.DE-THEME` und `365CMS.DE-PLUGINS` sind Quellbäume, keine automatischen Runtime-Pfade.

| Document / Dokument | Purpose / Zweck |
|---|---|
| [theme/README.md](theme/README.md) | Theme system |
| [theme/THEME-DEVELOPMENT.md](theme/THEME-DEVELOPMENT.md) | Theme creation |
| [theme/DEVELOPMENT.md](theme/DEVELOPMENT.md) | Theme development notes |
| [theme/DESIGN-SYSTEM.md](theme/DESIGN-SYSTEM.md) | Design system |
| [theme/COMPONENTS.md](theme/COMPONENTS.md) | Components |
| [theme/JAVASCRIPT.md](theme/JAVASCRIPT.md) | Theme JavaScript |
| [plugins/GUIDE.md](plugins/GUIDE.md) | Plugin quick start |
| [plugins/PLUGIN-DEVELOPMENT.md](plugins/PLUGIN-DEVELOPMENT.md) | Full plugin guide |
| [plugins/PLUGIN-MARKETPLACE.md](plugins/PLUGIN-MARKETPLACE.md) | Plugin marketplace |

---

## Assets, workflows and audits | Assets, Workflows und Audits

**English**

Asset docs live under [`assets/`](assets/), not in the `DOC/` root. For technical facts, prefer `DEVLIST.md`, `FILELIST.md`, and the nearest core/admin/asset document.

**Deutsch**

Asset-Dokumente liegen unter [`assets/`](assets/), nicht in der `DOC/`-Wurzel. Für technische Aussagen haben `DEVLIST.md`, `FILELIST.md` und die jeweils bereichsnahen Core-/Admin-/Asset-Dokumente Vorrang.

| Area / Bereich | Documents / Dokumente |
|---|---|
| Assets | [assets/ASSET.md](assets/ASSET.md), [assets/README.md](assets/README.md), [assets/ASSETS_NEW.md](assets/ASSETS_NEW.md), [assets/ASSETS_OwnAssets.md](assets/ASSETS_OwnAssets.md) |
| Audits | Full audit 2026-10-14 (security, speed, mass requests, functions, references, GUI): [audit/README.md](audit/README.md), follow-up audit: [audit/FOLGEAUDIT-2026-10-14.md](audit/FOLGEAUDIT-2026-10-14.md); follow-up audit 2026-11-08 (3.5.6 → 4.0.0: security, libraries, functions, backups, status displays): [audit/FOLGEAUDIT-2026-11-08.md](audit/FOLGEAUDIT-2026-11-08.md); release audit 4.1.0: [audit/RELEASE-AUDIT-4.1.0-2026-11-15.md](audit/RELEASE-AUDIT-4.1.0-2026-11-15.md); runtime check: `/admin/security-audit` ([admin/security/SECURITY-AUDIT.md](admin/security/SECURITY-AUDIT.md)); open gaps: [core/STATUS.md](core/STATUS.md) |
| Workflows | [workflow/](workflow/) |

### Workflows

| File / Datei | Description / Beschreibung |
|---|---|
| [CONTENT-MANAGEMENT-WORKFLOW.md](workflow/CONTENT-MANAGEMENT-WORKFLOW.md) | Create content, SEO, publish |
| [MEDIA-UPLOAD-WORKFLOW.md](workflow/MEDIA-UPLOAD-WORKFLOW.md) | Native upload pipeline, member root, bulk / rename / move |
| [UPDATE-DEPLOYMENT-WORKFLOW.md](workflow/UPDATE-DEPLOYMENT-WORKFLOW.md) | CMS update, SHA-256 verification, deployment |
| [MARKETPLACE-WORKFLOW.md](workflow/MARKETPLACE-WORKFLOW.md) | Install plugin / theme from marketplace |
| [API-INTEGRATION-WORKFLOW.md](workflow/API-INTEGRATION-WORKFLOW.md) | JSON endpoints, custom routes via `register_routes`, JWT note |
| [specs/community/](workflow/specs/community/README.md) | Community module (forum) – plan: features, architecture, data model, permissions & moderation, workflows, security & privacy, theme/UI, migration from cms-forum, roadmap, test plan / Community-Modul (Forum) – Planung |

---

## Roadmap 4.2.0 / 4.3.0 | Planung und Spezifikationen

**English**

4.1.0 is released with areas 1–5, including invoices ([workflow/specs/v4.1/PROGRESS.md](workflow/specs/v4.1/PROGRESS.md)). Area 6 (central CLI `CMS/cli.php`, real-time alerting, webhook event `alert.raised`) moved to **4.3.0**. **4.2.0** (schema `v27`) is the Community module; its implementation plan is in [workflow/specs/community/](workflow/specs/community/README.md).

**Deutsch**

4.1.0 ist mit den Bereichen 1–5 einschließlich Rechnungen veröffentlicht ([workflow/specs/v4.1/PROGRESS.md](workflow/specs/v4.1/PROGRESS.md)). Bereich 6 (zentrale CLI `CMS/cli.php`, Echtzeit-Alerting, Webhook-Ereignis `alert.raised`) ist auf **4.3.0** verschoben. **4.2.0** (Schema `v27`) ist das Community-Modul; der Umsetzungsfahrplan steht in [workflow/specs/community/](workflow/specs/community/README.md).

| Document / Dokument | Purpose / Zweck |
|---|---|
| [workflow/specs/v4.1/README.md](workflow/specs/v4.1/README.md) | 4.1.0 specification overview and status |
| [workflow/specs/v4.1/PROGRESS.md](workflow/specs/v4.1/PROGRESS.md) | 4.1.0 progress (areas 1–5 done, area 6 → 4.3.0) |
| [workflow/specs/v4.1/SCHEMA-v26.md](workflow/specs/v4.1/SCHEMA-v26.md) | Schema `v26` specification |
| [workflow/specs/v4.1/06-CLI-ALERTING.md](workflow/specs/v4.1/06-CLI-ALERTING.md) | CLI and real-time alerting (planned for 4.3.0) |
| [workflow/specs/community/IMPLEMENTATION-PLAN.md](workflow/specs/community/IMPLEMENTATION-PLAN.md) | **Community module 4.2.0 (schema `v27`): executable implementation plan** – work packages, tables, capabilities, privacy/moderation rules, CSP/security, tests |
| [workflow/specs/community/README.md](workflow/specs/community/README.md) | Community module 4.2.0 (schema `v27`): planning set entry point, decisions E1–E12 |
| [workflow/specs/community/ROADMAP.md](workflow/specs/community/ROADMAP.md) | 4.2.0 implementation roadmap |
| [DEVLIST.md](DEVLIST.md) | Architecture contracts and development list (incl. 4.2.0 plan) |

---

## Further documents | Weitere Dokumente

**English** / **Deutsch**

Complete list of all documents not linked above (area READMEs link their sub-pages as well). / Vollständige Liste aller Dokumente, die oben nicht einzeln verlinkt sind (die Bereichs-READMEs verlinken ihre Unterseiten zusätzlich).

**`admin/ai/`**

- [admin/ai/AI-SERVICES.md](admin/ai/AI-SERVICES.md) – Admin – KI-Dienste (AI Services)

**`admin/dashboard/`**

- [admin/dashboard/DASHBOARD.md](admin/dashboard/DASHBOARD.md) – Admin – Dashboard

**`admin/diagnose/`**

- [admin/diagnose/README.md](admin/diagnose/README.md) – Admin – Diagnose & Protokolle

**`admin/info/`**

- [admin/info/README.md](admin/info/README.md) – Admin – Info

**`admin/landing-page/`**

- [admin/landing-page/README.md](admin/landing-page/README.md) – Admin – Landingpage

**`admin/legal/`**

- [admin/legal/COOKIES.md](admin/legal/COOKIES.md) – Admin – Cookie-Manager & Einwilligung
- [admin/legal/DELETION-REQUESTS.md](admin/legal/DELETION-REQUESTS.md) – Admin – Löschanträge (Art. 17 DSGVO)
- [admin/legal/DSGVO.md](admin/legal/DSGVO.md) – Admin – DSGVO-Anfragen (Auskunft & Löschung)
- [admin/legal/LEGAL.md](admin/legal/LEGAL.md) – Admin – Rechtsseiten

**`admin/media/`**

- [admin/media/MEDIA.md](admin/media/MEDIA.md) – Admin – Medienverwaltung

**`admin/pages-posts/`**

- [admin/pages-posts/COMMENTS.md](admin/pages-posts/COMMENTS.md) – Admin – Kommentare
- [admin/pages-posts/HUBSITES.md](admin/pages-posts/HUBSITES.md) – Admin – Hub-Sites
- [admin/pages-posts/PAGES.md](admin/pages-posts/PAGES.md) – Admin – Seiten
- [admin/pages-posts/SETTINGS.md](admin/pages-posts/SETTINGS.md) – Admin – Inhalts-Einstellungen
- [admin/pages-posts/TABLES.md](admin/pages-posts/TABLES.md) – Admin – Tabellen (Site Tables)
- [admin/pages-posts/TOC.md](admin/pages-posts/TOC.md) – Admin – Inhaltsverzeichnis (TOC)

**`admin/performance/`**

- [admin/performance/README.md](admin/performance/README.md) – Admin – Performance

**`admin/plugins/`**

- [admin/plugins/MARKETPLACE.md](admin/plugins/MARKETPLACE.md) – Admin – Plugin-Marketplace
- [admin/plugins/README.md](admin/plugins/README.md) – Admin – Plugins
- [admin/plugins/UPDATES.md](admin/plugins/UPDATES.md) – Admin – Plugin-Updates

**`admin/security/`**

- [admin/security/ANTISPAM.md](admin/security/ANTISPAM.md) – Admin – Anti-Spam
- [admin/security/FIREWALL.md](admin/security/FIREWALL.md) – Admin – Firewall

**`admin/seo/`**

- [admin/seo/ANALYTICS.md](admin/seo/ANALYTICS.md) – Admin – Analysen & Tracking
- [admin/seo/README.md](admin/seo/README.md) – Admin – SEO
- [admin/seo/REDIRECTS.md](admin/seo/REDIRECTS.md) – Admin – Weiterleitungen & 404-Monitor

**`admin/subscription/`**

- [admin/subscription/ORDERS.md](admin/subscription/ORDERS.md) – Admin – Bestellungen & Zuweisung
- [admin/subscription/PACKAGES.md](admin/subscription/PACKAGES.md) – Admin – Abo-Pakete
- [admin/subscription/README.md](admin/subscription/README.md) – Admin – Aboverwaltung

**`admin/system-settings/`**

- [admin/system-settings/SYSTEM.md](admin/system-settings/SYSTEM.md) – Admin – Allgemeine Einstellungen

**`admin/themes-design/`**

- [admin/themes-design/CUSTOMIZER.md](admin/themes-design/CUSTOMIZER.md) – Admin – Theme-Customizer
- [admin/themes-design/DASHBOARD-WIDGETS.md](admin/themes-design/DASHBOARD-WIDGETS.md) – Admin – Dashboard-Widgets
- [admin/themes-design/EDITOR.md](admin/themes-design/EDITOR.md) – Admin – Theme-Explorer (Dateieditor)
- [admin/themes-design/FONTS.md](admin/themes-design/FONTS.md) – Admin – Schriftverwaltung
- [admin/themes-design/MARKETPLACE.md](admin/themes-design/MARKETPLACE.md) – Admin – Theme-Verwaltung & Theme-Marktplatz
- [admin/themes-design/MENUS.md](admin/themes-design/MENUS.md) – Admin – Theme-Menüs

**`admin/users-groups/`**

- [admin/users-groups/GROUPS.md](admin/users-groups/GROUPS.md) – Admin – Gruppen
- [admin/users-groups/USERS.md](admin/users-groups/USERS.md) – Admin – Benutzer

**`assets/`**

- [assets/carbon/README.md](assets/carbon/README.md) – Carbon
- [assets/cron/README.md](assets/cron/README.md) – Cron Library (poliander/cron 3.3.1)
- [assets/css/README.md](assets/css/README.md) – Interne CSS-Assets
- [assets/dompurify/README.md](assets/dompurify/README.md) – DOMPurify
- [assets/editorjs/README.md](assets/editorjs/README.md) – Editor.js
- [assets/htmlpurifier/README.md](assets/htmlpurifier/README.md) – HTMLPurifier
- [assets/images/README.md](assets/images/README.md) – Interne Bild-Assets
- [assets/js/README.md](assets/js/README.md) – Interne JavaScript-Assets
- [assets/ldaprecord/README.md](assets/ldaprecord/README.md) – LdapRecord
- [assets/mailer/README.md](assets/mailer/README.md) – Symfony Mailer
- [assets/melbahja-seo/README.md](assets/melbahja-seo/README.md) – melbahja-seo
- [assets/mime/README.md](assets/mime/README.md) – Symfony Mime
- [assets/photoswipe/README.md](assets/photoswipe/README.md) – PhotoSwipe
- [assets/php-jwt/README.md](assets/php-jwt/README.md) – firebase/php-jwt
- [assets/psr/README.md](assets/psr/README.md) – PSR-Interfaces
- [assets/suneditor/README.md](assets/suneditor/README.md) – SunEditor
- [assets/tabler/README.md](assets/tabler/README.md) – Tabler Core
- [assets/tntsearch/README.md](assets/tntsearch/README.md) – TNTSearch
- [assets/translation/README.md](assets/translation/README.md) – Symfony Translation
- [assets/twofactorauth/README.md](assets/twofactorauth/README.md) – RobThree TwoFactorAuth
- [assets/webauthn/README.md](assets/webauthn/README.md) – lbuchs/WebAuthn

**`audit/`**

- [audit/RELEASE-AUDIT-4.1.0-2026-11-15.md](audit/RELEASE-AUDIT-4.1.0-2026-11-15.md) – Release-Prüfung 4.1.0
- [audit/FOLGEAUDIT-2026-11-09.md](audit/FOLGEAUDIT-2026-11-09.md) – Folgeaudit 2026-11-09
- [audit/FOLGEAUDIT-2026-11-12.md](audit/FOLGEAUDIT-2026-11-12.md) – Folgeaudit 2026-11-12
- [audit/FUNKTIONEN-VERWEISE.md](audit/FUNKTIONEN-VERWEISE.md) – 365CMS – Gesamtaudit 2026-10-14 | Funktionen und Verweise
- [audit/GUI-BARRIEREFREIHEIT.md](audit/GUI-BARRIEREFREIHEIT.md) – 365CMS – Gesamtaudit 2026-10-14 | GUI und Barrierefreiheit
- [audit/PERFORMANCE-LAST.md](audit/PERFORMANCE-LAST.md) – 365CMS – Gesamtaudit 2026-10-14 | Geschwindigkeit und Massenanfragen
- [audit/PRUEFKATALOG.md](audit/PRUEFKATALOG.md) – 365CMS – Gesamtaudit 2026-10-14 | Prüfkatalog und Methodik
- [audit/SICHERHEIT.md](audit/SICHERHEIT.md) – 365CMS – Gesamtaudit 2026-10-14 | Sicherheit


**`workflow/specs/`**

- [workflow/specs/ADMIN-MENU-PROFILE.md](workflow/specs/ADMIN-MENU-PROFILE.md) – Workflow – Spec: Admin-Sidebar-Menü mit Profilen
- [workflow/specs/KLARSTELLUNGEN.md](workflow/specs/KLARSTELLUNGEN.md) – Workflow – Klarstellungen zu den Specs
- [workflow/specs/PLUGIN-PARITY.md](workflow/specs/PLUGIN-PARITY.md) – 365CMS – Paritäts-Checkliste der in den Core integrierten Plugins
- [workflow/specs/ROADMAP-CONTENT-PLATFORM.md](workflow/specs/ROADMAP-CONTENT-PLATFORM.md) – 365CMS – Spezifikation: Freigabe-Ablauf, eigene Inhaltstypen und Felder, beliebig viele Sprachen
- [workflow/specs/community/ARCHITECTURE.md](workflow/specs/community/ARCHITECTURE.md) – Community – Architektur
- [workflow/specs/community/DATA-MODEL.md](workflow/specs/community/DATA-MODEL.md) – Community – Datenmodell
- [workflow/specs/community/FEATURES.md](workflow/specs/community/FEATURES.md) – Community – Funktionskatalog
- [workflow/specs/community/MIGRATION-CMS-FORUM.md](workflow/specs/community/MIGRATION-CMS-FORUM.md) – Community – Übernahme aus cms-forum
- [workflow/specs/community/PERMISSIONS-MODERATION.md](workflow/specs/community/PERMISSIONS-MODERATION.md) – Community – Rechte & Moderation
- [workflow/specs/community/SECURITY-PRIVACY.md](workflow/specs/community/SECURITY-PRIVACY.md) – Community – Sicherheit & Datenschutz
- [workflow/specs/community/TEST-PLAN.md](workflow/specs/community/TEST-PLAN.md) – Community – Testplan
- [workflow/specs/community/THEME-UI.md](workflow/specs/community/THEME-UI.md) – Community – Theme, Oberfläche & SEO
- [workflow/specs/community/WORKFLOWS.md](workflow/specs/community/WORKFLOWS.md) – Community – Abläufe
- [workflow/specs/v4.1/01-IDENTITY.md](workflow/specs/v4.1/01-IDENTITY.md) – Spec 4.1.0 – Bereich 1: Identität, Anmeldung & Konto-Sicherheit
- [workflow/specs/v4.1/02-MEDIA.md](workflow/specs/v4.1/02-MEDIA.md) – Spec 4.1.0 – Bereich 2: Medien & Performance
- [workflow/specs/v4.1/03-CONTENT-WORKFLOWS.md](workflow/specs/v4.1/03-CONTENT-WORKFLOWS.md) – Spec 4.1.0 – Bereich 3: Redaktions- & Content-Workflows
- [workflow/specs/v4.1/04-BACKUP-WEBHOOKS.md](workflow/specs/v4.1/04-BACKUP-WEBHOOKS.md) – Spec 4.1.0 – Bereich 4: Offsite-Backups (S3) & ausgehende Webhooks
- [workflow/specs/v4.1/05-PAYMENTS.md](workflow/specs/v4.1/05-PAYMENTS.md) – Spec 4.1.0 – Bereich 5: Zahlungen, Abos & Rechnungen
- [workflow/specs/v4.1/REFERENCES.md](workflow/specs/v4.1/REFERENCES.md) – Spec 4.1.0 – Bewertung der Referenzen

---

## Task shortcuts | Direktlinks für häufige Aufgaben

**English**

Common tasks and the document that should be opened first.

**Deutsch**

Häufige Aufgaben und das Dokument, das zuerst geöffnet werden sollte.

| Task / Aufgabe | Document / Dokument |
|---|---|
| First install | [INSTALLATION.md](INSTALLATION.md) |
| Admin overview | [admin/README.md](admin/README.md) |
| Media management | [admin/media/README.md](admin/media/README.md) |
| Member area | [member/README.md](member/README.md) |
| Login / reset / registration branding | [admin/themes-design/CMS-LOGINPAGE.md](admin/themes-design/CMS-LOGINPAGE.md) |
| Secure uploads | [workflow/MEDIA-UPLOAD-WORKFLOW.md](workflow/MEDIA-UPLOAD-WORKFLOW.md) |
| Plugin development | [plugins/PLUGIN-DEVELOPMENT.md](plugins/PLUGIN-DEVELOPMENT.md) |
| Theme development | [theme/THEME-DEVELOPMENT.md](theme/THEME-DEVELOPMENT.md) |
| Hooks | [core/HOOKS-REFERENCE.md](core/HOOKS-REFERENCE.md) |
| Security (CSRF / XSS) | [core/SECURITY.md](core/SECURITY.md) |
| Asset status | [assets/README.md](assets/README.md) |
| New asset candidates | [assets/ASSETS_NEW.md](assets/ASSETS_NEW.md) |
| AI services concept | [ai/AI-SERVICES.md](ai/AI-SERVICES.md) |
| Replace vendor assets | [assets/ASSETS_OwnAssets.md](assets/ASSETS_OwnAssets.md) |
| Status / known gaps | [core/STATUS.md](core/STATUS.md) |
| System status | [core/STATUS.md](core/STATUS.md) |
| Mail / SMTP / Graph | [admin/system-settings/MAIL.md](admin/system-settings/MAIL.md) |
| Enable / disable modules | [admin/system-settings/MODULES.md](admin/system-settings/MODULES.md) |
| Plugin marketplace | [plugins/PLUGIN-MARKETPLACE.md](plugins/PLUGIN-MARKETPLACE.md) |
| Update / deployment | [workflow/UPDATE-DEPLOYMENT-WORKFLOW.md](workflow/UPDATE-DEPLOYMENT-WORKFLOW.md) |
