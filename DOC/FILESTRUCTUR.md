# 365CMS Projektdokumentation | FILESTRUCTUR
> **Stand:** 2026-11-15 | **Version:** 4.1.0 | **Status:** Stable | **Update:** 2026-11-15

## Table of contents | Inhaltsverzeichnis

- [Purpose](#purpose--zweck)
- [Repository root](#repository-root--repository-wurzel)
- [Documentation tree](#documentation-tree--dokumentationsbaum)
- [Related workspace repositories](#related-workspace-repositories--benachbarte-workspace-repositories)
- [How to read this file](#how-to-read-this-file--lesehilfe)

---

## Purpose | Zweck

**English**

This document describes the **repository layout** of `365CMS.DE-MAIN`. It is not the CMS runtime inventory.

| Document | Role |
|---|---|
| [FILESTRUCTUR.md](FILESTRUCTUR.md) | this file: repository and documentation layout |
| [CMSFILESTRUCTUR.md](CMSFILESTRUCTUR.md) | inventory of the `CMS/` runtime |
| [FILELIST.md](FILELIST.md) | developer-oriented structure map |
| [DEVLIST.md](DEVLIST.md) | technical reference |

**Deutsch**

Dieses Dokument beschreibt die **Repository-Struktur** von `365CMS.DE-MAIN`. Es ist kein Inventar der CMS-Runtime.

| Dokument | Rolle |
|---|---|
| [FILESTRUCTUR.md](FILESTRUCTUR.md) | diese Datei: Repository- und Dokumentationslayout |
| [CMSFILESTRUCTUR.md](CMSFILESTRUCTUR.md) | Inventar der Runtime `CMS/` |
| [FILELIST.md](FILELIST.md) | entwicklerfreundliche Strukturkarte |
| [DEVLIST.md](DEVLIST.md) | technische Referenz |

---

## Repository root | Repository-Wurzel

**English**

Verified top-level entries of this repository:

| Path | Type | Purpose |
|---|---|---|
| `CMS/` | directory | **productive runtime** (web root in typical deployments) |
| `CMS_ASSETS/` | directory | source archives of the bundled third-party libraries |
| `DOC/` | directory | public project documentation |
| `RELEASE/` | directory | release packages and checksums (`365CMS-<version>-full.zip`, `-update.zip`, `-update.json`, `-SHA256SUMS.txt`, `README-<version>.md`; currently 4.0.0, 4.1.0, 4.1.5, 4.1.6, 4.1.7, 4.1.8) |
| `SCREENSHOTS/` | directory | screenshots |
| `TESTS/admin-menu/` | directory | versioned admin menu test (catalog, static access check, profiles, validator, registry, builder, sidebar model, installer step): `php TESTS/admin-menu/run.php [-v]` |
| `TESTS/admin-redirects/` | directory | admin redirects page test (250 rules per page, no background refresh): `php TESTS/admin-redirects/run.php [-v]` |
| `TESTS/captcha/` | directory | core captcha service (proof-of-work, image, arithmetic): `php TESTS/captcha/run.php [-v]` |
| `TESTS/ai-admin-contract/` | directory | static contract of the AI admin integration (routes, CSRF, quota, SSRF guard, sanitizing): `php TESTS/ai-admin-contract/run.php` |
| `TESTS/ai-content-creator/` | directory | AI content draft pipeline with mock provider: `php TESTS/ai-content-creator/run.php` |
| `TESTS/ai-editorjs-translation/` | directory | Editor.js translation pipeline: batches, segment order, size limits: `php TESTS/ai-editorjs-translation/run.php` |
| `TESTS/ai-policy/` | directory | AI provider policy gates (external data sharing, beta providers, scopes): `php TESTS/ai-policy/run.php` |
| `TESTS/ai-seo-metadata/` | directory | AI SEO metadata pipeline (normalizing, schema type, robots): `php TESTS/ai-seo-metadata/run.php` |
| `TESTS/analytics-consent/` | directory | analytics output and CSP only with configured provider and consent: `php TESTS/analytics-consent/run.php` |
| `TESTS/content-expiry/` | directory | expiry and review dates: `php TESTS/content-expiry/run.php [-v]` |
| `TESTS/content-types/` | directory | content type provider registry test: `php TESTS/content-types/run.php [-v]`; all suites: `php TESTS/run-all.php` (shared runner `TESTS/lib/TestRunner.php`) |
| `TESTS/content-workflow/` | directory | four-eyes review: publish right, save enforcement, transitions, recipients, preview links, capability migration: `php TESTS/content-workflow/run.php [-v]` |
| `TESTS/csp-style-rewriter/` | directory | `InlineStyleRewriter`: `style` attributes to nonce CSS classes: `php TESTS/csp-style-rewriter/run.php` |
| `TESTS/editorjs-blocks/` | directory | Editor.js block tests: `php TESTS/editorjs-blocks/run.php`, browser part `node TESTS/editorjs-blocks/browser/run.js` |
| `TESTS/editorjs-csp/` | directory | Editor.js output without `style` attributes under the production CSP (4.1.0): `php TESTS/editorjs-csp/run.php [-v]`, browser part with enforced CSP `node TESTS/editorjs-csp/browser/run.js` |
| `TESTS/feeds-design/` | directory | feed design tokens, no reading time on overviews: `php TESTS/feeds-design/run.php [-v]` |
| `TESTS/functions/` | directory | function tests without database: `php TESTS/functions/run.php` |
| `TESTS/login-showcase/` | directory | login showcase and theme colour import: settings validation, getShowcase() shape, partial escaping, core login view, theme override: `php TESTS/login-showcase/run.php [-v]` |
| `TESTS/member-area/` | directory | member area: CSRF per request, PRG, favorites, notifications, sanitizer, dashboard layout, menu tree, i18n, accessibility markup: `php TESTS/member-area/run.php [-v]` |
| `TESTS/media-avif/` | directory | AVIF derivatives: detection, keep-only-if-smaller, pixel budget, `<picture>` markup, deletion, batch steps: `php TESTS/media-avif/run.php [-v]` |
| `TESTS/media-images/` | directory | image processing on upload: PNG transparency, EXIF orientation, `strip_exif` without re-encoding: `php TESTS/media-images/run.php [-v]` |
| `TESTS/media-usage/` | directory | media usage index: extraction, contexts, reindex, unused, rebuild: `php TESTS/media-usage/run.php [-v]` |
| `TESTS/mfa-enforcement/` | directory | Role-based MFA enforcement and account gate: `php TESTS/mfa-enforcement/run.php` |
| `TESTS/newsletter-member/` | directory | newsletter in the member area: widget states, consent, double opt-in, rate limit, PRG of plugin sections, escaping: `php TESTS/newsletter-member/run.php [-v]` |
| `TESTS/password-policy/` | directory | password policy (release 4.1.0): limits, Unicode character classes, history, expiry, filter, integration: `php TESTS/password-policy/run.php [-v]` |
| `TESTS/release-3.4.0/` | directory | install/update contract since 3.4.0 (schema version, update swap, packages and SHA-256 of the current release): `php TESTS/release-3.4.0/run.php` |
| `TESTS/release-4.1.0/` | directory | regressions of the 4.1.0 release audit without a database (FUN-410-xx and later findings): `php TESTS/release-4.1.0/run.php [-v]` |
| `TESTS/reusable-blocks/` | directory | EditorJS block templates: block data, usage index, rendering, recursion, history: `php TESTS/reusable-blocks/run.php [-v]` |
| `TESTS/s3-backup/` | directory | offsite backups: SigV4, S3 client, multipart, retention: `php TESTS/s3-backup/run.php [-v]` |
| `TESTS/invoices/` | directory | invoices: `php TESTS/invoices/run.php [-v]` |
| `TESTS/payments/` | directory | payments: `php TESTS/payments/run.php [-v]` |
| `TESTS/webhooks/` | directory | outgoing webhooks: `php TESTS/webhooks/run.php [-v]` |
| `TESTS/schema-v26/` | directory | schema v26 (release 4.1.0): new tables and columns identical for fresh install, update and runtime check: `php TESTS/schema-v26/run.php [-v]` |
| `TESTS/slugs/` | directory | slug generation: umlaut transliteration, accents, unchanged ASCII titles: `php TESTS/slugs/run.php [-v]` |
| `TESTS/schema-v27/` | directory | schema v27 (release 4.2.0, Community module): 28 tables, strict-mode rules, indexes, snapshot `golden.json` (regenerate with `dump-golden.php`), permission step `capabilities_v27`: `php TESTS/schema-v27/run.php [-v]` |
| `TESTS/community-settings/` | directory | settings of the Community module: definitions, limits, readers, effect, language keys and module entry: `php TESTS/community-settings/run.php [-v]` |
| `TESTS/community-repositories/` | directory | repositories of the Community module against the SQLite double (forums, topics, posts, read states, bans, statistics, keyset): `php TESTS/community-repositories/run.php [-v]` |
| `TESTS/community-access/` | directory | access decision `CommunityAccess::can()`: matrix of user class, action and state, trust levels, bans, windows, approval, filter: `php TESTS/community-access/run.php [-v]` |
| `TESTS/community-content/` | directory | topics, posts, quotes and read states: counters, idempotency, editing with history, soft delete, keyset, source guard for UTC and OFFSET: `php TESTS/community-content/run.php [-v]` |
| `TESTS/community-render/` | directory | Markdown rendering of the Community module: renderer core `ContentRenderer`, bundle `CMS/assets/commonmark/`, purifier profile `community`, counting, plain text, limits with pathological inputs, quote limit, host rule, output rule, display service `RenderService`: `php TESTS/community-render/run.php [-v]` |
| `TESTS/community-xss/` | directory | XSS corpus X1 to X40 against the Community renderer (full chain and CommonMark stage alone, DOM check): `php TESTS/community-xss/run.php [-v]` |
| `TESTS/community-spam/` | directory | spam and abuse protection: `IpHash`, word filter, hosts, comparison form `fold()`, spam duplicate, core antispam, filter `cms_community_spam_check`, request limits `ContentGuard::throttle()`, security log: `php TESTS/community-spam/run.php [-v]` |
| `TESTS/community-moderation/` | directory | moderation and counter booking: `ModerationService` (approve, reject, hide, restore, move with redirect topic, pin, solution, bulk action, queue, log, `systemAction`, `ipHashes`) and `CounterBook`, oracle `communityAudit()` after every step: `php TESTS/community-moderation/run.php [-v]` |
| `TESTS/community-privacy/` | directory | retention rules and DSGVO export/erasure of the community: `php TESTS/community-privacy/run.php [-v]` |
| `TESTS/community-captcha/` | directory | captcha in the community (modes, provider, write form): `php TESTS/community-captcha/run.php [-v]` |
| `TESTS/community-trash/` | directory | trash of the community (topics): `php TESTS/community-trash/run.php [-v]` |
| `TESTS/community-editor/` | directory | visual editor of the write form: source checks and browser checks (Chromium via Playwright, skipped without it): `php TESTS/community-editor/run.php [-v]` |
| `TESTS/community-frontend/` | directory | public read pages (PR #4): route patterns and order, page segments, page cursor, views without inline script: `php TESTS/community-frontend/run.php [-v]` |
| `TESTS/community-member/` | directory | member area (PR #4): tabs, registry registration, POST evaluation, "my topics/replies" lists: `php TESTS/community-member/run.php [-v]` |
| `TESTS/community-api/` | directory | JSON API (PR #4): actions and input checks, status mapping, CSRF filter, route registration, JS module: `php TESTS/community-api/run.php [-v]` |
| `TESTS/community-admin/` | directory | admin pages `/admin/community*` (entry files, capability gates, view without inline script, CSRF in every form, settings round trip): `php TESTS/community-admin/run.php [-v]` |
| `TESTS/community-sanctions/` | directory | reports and bans: `ReportService` (report, merge, limit, threshold, resolve, lists) and `BanService` (ban, lift with rank check, history), 404 rule and lock order: `php TESTS/community-sanctions/run.php [-v]` |
| `TESTS/community-notify/` | directory | notifications, subscriptions, reactions and ranks: `NotificationDispatcher` (queue `community_notify_queue`, tiers, rights per recipient, mail gate and daily quota, report feedback, ban and move notices), `SubscriptionService` (auto-subscribe, preferences, unsubscribe link), `ReactionService`, `RankService`, core methods `notifyGrouped()` and `enqueuePlainStrict()`: `php TESTS/community-notify/run.php [-v]` |
| `TESTS/community-uploads/` | directory | attachments: `AttachmentService` (extension, MIME and header check, file name, quotas under lock, decoding limits and slots, re-encoding without metadata, two-step storage, claim, resolve, cleanup) and `AttachmentRepository`: `php TESTS/community-uploads/run.php [-v]` |
| `TESTS/community-search-seo/` | directory | search, index and sitemap: `CommunitySearch` (TNT index with `doclist_term_hits`, limit per word, shared guest bucket, LIKE fallback for members, journal, rebuild, index name from the secret, `sitemapEntries()`), `SearchRepository`, `CommunityMediaExtractor`: `php TESTS/community-search-seo/run.php [-v]` |
| `TESTS/community-db/` | directory | Community services against a real MariaDB/MySQL (optional, `CMS_TEST_DB_HOST`/`CMS_TEST_DB_USER`): rollback, race, UTC with local time, EXPLAIN of the lists, areas of PR #2b in `sections-*.php`: `php TESTS/community-db/run.php [-v]` |
| `TESTS/community-mutants/` | directory | counter-check: every rule of the Community suites runs once against an intentionally wrong implementation and must fail: `php TESTS/community-mutants/run.php [-v]` |
| `TESTS/user-role-hook/` | directory | core hook `user_role_changed` on role changes (user administration, bulk action, rename/delete role): `php TESTS/user-role-hook/run.php [-v]` |
| `TESTS/schema-upgrade/` | directory | schema upgrade against a real MySQL/MariaDB (optional, `CMS_TEST_DB_HOST`/`CMS_TEST_DB_USER`): fresh install v27 and updates from 4.0.0 (v25), 4.1.8 (v26) and, with the Git tag, 3.5.x (v23) yield identical structure matching the snapshot `TESTS/schema-v27/golden.json`, plus the one-time permission step `capabilities_v27`, the collation of the Community tables and the repair path, keep data, are idempotent, one connection on first request: `php TESTS/schema-upgrade/run.php [-v]` |
| `TESTS/security/` | directory | security tests: `php TESTS/security/run.php` |
| `TESTS/sso-entra/` | directory | Entra ID sign-in via OpenID Connect: ID-token checks, state/nonce/PKCE, account linking incl. attacks, provisioning, role mapping: `php TESTS/sso-entra/run.php [-v]` |
| `TESTS/theme-contract/` | directory | theme contract (CSP, layout hooks, versions), optional further theme roots as arguments: `php TESTS/theme-contract/run.php [theme roots …]` |
| `TESTS/update-swap/` | directory | update directory swap test: `php TESTS/update-swap/run.php` |
| `.github/` | directory | `CODEOWNERS`, `SECURITY`, `dependabot.yml`, `workflows/security.yml`, `deps/` (dependency manifests for the scans) |
| `.oss-scanner/` | directory | `Dockerfile`, `threat_model.md` |
| `LICENSE` | file | licence |
| `README.md` | file | public project README |
| `Changelog.md` | file | project changelog |
| `CODE_OF_CONDUCT.md` | file | code of conduct |
| `.gitignore` | file | git ignore rules |

`ASSETS/` (asset staging), `AUDIT/` and `tools/` are referenced in older notes and in `VendorRegistry` (staging paths) but are **not** part of this repository (state 2026-11-13); they exist only in local maintainer workspaces. `TESTS/` is fully versioned: `run-all.php`, `lib/TestRunner.php` and every suite `TESTS/<suite>/run.php` (one row per suite in the table above), all run by `php TESTS/run-all.php` in CI.

There is **no** `var/` directory in this repository. Application logs are configured as `CMS/logs/` (`LOG_PATH` in `config/app.php`).

**Deutsch**

Verifizierte Top-Level-Einträge dieses Repositories:

| Pfad | Typ | Zweck |
|---|---|---|
| `CMS/` | Verzeichnis | **produktive Runtime** (in typischen Deployments das Webroot) |
| `CMS_ASSETS/` | Verzeichnis | Quellarchive der gebündelten Drittbibliotheken |
| `DOC/` | Verzeichnis | öffentliche Projektdokumentation |
| `RELEASE/` | Verzeichnis | Release-Pakete und Prüfsummen (`365CMS-<version>-full.zip`, `-update.zip`, `-update.json`, `-SHA256SUMS.txt`, `README-<version>.md`; aktuell 4.0.0, 4.1.0, 4.1.5, 4.1.6, 4.1.7, 4.1.8) |
| `SCREENSHOTS/` | Verzeichnis | Screenshots |
| `TESTS/admin-menu/` | Verzeichnis | versionierter Admin-Menü-Test (Katalog, statische Zugriffsprüfung, Profile, Validator, Registry, Builder, Sidebar-Modell, Installer-Schritt): `php TESTS/admin-menu/run.php [-v]` |
| `TESTS/admin-redirects/` | Verzeichnis | Test der Admin-Seite Weiterleitungen (250 Regeln je Seite, keine Aktualisierung im Hintergrund): `php TESTS/admin-redirects/run.php [-v]` |
| `TESTS/captcha/` | Verzeichnis | Captcha-Dienst des Core (Proof-of-Work, Bild, Rechenaufgabe): `php TESTS/captcha/run.php [-v]` |
| `TESTS/ai-admin-contract/` | Verzeichnis | statischer Vertrag der AI-Admin-Integration (Routen, CSRF, Quota, SSRF-Schutz, Bereinigung): `php TESTS/ai-admin-contract/run.php` |
| `TESTS/ai-content-creator/` | Verzeichnis | AI-Entwurfs-Pipeline mit Mock-Provider: `php TESTS/ai-content-creator/run.php` |
| `TESTS/ai-editorjs-translation/` | Verzeichnis | Editor.js-Übersetzungs-Pipeline: Batches, Segmentreihenfolge, Größenlimits: `php TESTS/ai-editorjs-translation/run.php` |
| `TESTS/ai-policy/` | Verzeichnis | Freigaben der AI-Provider-Richtlinie (externe Datenweitergabe, Beta-Provider, Scopes): `php TESTS/ai-policy/run.php` |
| `TESTS/ai-seo-metadata/` | Verzeichnis | AI-SEO-Metadaten-Pipeline (Normalisierung, Schema-Typ, Robots): `php TESTS/ai-seo-metadata/run.php` |
| `TESTS/analytics-consent/` | Verzeichnis | Analytics-Ausgabe und CSP nur mit konfiguriertem Anbieter und Einwilligung: `php TESTS/analytics-consent/run.php` |
| `TESTS/content-expiry/` | Verzeichnis | Ablauf- und Prüfdaten: `php TESTS/content-expiry/run.php [-v]` |
| `TESTS/content-types/` | Verzeichnis | Test der Inhaltstyp-Provider-Registry: `php TESTS/content-types/run.php [-v]`; alle Suiten: `php TESTS/run-all.php` (gemeinsamer Runner `TESTS/lib/TestRunner.php`) |
| `TESTS/content-workflow/` | Verzeichnis | Vier-Augen-Prinzip: Freigaberecht, Durchsetzung beim Speichern, Übergänge, Empfänger, Vorschau-Links, Rechte-Migration: `php TESTS/content-workflow/run.php [-v]` |
| `TESTS/csp-style-rewriter/` | Verzeichnis | `InlineStyleRewriter`: `style`-Attribute zu Nonce-CSS-Klassen: `php TESTS/csp-style-rewriter/run.php` |
| `TESTS/editorjs-blocks/` | Verzeichnis | Tests der Editor.js-Blöcke: `php TESTS/editorjs-blocks/run.php`, Browser-Teil `node TESTS/editorjs-blocks/browser/run.js` |
| `TESTS/editorjs-csp/` | Verzeichnis | Editor.js-Ausgabe ohne `style`-Attribute unter der Produktiv-CSP (4.1.0): `php TESTS/editorjs-csp/run.php [-v]`, Browser-Teil mit erzwungener CSP `node TESTS/editorjs-csp/browser/run.js` |
| `TESTS/feeds-design/` | Verzeichnis | Design-Variablen der Feed-Seiten, keine Lesezeit auf Übersichten: `php TESTS/feeds-design/run.php [-v]` |
| `TESTS/functions/` | Verzeichnis | Funktionstests ohne Datenbank: `php TESTS/functions/run.php` |
| `TESTS/login-showcase/` | Verzeichnis | Login-Showcase und Theme-Farbimport: Validierung der Einstellungen, Datenform von getShowcase(), Escaping des Partials, Core-Loginseite, Theme-Override: `php TESTS/login-showcase/run.php [-v]` |
| `TESTS/member-area/` | Verzeichnis | Mitgliederbereich: CSRF je Anfrage, PRG, Favoriten, Benachrichtigungen, Sanitizer, Dashboard-Layout, Menübaum, Sprache, Barrierefreiheit: `php TESTS/member-area/run.php [-v]` |
| `TESTS/media-avif/` | Verzeichnis | AVIF-Derivate: Erkennung, „nur wenn kleiner“, Pixel-Budget, `<picture>`-Markup, Löschen, Batch-Schritte: `php TESTS/media-avif/run.php [-v]` |
| `TESTS/media-images/` | Verzeichnis | Bildverarbeitung beim Upload: PNG-Transparenz, EXIF-Ausrichtung, `strip_exif` ohne Neukodierung: `php TESTS/media-images/run.php [-v]` |
| `TESTS/media-usage/` | Verzeichnis | Verwendungsnachweis für Medien: Extraktion, Kontexte, Neuindizieren, ungenutzt, Neuaufbau: `php TESTS/media-usage/run.php [-v]` |
| `TESTS/mfa-enforcement/` | Verzeichnis | Rollenbasierter MFA-Zwang und Konto-Weiche: `php TESTS/mfa-enforcement/run.php` |
| `TESTS/newsletter-member/` | Verzeichnis | Newsletter im Mitgliederbereich: Widget-Zustände, Einwilligung, Double-Opt-In, Rate-Limit, PRG der Plugin-Bereiche, Escaping: `php TESTS/newsletter-member/run.php [-v]` |
| `TESTS/password-policy/` | Verzeichnis | Passwort-Richtlinie (Release 4.1.0): Grenzen, Unicode-Zeichenklassen, Historie, Ablauf, Filter, Einbindung: `php TESTS/password-policy/run.php [-v]` |
| `TESTS/release-3.4.0/` | Verzeichnis | Install-/Update-Vertrag seit 3.4.0 (Schema-Version, Update-Tausch, Pakete und SHA-256 des aktuellen Releases): `php TESTS/release-3.4.0/run.php` |
| `TESTS/release-4.1.0/` | Verzeichnis | Regressionen der Release-Prüfung 4.1.0 ohne Datenbank (FUN-410-xx und folgende Befunde): `php TESTS/release-4.1.0/run.php [-v]` |
| `TESTS/reusable-blocks/` | Verzeichnis | EditorJS Block Vorlagen: Blockdaten, Nutzungsindex, Rendern, Rekursion, Verlauf: `php TESTS/reusable-blocks/run.php [-v]` |
| `TESTS/s3-backup/` | Verzeichnis | Offsite-Backups: SigV4, S3-Client, Multipart, Aufbewahrung: `php TESTS/s3-backup/run.php [-v]` |
| `TESTS/invoices/` | Verzeichnis | Rechnungen: `php TESTS/invoices/run.php [-v]` |
| `TESTS/payments/` | Verzeichnis | Zahlungen: `php TESTS/payments/run.php [-v]` |
| `TESTS/webhooks/` | Verzeichnis | ausgehende Webhooks: `php TESTS/webhooks/run.php [-v]` |
| `TESTS/schema-v26/` | Verzeichnis | Schema v26 (Release 4.1.0): neue Tabellen und Spalten identisch für Neuinstallation, Update und Laufzeitprüfung: `php TESTS/schema-v26/run.php [-v]` |
| `TESTS/slugs/` | Verzeichnis | Slug-Erzeugung: Umlaut-Umschrift, Akzente, unveränderte ASCII-Titel: `php TESTS/slugs/run.php [-v]` |
| `TESTS/schema-v27/` | Verzeichnis | Schema v27 (Release 4.2.0, Community-Modul): 28 Tabellen, Strict-Mode-Regeln, Indizes, Snapshot `golden.json` (neu erzeugen mit `dump-golden.php`), Rechte-Schritt `capabilities_v27`: `php TESTS/schema-v27/run.php [-v]` |
| `TESTS/community-settings/` | Verzeichnis | Einstellungen des Community-Moduls: Definitionen, Grenzen, Leser, Wirkung, Sprachschlüssel und Modul-Eintrag: `php TESTS/community-settings/run.php [-v]` |
| `TESTS/community-repositories/` | Verzeichnis | Repositories des Community-Moduls gegen das SQLite-Double (Foren, Themen, Beiträge, Lesestatus, Sperren, Statistik, Keyset): `php TESTS/community-repositories/run.php [-v]` |
| `TESTS/community-access/` | Verzeichnis | Zugriffsentscheidung `CommunityAccess::can()`: Matrix aus Nutzerklasse, Aktion und Zustand, Vertrauensstufen, Sperren, Fenster, Freigabe, Filter: `php TESTS/community-access/run.php [-v]` |
| `TESTS/community-content/` | Verzeichnis | Themen, Beiträge, Zitate und Lesestatus: Zähler, Idempotenz, Bearbeiten mit Verlauf, weiches Löschen, Keyset, Quelltext-Wächter für UTC und OFFSET: `php TESTS/community-content/run.php [-v]` |
| `TESTS/community-render/` | Verzeichnis | Markdown-Darstellung des Community-Moduls: Renderer-Kern `ContentRenderer`, Bündel `CMS/assets/commonmark/`, Purifier-Profil `community`, Zählung, Klartext, Grenzen mit pathologischen Eingaben, Zitatgrenze, Host-Regel, Ausgaberegel, Darstellungsdienst `RenderService`: `php TESTS/community-render/run.php [-v]` |
| `TESTS/community-xss/` | Verzeichnis | XSS-Korpus X1 bis X40 gegen den Community-Renderer (ganze Kette und CommonMark-Stufe allein, DOM-Prüfung): `php TESTS/community-xss/run.php [-v]` |
| `TESTS/community-spam/` | Verzeichnis | Spam- und Missbrauchsschutz: `IpHash`, Wortfilter, Hosts, Vergleichsfassung `fold()`, Spam-Dublette, Core-Antispam, Filter `cms_community_spam_check`, Request-Grenzen `ContentGuard::throttle()`, Sicherheitsprotokoll: `php TESTS/community-spam/run.php [-v]` |
| `TESTS/community-moderation/` | Verzeichnis | Moderation und Zählerbuchung: `ModerationService` (Freigeben, Ablehnen, Ausblenden, Wiederherstellen, Verschieben mit Verweis-Thema, Anheften, Lösung, Sammelaktion, Warteschlange, Protokoll, `systemAction`, `ipHashes`) und `CounterBook`, Orakel `communityAudit()` nach jedem Schritt: `php TESTS/community-moderation/run.php [-v]` |
| `TESTS/community-privacy/` | Verzeichnis | Löschfristen und DSGVO-Auskunft/-Löschung der Community: `php TESTS/community-privacy/run.php [-v]` |
| `TESTS/community-captcha/` | Verzeichnis | Captcha in der Community (Modus, Verfahren, Schreibformular): `php TESTS/community-captcha/run.php [-v]` |
| `TESTS/community-trash/` | Verzeichnis | Papierkorb der Community (Themen): `php TESTS/community-trash/run.php [-v]` |
| `TESTS/community-editor/` | Verzeichnis | Visueller Editor des Schreibformulars: Quelltextprüfung und Browser-Prüfung (Chromium über Playwright, ohne Playwright übersprungen): `php TESTS/community-editor/run.php [-v]` |
| `TESTS/community-frontend/` | Verzeichnis | Öffentliche Leseseiten (PR #4): Routenmuster und -reihenfolge, Seitensegmente, Seitencursor, Ansichten ohne Inline-Skript: `php TESTS/community-frontend/run.php [-v]` |
| `TESTS/community-member/` | Verzeichnis | Mitgliederbereich (PR #4): Reiter, Anmeldung bei der Registry, POST-Auswertung, Listen „Meine Themen/Antworten“: `php TESTS/community-member/run.php [-v]` |
| `TESTS/community-api/` | Verzeichnis | JSON-API (PR #4): Aktionen und Eingabeprüfung, Statusabbildung, CSRF-Filter, Routenanmeldung, JS-Modul: `php TESTS/community-api/run.php [-v]` |
| `TESTS/community-admin/` | Verzeichnis | Admin-Seiten `/admin/community*` (Einstiegsdateien, Capability-Gates, Ansicht ohne Inline-Skript, CSRF in jedem Formular, Einstellungen im Rundlauf): `php TESTS/community-admin/run.php [-v]` |
| `TESTS/community-sanctions/` | Verzeichnis | Meldungen und Sperren: `ReportService` (Melden, Zusammenführen, Grenze, Schwelle, Erledigen, Listen) und `BanService` (Sperren, Aufheben mit Rangprüfung, Verlauf), 404-Regel und Sperrfolge: `php TESTS/community-sanctions/run.php [-v]` |
| `TESTS/community-notify/` | Verzeichnis | Benachrichtigungen, Abos, Reaktionen und Ränge: `NotificationDispatcher` (Warteschlange `community_notify_queue`, Stufen, Rechte je Empfänger, Mail-Tor und Tageskontingent, Rückmeldung an Melder, Sperr- und Verschiebehinweis), `SubscriptionService` (Auto-Abo, Präferenzen, Abmeldelink), `ReactionService`, `RankService`, Core-Methoden `notifyGrouped()` und `enqueuePlainStrict()`: `php TESTS/community-notify/run.php [-v]` |
| `TESTS/community-uploads/` | Verzeichnis | Anhänge: `AttachmentService` (Endung, MIME- und Kopfprüfung, Dateiname, Kontingente unter Sperre, Dekodiergrenzen und Slots, Neukodierung ohne Metadaten, zweistufige Ablage, Zuordnen, Auflösen, Aufräumen) und `AttachmentRepository`: `php TESTS/community-uploads/run.php [-v]` |
| `TESTS/community-search-seo/` | Verzeichnis | Suche, Index und Sitemap: `CommunitySearch` (TNT-Index mit `doclist_term_hits`, Grenze je Wort, gemeinsamer Eimer der Gäste, LIKE-Rückfall für Mitglieder, Journal, Neuaufbau, Indexname aus dem Geheimnis, `sitemapEntries()`), `SearchRepository`, `CommunityMediaExtractor`: `php TESTS/community-search-seo/run.php [-v]` |
| `TESTS/community-db/` | Verzeichnis | Community-Dienste gegen eine echte MariaDB/MySQL (optional, `CMS_TEST_DB_HOST`/`CMS_TEST_DB_USER`): Rollback, Wettlauf, UTC bei Ortszeit, EXPLAIN der Listen, Bereiche aus PR #2b in `sections-*.php`: `php TESTS/community-db/run.php [-v]` |
| `TESTS/community-mutants/` | Verzeichnis | Gegenprobe: jede Regel der Community-Suiten läuft einmal gegen eine absichtlich falsche Umsetzung und muss scheitern: `php TESTS/community-mutants/run.php [-v]` |
| `TESTS/user-role-hook/` | Verzeichnis | Core-Hook `user_role_changed` bei Rollenwechsel (Benutzerverwaltung, Sammelaktion, Rolle umbenennen/löschen): `php TESTS/user-role-hook/run.php [-v]` |
| `TESTS/schema-upgrade/` | Verzeichnis | Upgrade-Pfad gegen echte MySQL/MariaDB (optional, `CMS_TEST_DB_HOST`/`CMS_TEST_DB_USER`): Neuinstallation v27 und Updates von 4.0.0 (v25), 4.1.8 (v26) und, mit dem Git-Tag, 3.5.x (v23) ergeben dieselbe Struktur gemäß Snapshot `TESTS/schema-v27/golden.json`, dazu der einmalige Rechte-Schritt `capabilities_v27`, die Kollation der Community-Tabellen und der Reparaturpfad, erhalten Daten, laufen idempotent, eine Verbindung beim ersten Request: `php TESTS/schema-upgrade/run.php [-v]` |
| `TESTS/security/` | Verzeichnis | Sicherheitstests: `php TESTS/security/run.php` |
| `TESTS/sso-entra/` | Verzeichnis | Entra-ID-Anmeldung über OpenID Connect: ID-Token-Prüfungen, state/nonce/PKCE, Kontozuordnung inkl. Angriffe, Provisioning, Rollen-Mapping: `php TESTS/sso-entra/run.php [-v]` |
| `TESTS/theme-contract/` | Verzeichnis | Theme-Vertrag (CSP, Layout-Hooks, Versionen), optional weitere Theme-Wurzeln als Argumente: `php TESTS/theme-contract/run.php [Theme-Wurzeln …]` |
| `TESTS/update-swap/` | Verzeichnis | Test des Verzeichnistauschs beim Update: `php TESTS/update-swap/run.php` |
| `.github/` | Verzeichnis | `CODEOWNERS`, `SECURITY`, `dependabot.yml`, `workflows/security.yml`, `deps/` (Abhängigkeitsmanifeste für die Scans) |
| `.oss-scanner/` | Verzeichnis | `Dockerfile`, `threat_model.md` |
| `LICENSE` | Datei | Lizenz |
| `README.md` | Datei | öffentliches Projekt-README |
| `Changelog.md` | Datei | Projekt-Changelog |
| `CODE_OF_CONDUCT.md` | Datei | Verhaltenskodex |
| `.gitignore` | Datei | Git-Ignore-Regeln |

`ASSETS/` (Asset-Staging), `AUDIT/` und `tools/` werden in älteren Notizen und in `VendorRegistry` (Staging-Pfade) erwähnt, sind aber **nicht** Teil dieses Repositories (Stand 2026-11-13); sie existieren nur in lokalen Maintainer-Arbeitsständen. `TESTS/` ist vollständig versioniert: `run-all.php`, `lib/TestRunner.php` und jede Suite `TESTS/<suite>/run.php` (je Suite eine Zeile in der Tabelle oben), alle führt `php TESTS/run-all.php` in der CI aus.

Es gibt **kein** Verzeichnis `var/` in diesem Repository. Anwendungslogs sind als `CMS/logs/` konfiguriert (`LOG_PATH` in `config/app.php`).

---

## Documentation tree | Dokumentationsbaum

**English**

Canonical files directly under `DOC/`:

| File | Purpose |
|---|---|
| [README.md](README.md) | documentation overview |
| [INDEX.md](INDEX.md) | documentation index |
| [INSTALLATION.md](INSTALLATION.md) | installation |
| [DEVLIST.md](DEVLIST.md) | developer reference |
| [FILELIST.md](FILELIST.md) | runtime structure map |
| [FILESTRUCTUR.md](FILESTRUCTUR.md) | repository layout (this file) |
| [CMSFILESTRUCTUR.md](CMSFILESTRUCTUR.md) | CMS runtime inventory |

Subfolders (all present):

| Folder | Purpose |
|---|---|
| `DOC/admin/` | admin documentation |
| `DOC/ai/` | AI concept docs |
| `DOC/assets/` | asset / vendor docs (`ASSET.md`, `ASSETS_NEW.md`, `ASSETS_OwnAssets.md` live **here**) |
| `DOC/audit/` | follow-up audits |
| `DOC/core/` | core documentation |
| `DOC/member/` | member documentation |
| `DOC/plugins/` | plugin documentation |
| `DOC/theme/` | theme documentation |
| `DOC/workflow/` | operational workflows |

Broken historical links (do **not** use):

- `DOC/ASSET.md`, actual path is `DOC/assets/ASSET.md`
- `DOC/ASSETS_NEW.md`, actual path is `DOC/assets/ASSETS_NEW.md`
- `DOC/ASSETS_OwnAssets.md`, actual path is `DOC/assets/ASSETS_OwnAssets.md`
- `AUDIT/`, not present in this repository
- `DOC/_cms_inventory_current.txt`, file does not exist

**Deutsch**

Kanonische Dateien direkt unter `DOC/`:

| Datei | Zweck |
|---|---|
| [README.md](README.md) | Dokumentationsüberblick |
| [INDEX.md](INDEX.md) | Dokumentationsindex |
| [INSTALLATION.md](INSTALLATION.md) | Installation |
| [DEVLIST.md](DEVLIST.md) | Entwicklerreferenz |
| [FILELIST.md](FILELIST.md) | Runtime-Strukturkarte |
| [FILESTRUCTUR.md](FILESTRUCTUR.md) | Repository-Layout (diese Datei) |
| [CMSFILESTRUCTUR.md](CMSFILESTRUCTUR.md) | CMS-Runtime-Inventar |

Unterordner (alle vorhanden):

| Ordner | Zweck |
|---|---|
| `DOC/admin/` | Admin-Dokumentation |
| `DOC/ai/` | AI-Konzeptdokumente |
| `DOC/assets/` | Asset-/Vendor-Dokumente (`ASSET.md`, `ASSETS_NEW.md`, `ASSETS_OwnAssets.md` liegen **hier**) |
| `DOC/audit/` | Folgeaudits |
| `DOC/core/` | Core-Dokumentation |
| `DOC/member/` | Member-Dokumentation |
| `DOC/plugins/` | Plugin-Dokumentation |
| `DOC/theme/` | Theme-Dokumentation |
| `DOC/workflow/` | operative Workflows |

Historisch kaputte Links (nicht verwenden):

- `DOC/ASSET.md`, tatsächlicher Pfad: `DOC/assets/ASSET.md`
- `DOC/ASSETS_NEW.md`, tatsächlicher Pfad: `DOC/assets/ASSETS_NEW.md`
- `DOC/ASSETS_OwnAssets.md`, tatsächlicher Pfad: `DOC/assets/ASSETS_OwnAssets.md`
- `AUDIT/`, in diesem Repository nicht vorhanden
- `DOC/_cms_inventory_current.txt`. Datei existiert nicht

---

## Related workspace repositories | Benachbarte Workspace-Repositories

**English**

On this machine, sibling folders next to `365CMS.DE-MAIN` include:

- `365CMS.DE-THEME`, theme source repository
- `365CMS.DE-PLUGINS`, plugin source repository

The running CMS loads themes and plugins **only** from:

- `365CMS.DE-MAIN/CMS/themes/`
- `365CMS.DE-MAIN/CMS/plugins/`

A change in a sibling repo is not live until it is present under those runtime paths.

**Deutsch**

Auf dieser Maschine liegen neben `365CMS.DE-MAIN` unter anderem:

- `365CMS.DE-THEME`. Theme-Quellrepository
- `365CMS.DE-PLUGINS`. Plugin-Quellrepository

Das laufende CMS lädt Themes und Plugins **nur** aus:

- `365CMS.DE-MAIN/CMS/themes/`
- `365CMS.DE-MAIN/CMS/plugins/`

Eine Änderung in einem Nachbar-Repo ist nicht live, solange sie nicht unter diesen Runtime-Pfaden liegt.

---

## How to read this file | Lesehilfe

**English**

- Working on **runtime behaviour** → start in `CMS/` and [FILELIST.md](FILELIST.md)
- Working on **repository orientation** → this file
- Working on **exact CMS file inventory** → [CMSFILESTRUCTUR.md](CMSFILESTRUCTUR.md)
- Working on **architecture and contracts** → [DEVLIST.md](DEVLIST.md)

**Deutsch**

- Arbeit an **Runtime-Verhalten** → in `CMS/` und [FILELIST.md](FILELIST.md) beginnen
- Arbeit an **Repository-Orientierung** → diese Datei
- Arbeit am **CMS-Dateiinventar** → [CMSFILESTRUCTUR.md](CMSFILESTRUCTUR.md)
- Arbeit an **Architektur und Verträgen** → [DEVLIST.md](DEVLIST.md)
