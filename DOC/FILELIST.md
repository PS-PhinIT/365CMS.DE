# 365CMS Projektdokumentation | FILELIST
> **Stand:** 2026-11-15 | **Version:** 4.1.0 | **Status:** Stable | **Update:** 2026-11-15

## Table of contents | Inhaltsverzeichnis

- [1. Repository picture](#1-repository-picture--gesamtbild-des-repositories)
- [2. Runtime under CMS/](#2-runtime-under-cms--produktive-kernstruktur-unter-cms)
- [3. Inventory vs this map](#3-inventory-vs-this-map--inventar-und-strukturkarte)
- [4. config](#4-cmsconfig--konfiguration)
- [5. core](#5-cmscore--technischer-kern)
- [6. admin](#6-cmsadmin--backend-struktur)
- [7. assets](#7-cmsassets--produktive-frontend-admin-assets)
- [8. member](#8-cmsmember--benutzerbereich)
- [9. plugins](#9-cmsplugins--produktiv-geladene-plugins)
- [10. themes](#10-cmsthemes--produktiv-geladene-themes)
- [11. Other CMS areas](#11-other-cms-areas--weitere-runtime-bereiche)
- [12. DOC](#12-doc--dokumentationsbaum)
- [13. Sibling repositories](#13-sibling-repositories--benachbarte-repositories)
- [14. Task orientation](#14-task-orientation--schnelle-orientierung-nach-aufgabenart)
- [15. Summary](#15-summary--kurzfazit)

---

## 1. Repository picture | Gesamtbild des Repositories

**English**

This file is the **readable structure map**. It answers: where does something live, what does the running CMS load, and which paths matter when you change code.

Product version in code: `4.1.0`. PHP: 8.4.1+. Schema: `v26`.

**Deutsch**

Diese Datei ist die **lesbare Strukturkarte**. Sie beantwortet: wo liegt etwas, was lädt das laufende CMS, und welche Pfade zählen bei Änderungen.

Produktversion im Code: `4.1.0`. PHP: 8.4.1+. Schema: `v26`.

### 1.1 Workspace context | Workspace-Kontext

**English**

Relevant repositories in the workspace:

- `365CMS.DE-MAIN`, this repository, **productive runtime base**
- `365CMS.DE-THEME`, separate theme source repository
- `365CMS.DE-PLUGINS`, separate plugin source repository

The CMS loads themes and plugins only from `CMS/themes/` and `CMS/plugins/`.

**Deutsch**

Relevante Repositories im Workspace:

- `365CMS.DE-MAIN`, dieses Repository, **produktive Laufzeitbasis**
- `365CMS.DE-THEME`, separates Theme-Quellrepository
- `365CMS.DE-PLUGINS`, separates Plugin-Quellrepository

Das CMS lädt Themes und Plugins nur aus `CMS/themes/` und `CMS/plugins/`.

### 1.2 Root of this repository | Wurzel dieses Repositories

| Path | Purpose / Zweck |
|---|---|
| `CMS/` | productive application runtime |
| `DOC/` | documentation |
| `RELEASE/` | release zips and checksums |
| `SCREENSHOTS/` | screenshots |
| `TESTS/mfa-enforcement/` | Role-based MFA enforcement and account gate / Rollenbasierter MFA-Zwang und Konto-Weiche (`php TESTS/mfa-enforcement/run.php`) |
| `TESTS/password-policy/` | password policy (release 4.1.0): limits, Unicode character classes, history, expiry, filter, integration / Passwort-Richtlinie (Release 4.1.0): Grenzen, Unicode-Zeichenklassen, Historie, Ablauf, Filter, Einbindung (`php TESTS/password-policy/run.php [-v]`) |
| `TESTS/media-avif/` | AVIF derivatives: detection, keep-only-if-smaller, pixel budget, `<picture>` markup, deletion, batch steps / AVIF-Derivate: Erkennung, „nur wenn kleiner“, Pixel-Budget, `<picture>`-Markup, Löschen, Batch-Schritte (`php TESTS/media-avif/run.php [-v]`) |
| `TESTS/media-images/` | image processing on upload: PNG transparency, all eight EXIF orientations in re-encoded originals and derivatives, `strip_exif` without re-encoding (JPEG, PNG, WebP), GD-generated test images / Bildverarbeitung beim Upload: PNG-Transparenz, alle acht EXIF-Ausrichtungen in neu kodierten Originalen und Derivaten, `strip_exif` ohne Neukodierung (JPEG, PNG, WebP), Testbilder aus GD (`php TESTS/media-images/run.php [-v]`) |
| `TESTS/sso-entra/` | Entra ID sign-in via OpenID Connect: ID-token checks, state/nonce/PKCE, account linking incl. attacks, provisioning, role mapping / Entra-ID-Anmeldung über OpenID Connect: ID-Token-Prüfungen, state/nonce/PKCE, Kontozuordnung inkl. Angriffe, Provisioning, Rollen-Mapping (`php TESTS/sso-entra/run.php [-v]`) |
| `TESTS/run-all.php` | runs every suite `TESTS/*/run.php` / führt alle Suiten aus (CI) |
| `TESTS/lib/TestRunner.php` | shared minimal test runner / gemeinsamer Test-Runner (`T::run()`, `T::check()`, `T::same()`) |
| `TESTS/admin-menu/` | admin menu test / Admin-Menü-Test (`php TESTS/admin-menu/run.php [-v]`, helper `installer-preview.php`) |
| `TESTS/ai-admin-contract/` | static contract of the AI admin integration (routes, CSRF, quota, SSRF guard, sanitizing) / statischer Vertrag der AI-Admin-Integration (Routen, CSRF, Quota, SSRF-Schutz, Bereinigung) (`php TESTS/ai-admin-contract/run.php`) |
| `TESTS/ai-content-creator/` | AI content draft pipeline with mock provider / AI-Entwurfs-Pipeline mit Mock-Provider (`php TESTS/ai-content-creator/run.php`) |
| `TESTS/ai-editorjs-translation/` | Editor.js translation pipeline: batches, segment order, size limits / Editor.js-Übersetzungs-Pipeline: Batches, Segmentreihenfolge, Größenlimits (`php TESTS/ai-editorjs-translation/run.php`) |
| `TESTS/ai-policy/` | AI provider policy gates (external data sharing, beta providers, scopes) / Freigaben der AI-Provider-Richtlinie (externe Datenweitergabe, Beta-Provider, Scopes) (`php TESTS/ai-policy/run.php`) |
| `TESTS/ai-seo-metadata/` | AI SEO metadata pipeline (normalizing, schema type, robots) / AI-SEO-Metadaten-Pipeline (Normalisierung, Schema-Typ, Robots) (`php TESTS/ai-seo-metadata/run.php`) |
| `TESTS/analytics-consent/` | analytics output and CSP only with configured provider and consent / Analytics-Ausgabe und CSP nur mit konfiguriertem Anbieter und Einwilligung (`php TESTS/analytics-consent/run.php`) |
| `TESTS/content-expiry/` | expiry and review dates: inputs, expiry (draft/mark, batch, idempotent), review reminders / Ablauf- und Prüfdaten: Eingaben, Ablauf (Entwurf/Markieren, Batch, idempotent), Wiedervorlage (`php TESTS/content-expiry/run.php [-v]`) |
| `TESTS/content-types/` | content type provider registry test / Test der Inhaltstyp-Provider (`php TESTS/content-types/run.php [-v]`) |
| `TESTS/content-workflow/` | four-eyes review: publish right incl. second person, save enforcement, transitions, recipients, history, preview links, capability migration / Vier-Augen-Prinzip: Freigaberecht inkl. zweiter Person, Durchsetzung beim Speichern, Übergänge, Empfänger, Verlauf, Vorschau-Links, Rechte-Migration (`php TESTS/content-workflow/run.php [-v]`) |
| `TESTS/csp-style-rewriter/` | `InlineStyleRewriter`: `style` attributes to nonce CSS classes / `InlineStyleRewriter`: `style`-Attribute zu Nonce-CSS-Klassen (`php TESTS/csp-style-rewriter/run.php`) |
| `TESTS/editorjs-blocks/` | Editor.js block tests / Tests der Editor.js-Blöcke (`php TESTS/editorjs-blocks/run.php`, browser part / Browser-Teil `node TESTS/editorjs-blocks/browser/run.js`) |
| `TESTS/editorjs-csp/` | Editor.js output without `style` attributes under the production CSP (4.1.0) / Editor.js-Ausgabe ohne `style`-Attribute unter der Produktiv-CSP (4.1.0) (`php TESTS/editorjs-csp/run.php [-v]`, browser part with enforced CSP / Browser-Teil mit erzwungener CSP `node TESTS/editorjs-csp/browser/run.js`) |
| `TESTS/feeds-design/` | feed page design tokens, no reading time on overview pages / Design-Variablen der Feed-Seiten, keine Lesezeit auf Übersichten (`php TESTS/feeds-design/run.php [-v]`) |
| `TESTS/functions/` | function tests without database / Funktionstests ohne Datenbank (`php TESTS/functions/run.php`) |
| `TESTS/login-showcase/` | login showcase and theme colour import: settings validation, getShowcase() shape, partial escaping, core login view, theme override / Login-Showcase und Theme-Farbimport: Validierung der Einstellungen, Datenform von getShowcase(), Escaping des Partials, Core-Loginseite, Theme-Override (`php TESTS/login-showcase/run.php [-v]`) |
| `TESTS/media-usage/` | media usage index: extraction (URLs, srcset, derivatives, foreign domains), contexts, reindex, unused, rebuild / Verwendungsnachweis für Medien: Extraktion (URLs, srcset, Derivate, fremde Domains), Kontexte, Neuindizieren, ungenutzt, Neuaufbau (`php TESTS/media-usage/run.php [-v]`) |
| `TESTS/member-area/` | member area: CSRF per request, PRG, favorites, notifications, sanitizer, dashboard layout, menu tree, i18n, accessibility markup / Mitgliederbereich: CSRF je Anfrage, PRG, Favoriten, Benachrichtigungen, Sanitizer, Dashboard-Layout, Menübaum, Sprache, Barrierefreiheit (`php TESTS/member-area/run.php [-v]`) |
| `TESTS/newsletter-member/` | newsletter in the member area: widget states, consent, double opt-in, rate limit, PRG of plugin sections, escaping / Newsletter im Mitgliederbereich: Widget-Zustände, Einwilligung, Double-Opt-In, Rate-Limit, PRG der Plugin-Bereiche, Escaping (`php TESTS/newsletter-member/run.php [-v]`) |
| `TESTS/release-3.4.0/` | install/update contract since 3.4.0 (schema version, update swap, packages and SHA-256 of the current release) / Install-/Update-Vertrag seit 3.4.0 (Schema-Version, Update-Tausch, Pakete und SHA-256 des aktuellen Releases) (`php TESTS/release-3.4.0/run.php`) |
| `TESTS/release-4.1.0/` | regressions of the 4.1.0 release audit without a database (FUN-410-xx and later findings) / Regressionen der Release-Prüfung 4.1.0 ohne Datenbank (FUN-410-xx und folgende Befunde) (`php TESTS/release-4.1.0/run.php [-v]`) |
| `TESTS/reusable-blocks/` | EditorJS block templates: block data, usage index, rendering and language, recursion, history, delete warning, search text / EditorJS Block Vorlagen: Blockdaten, Nutzungsindex, Rendern und Sprache, Rekursion, Verlauf, Löschwarnung, Suchtext (`php TESTS/reusable-blocks/run.php [-v]`) |
| `TESTS/s3-backup/` | offsite backups: SigV4 vectors, S3 client with fake storage, multipart, retention, encryption gate / Offsite-Backups: SigV4-Testvektoren, S3-Client mit Fake-Speicher, Multipart, Aufbewahrung, Verschlüsselungspflicht (`php TESTS/s3-backup/run.php [-v]`) |
| `TESTS/invoices/` | invoices: numbering, cancellation, mandatory fields, download rights / Rechnungen: Nummernkreis lückenlos (auch parallel), Storno, Pflichtangaben, Download-Rechte, DSGVO-Sperrvermerk (`php TESTS/invoices/run.php [-v]`) |
| `TESTS/payments/` | payments: Stripe signature, Stripe/Mollie events, checkout amounts, idempotency, renewals / Zahlungen: Stripe-Signatur, Ereignisse, Beträge, Idempotenz, Verlängerung (`php TESTS/payments/run.php [-v]`) |
| `TESTS/webhooks/` | outgoing webhooks: signature, queue, retries, auto-disable, SSRF targets, retention / ausgehende Webhooks: Signatur, Warteschlange, Wiederholungen, Deaktivieren, Ziele, Löschfrist (`php TESTS/webhooks/run.php [-v]`) |
| `TESTS/schema-v26/` | schema v26 (release 4.1.0): new tables and columns identical for fresh install, update and runtime check / Schema v26 (Release 4.1.0): neue Tabellen und Spalten identisch für Neuinstallation, Update und Laufzeitprüfung (`php TESTS/schema-v26/run.php [-v]`) |
| `TESTS/slugs/` | slug generation: umlaut transliteration, accents, unchanged ASCII titles, truncation, use in posts, categories, tags and pages / Slug-Erzeugung: Umlaut-Umschrift, Akzente, unveränderte ASCII-Titel, Kürzung, Einbindung in Beiträge, Kategorien, Tags und Seiten (`php TESTS/slugs/run.php [-v]`) |
| `TESTS/schema-v27/` | schema v27 (release 4.2.0, Community module): the 28 tables `community_*`, strict-mode rules, indexes, comparison of definition, snapshot `golden.json` and HANDOFF tables (`lib.php`; regenerate the snapshot with `dump-golden.php`) and the one-time permission step `capabilities_v27` / Schema v27 (Release 4.2.0, Community-Modul): 28 Tabellen, Strict-Mode-Regeln, Indizes, Abgleich von Definition, Snapshot `golden.json` und HANDOFF-Tabellen (`lib.php`; Snapshot neu erzeugen mit `dump-golden.php`) und der einmalige Rechte-Schritt `capabilities_v27` (`php TESTS/schema-v27/run.php [-v]`) |
| `TESTS/community-settings/` | settings of the Community module: definitions, limits, readers, effect, language keys and module entry / Einstellungen des Community-Moduls: Definitionen, Grenzen, Leser, Wirkung, Sprachschlüssel und Modul-Eintrag (`php TESTS/community-settings/run.php [-v]`) |
| `TESTS/community-repositories/` | repositories of the Community module against the SQLite double (forums, topics, posts, read states, bans, statistics, keyset) / Repositories des Community-Moduls gegen das SQLite-Double (Foren, Themen, Beiträge, Lesestatus, Sperren, Statistik, Keyset) (`php TESTS/community-repositories/run.php [-v]`) |
| `TESTS/community-access/` | access decision `CommunityAccess::can()`: matrix of user class, action and state, trust levels, bans, windows, approval, filter / Zugriffsentscheidung `CommunityAccess::can()`: Matrix aus Nutzerklasse, Aktion und Zustand, Vertrauensstufen, Sperren, Fenster, Freigabe, Filter (`php TESTS/community-access/run.php [-v]`) |
| `TESTS/community-content/` | topics, posts, quotes and read states: counters, idempotency, editing with history, soft delete, keyset, source guard for UTC and OFFSET / Themen, Beiträge, Zitate und Lesestatus: Zähler, Idempotenz, Bearbeiten mit Verlauf, weiches Löschen, Keyset, Quelltext-Wächter für UTC und OFFSET (`php TESTS/community-content/run.php [-v]`) |
| `TESTS/community-render/` | Markdown rendering of the Community module: renderer core `ContentRenderer`, bundle `CMS/assets/commonmark/`, purifier profile `community`, counting, plain text, limits before and after parsing with pathological inputs, quote limit, host rule, quote marker and output rule (`QuoteService::presentable()`), display service `RenderService` (output per viewer, preview, render job) / Markdown-Darstellung des Community-Moduls: Renderer-Kern `ContentRenderer`, Bündel `CMS/assets/commonmark/`, Purifier-Profil `community`, Zählung, Klartext, Grenzen vor und nach dem Parsen mit pathologischen Eingaben, Zitatgrenze, Host-Regel, Zitatmarker und Ausgaberegel (`QuoteService::presentable()`), Darstellungsdienst `RenderService` (Ausgabe je Betrachter, Vorschau, Render-Job) (`php TESTS/community-render/run.php [-v]`) |
| `TESTS/community-xss/` | XSS corpus X1 to X40 against the Community renderer: full chain with profile `community` and CommonMark stage alone, DOM check of elements, attributes, classes and URLs / XSS-Korpus X1 bis X40 gegen den Community-Renderer: ganze Kette mit Profil `community` und CommonMark-Stufe allein, DOM-Prüfung von Elementen, Attributen, Klassen und URLs (`php TESTS/community-xss/run.php [-v]`) |
| `TESTS/community-spam/` | spam and abuse protection: `IpHash`, word filter, hosts, comparison form `fold()`, spam duplicate, core antispam, filter `cms_community_spam_check`, request limits `ContentGuard::throttle()`, security log / Spam- und Missbrauchsschutz: `IpHash`, Wortfilter, Hosts, Vergleichsfassung `fold()`, Spam-Dublette, Core-Antispam, Filter `cms_community_spam_check`, Request-Grenzen `ContentGuard::throttle()`, Sicherheitsprotokoll (`php TESTS/community-spam/run.php [-v]`) |
| `TESTS/community-moderation/` | moderation and counter booking: `ModerationService` (approve, reject, hide, restore, move with redirect topic, pin, solution, bulk action, queue, log, `systemAction`, `ipHashes`) and `CounterBook`, oracle `communityAudit()` after every step / Moderation und Zählerbuchung: `ModerationService` (Freigeben, Ablehnen, Ausblenden, Wiederherstellen, Verschieben mit Verweis-Thema, Anheften, Lösung, Sammelaktion, Warteschlange, Protokoll, `systemAction`, `ipHashes`) und `CounterBook`, Orakel `communityAudit()` nach jedem Schritt (`php TESTS/community-moderation/run.php [-v]`) |
| `TESTS/community-privacy/` | retention rules and DSGVO export/erasure of the community (dry run, blocks, limits, scope, idempotence) / Löschfristen und DSGVO-Auskunft/-Löschung der Community (Trockenlauf, Blöcke, Grenzen, Umfang, Idempotenz) (`php TESTS/community-privacy/run.php [-v]`) |
| `TESTS/community-trash/` | trash of the community: list, restore (counters as exact inverse), permanent deletion (posts, attachments, reactions, reports, chunks), provider contract / Papierkorb der Community: Liste, Wiederherstellen (Zähler als genaue Umkehrung), endgültiges Löschen (Beiträge, Anhänge, Reaktionen, Meldungen, Stücke), Provider-Vertrag |
| `TESTS/community-editor/` | visual editor of the write form: source checks always, browser checks (Chromium via Playwright, real template under the CMS CSP with Trusted Types) only with `node` and Playwright / Visueller Editor des Schreibformulars: Quelltextprüfung immer, Browser-Prüfung (Chromium über Playwright, echte Vorlage unter dem CSP des CMS mit Trusted Types) nur mit `node` und Playwright (`php TESTS/community-editor/run.php [-v]`) |
| `TESTS/community-frontend/` | public read pages (PR #4): route patterns and order, page segments, page cursor (`seite-N`), views without inline script / Öffentliche Leseseiten: Routenmuster und -reihenfolge, Seitensegmente, Seitencursor (`seite-N`), Ansichten ohne Inline-Skript (`php TESTS/community-frontend/run.php [-v]`) |
| `TESTS/community-member/` | member area (PR #4): tabs and moderation access, registry registration, POST evaluation, "my topics/replies" lists / Mitgliederbereich: Reiter und Moderationszugang, Anmeldung bei der Registry, POST-Auswertung, Listen „Meine Themen/Antworten“ (`php TESTS/community-member/run.php [-v]`) |
| `TESTS/community-api/` | JSON API (PR #4): actions and input checks before any service call, status mapping, CSRF filter for the persistent token, route registration, JS module without inline script / JSON-API: Aktionen und Eingabeprüfung vor dem Dienst, Statusabbildung, CSRF-Filter für das dauerhafte Token, Routenanmeldung, JS-Modul ohne Inline-Skript (`php TESTS/community-api/run.php [-v]`) |
| `TESTS/community-admin/` | admin pages `/admin/community*` (entry files, capability gates, view without inline script, CSRF in every form, settings round trip) / Admin-Seiten `/admin/community*` (Einstiegsdateien, Capability-Gates, Ansicht ohne Inline-Skript, CSRF in jedem Formular, Einstellungen im Rundlauf) (`php TESTS/community-admin/run.php [-v]`) |
| `TESTS/community-sanctions/` | reports and bans: `ReportService` (report, merge, limit, threshold, resolve, lists) and `BanService` (ban, lift with rank check, history), 404 rule and lock order / Meldungen und Sperren: `ReportService` (Melden, Zusammenführen, Grenze, Schwelle, Erledigen, Listen) und `BanService` (Sperren, Aufheben mit Rangprüfung, Verlauf), 404-Regel und Sperrfolge (`php TESTS/community-sanctions/run.php [-v]`) |
| `TESTS/community-notify/` | notifications, subscriptions, reactions and ranks: `NotificationDispatcher` (queue `community_notify_queue`, tiers, rights per recipient, mail gate and daily quota, report feedback, ban and move notices), `SubscriptionService` (auto-subscribe, preferences, unsubscribe link), `ReactionService`, `RankService`, core methods `notifyGrouped()` and `enqueuePlainStrict()` / Benachrichtigungen, Abos, Reaktionen und Ränge: `NotificationDispatcher` (Warteschlange `community_notify_queue`, Stufen, Rechte je Empfänger, Mail-Tor und Tageskontingent, Rückmeldung an Melder, Sperr- und Verschiebehinweis), `SubscriptionService` (Auto-Abo, Präferenzen, Abmeldelink), `ReactionService`, `RankService`, Core-Methoden `notifyGrouped()` und `enqueuePlainStrict()` (`php TESTS/community-notify/run.php [-v]`) |
| `TESTS/community-uploads/` | attachments: `AttachmentService` (extension, MIME and header check, file name, quotas under lock, decoding limits and slots, re-encoding without metadata, two-step storage, claim, resolve, cleanup) and `AttachmentRepository` / Anhänge: `AttachmentService` (Endung, MIME- und Kopfprüfung, Dateiname, Kontingente unter Sperre, Dekodiergrenzen und Slots, Neukodierung ohne Metadaten, zweistufige Ablage, Zuordnen, Auflösen, Aufräumen) und `AttachmentRepository` (`php TESTS/community-uploads/run.php [-v]`) |
| `TESTS/community-search-seo/` | search, index and sitemap: `CommunitySearch` (TNT index with `doclist_term_hits`, limit per word, shared guest bucket, LIKE fallback for members, journal, rebuild, index name from the secret, `sitemapEntries()`), `SearchRepository`, `CommunityMediaExtractor` / Suche, Index und Sitemap: `CommunitySearch` (TNT-Index mit `doclist_term_hits`, Grenze je Wort, gemeinsamer Eimer der Gäste, LIKE-Rückfall für Mitglieder, Journal, Neuaufbau, Indexname aus dem Geheimnis, `sitemapEntries()`), `SearchRepository`, `CommunityMediaExtractor` (`php TESTS/community-search-seo/run.php [-v]`) |
| `TESTS/community-db/` | Community services against a real MariaDB/MySQL (optional, `CMS_TEST_DB_HOST`/`CMS_TEST_DB_USER`): rollback, race, UTC with local time, EXPLAIN of the lists; areas of PR #2b in `sections-*.php` with worker actions in `actions-*.php` / Community-Dienste gegen eine echte MariaDB/MySQL (optional, `CMS_TEST_DB_HOST`/`CMS_TEST_DB_USER`): Rollback, Wettlauf, UTC bei Ortszeit, EXPLAIN der Listen; Bereiche aus PR #2b in `sections-*.php` mit Worker-Aktionen in `actions-*.php` (`php TESTS/community-db/run.php [-v]`) |
| `TESTS/community-mutants/` | counter-check: every rule of the Community suites runs once against an intentionally wrong implementation and must fail / Gegenprobe: jede Regel der Community-Suiten läuft einmal gegen eine absichtlich falsche Umsetzung und muss scheitern (`php TESTS/community-mutants/run.php [-v]`) |
| `TESTS/user-role-hook/` | core hook `user_role_changed` on role changes (user administration, bulk action, rename/delete role) / Core-Hook `user_role_changed` bei Rollenwechsel (Benutzerverwaltung, Sammelaktion, Rolle umbenennen/löschen) (`php TESTS/user-role-hook/run.php [-v]`) |
| `TESTS/schema-upgrade/` | schema upgrade against a real MySQL/MariaDB (optional, `CMS_TEST_DB_HOST`/`CMS_TEST_DB_USER`): fresh install v27 and updates from 4.0.0 (v25), 4.1.8 (v26) and, with the Git tag, 3.5.x (v23) yield identical structure matching the snapshot `TESTS/schema-v27/golden.json`, plus the one-time permission step `capabilities_v27`, the collation of the Community tables and the repair path, keep data, are idempotent, one connection on first request / Upgrade-Pfad gegen echte MySQL/MariaDB (optional, `CMS_TEST_DB_HOST`/`CMS_TEST_DB_USER`): Neuinstallation v27 und Updates von 4.0.0 (v25), 4.1.8 (v26) und, mit dem Git-Tag, 3.5.x (v23) ergeben dieselbe Struktur gemäß Snapshot `TESTS/schema-v27/golden.json`, dazu der einmalige Rechte-Schritt `capabilities_v27`, die Kollation der Community-Tabellen und der Reparaturpfad, erhalten Daten, laufen idempotent, eine Verbindung beim ersten Request (`php TESTS/schema-upgrade/run.php [-v]`) |
| `TESTS/security/` | security tests / Sicherheitstests (`php TESTS/security/run.php`) |
| `TESTS/theme-contract/` | theme contract (CSP, layout hooks, versions), optional further theme roots as arguments / Theme-Vertrag (CSP, Layout-Hooks, Versionen), optional weitere Theme-Wurzeln als Argumente (`php TESTS/theme-contract/run.php [Theme-Wurzeln …]`) |
| `TESTS/update-swap/` | update directory swap test / Test des Verzeichnistauschs beim Update (`php TESTS/update-swap/run.php`) |
| `CODE_OF_CONDUCT.md` | code of conduct |
| `README.md` | public README |
| `Changelog.md` | changelog |

`ASSETS/` and `tools/` are not part of this repository; under `TESTS/` every suite `TESTS/<suite>/run.php` is versioned (one row per suite in the table above), all run by `php TESTS/run-all.php` in CI (see [FILESTRUCTUR.md](FILESTRUCTUR.md)). / `ASSETS/` und `tools/` sind nicht Teil dieses Repositories; unter `TESTS/` ist jede Suite `TESTS/<suite>/run.php` versioniert (je Suite eine Zeile in der Tabelle oben), alle führt `php TESTS/run-all.php` in der CI aus.

Rule: **always know whether you are editing runtime, documentation, or source context.**

Regel: **immer klären, ob gerade Runtime, Doku oder Quellkontext bearbeitet wird.**

---

## 2. Runtime under CMS/ | Produktive Kernstruktur unter CMS/

**English**

`CMS/` is the application the web server should serve.

**Deutsch**

`CMS/` ist die Anwendung, die der Webserver ausliefern soll.

| Path | Purpose / Zweck |
|---|---|
| `.htaccess` | rewrite and protection rules |
| `index.php` | public entry |
| `config.php` | config stub, PHP 8.4.1+ gate |
| `config/` | real configuration |
| `install.php` / `install/` | installer |
| `cron.php` | cron entry |
| `update.php` / `update.json` | updates |
| `orders.php` | orders entry |
| `core/` | bootstrap, security, routing, services |
| `admin/` | backend |
| `member/` | member area |
| `themes/` | theme runtime |
| `plugins/` | plugin runtime |
| `assets/` | CSS, JS, bundled libraries |
| `includes/` | helpers |
| `lang/` | `de.yaml`, `en.yaml` |
| `marketplace/` | marketplace manifests |
| `vendor/` | extra vendor (`dompdf`) |
| `views/` | extra views |
| `uploads/`, `cache/`, `logs/`, `backups/` | storage / ops |

Hotspots: `core/Bootstrap.php`, `core/Security.php`, `core/ThemeManager.php`, `core/PluginManager.php`, `admin/`, `assets/js/`, `member/includes/class-member-controller.php`.

---

## 3. Inventory vs this map | Inventar und Strukturkarte

**English**

Do not use the old 467-file / 28.03.2026 numbers. Current counts and file lists are in [CMSFILESTRUCTUR.md](CMSFILESTRUCTUR.md). `DOC/_cms_inventory_current.txt` does not exist.

| Document | Role |
|---|---|
| [CMSFILESTRUCTUR.md](CMSFILESTRUCTUR.md) | inventory |
| [FILELIST.md](FILELIST.md) | this readable map |
| [DEVLIST.md](DEVLIST.md) | architecture and contracts |
| [FILESTRUCTUR.md](FILESTRUCTUR.md) | repository layout |

**Deutsch**

Zählungen und Dateilisten stehen in [CMSFILESTRUCTUR.md](CMSFILESTRUCTUR.md).

---

## 4. CMS/config/ | Konfiguration

**English** / **Deutsch**

| File | Purpose / Zweck |
|---|---|
| `config/.htaccess` | deny direct access |
| `config/app.php` | DB, keys, paths, SMTP, LDAP, JWT, HSTS, theme, encryption key ring, backup path |
| `config/media-meta.json` | media metadata |
| `config/media-settings.json` | media settings |

`config/app.php` defines `ABSPATH`, `CMS_DEBUG`, `DB_*`, `AUTH_KEY`, `SECURE_AUTH_KEY`, `NONCE_KEY`, `SITE_NAME`, `SITE_URL` (no trailing slash, no subdirectory), `ADMIN_EMAIL`, `CMS_VERSION` from `Version::CURRENT`, path constants, `DEFAULT_THEME = cms-default`, login limits, optional LDAP/JWT/SMTP and, since 4.0.0, `CMS_ENCRYPTION_KEY` / `CMS_ENCRYPTION_KEYS_PREVIOUS` (key ring for encrypted secrets and backups) and `CMS_BACKUP_PATH` (backup directory).

Changes here affect bootstrap, HTTPS, sessions, mail, LDAP, JWT, and logging at once.

---

## 5. CMS/core/ | Technischer Kern

**English**

If you need to know **how** 365CMS works, start in `core/`. Suggested order: `Bootstrap.php` → router/manager → service → Auth/Security/Hooks → then views.

Bootstrap modes (`Bootstrap::detectMode()`):

| Mode | Trigger |
|---|---|
| `cli` | `PHP_SAPI === 'cli'` |
| `api` | path starts with `/api/` |
| `admin` | path is `/admin` or starts with `/admin/` |
| `web` | everything else |

**Deutsch**

Wer verstehen will, **wie** 365CMS arbeitet, beginnt in `core/`. Lesereihenfolge: `Bootstrap.php` → Router/Manager → Service → Auth/Security/Hooks → danach Views.

Bootstrap-Modi (`Bootstrap::detectMode()`):

| Modus | Auslöser |
|---|---|
| `cli` | `PHP_SAPI === 'cli'` |
| `api` | Pfad beginnt mit `/api/` |
| `admin` | Pfad ist `/admin` oder beginnt mit `/admin/` |
| `web` | alles andere |

Root classes, Auth, Routing, and the service list are inventoried in [CMSFILESTRUCTUR.md](CMSFILESTRUCTUR.md). Service families:

| Family | Examples |
|---|---|
| Mail | `MailService`, `MailQueueService`, `MailLogService`, `AzureMailTokenProvider`, `GraphApiService` |
| SEO | `SEOService`, `SeoAnalysisService`, `RedirectService`, `SitemapService` |
| Media | `MediaService`, `MediaDeliveryService`, `FileUploadService`, `ImageService` |
| Editor | `EditorJsService`, `EditorJsRenderer`, `EditorService`, `PostPublishChecklistService`, `PostRevisionService` |
| Admin UI | `AdminNavigationService`, `AdminUiPreferenceService`, `AdminSiteContextService`, `PluginSiteAssignmentService`, `AdminNoticeService` |
| Admin menu | `Services/AdminMenu/`: `CoreCatalog`, `ProfileLayouts`, `MenuRegistry`, `MenuBuilder`, `LayoutValidator`, `LayoutRepository`, `BadgeProvider`, `AdminMenuService` ([admin/ADMIN-MENU.md](admin/ADMIN-MENU.md)) |
| Content functions | `Forms/` (`FormService`, `FormSubmissionService`, `FormUploadService` …), `Downloads/`, `Knowledgebase/` (Repository, Linker, CSV-Pakete, Provider), `Newsletter/` (Repository, Mailer, Dispatcher, Frontend, DSGVO), `Trash/` + `TrashListActions`, `Exchange/` (`ContentExchangeService` + providers), `Search/` (`SearchIndexService`, `SearchTermStatsService`), `ContentChangesService`, `TranslationStatusService`, `Media/ThumbnailRegenerationService`, `Media/MediaLicenseService`, `Legal/AccessibilityStatementService` |
| System | `AdminToolbarService` (cache, maintenance), `LoginThrottleSettings`, `EnvironmentInfoService`, `DataRetentionService` + `Retention/` |
| Extension points | `FeatureHooks\Registry`, `BatchJobService`, `PrivateStorageService`, `IntegratedPluginService`, `PluginMigration\PluginMigrator`, `Retention\RetentionRule`, `EditorJs\EditorJsBlockRegistry`, `ContentTypes\ContentTypeRegistry` ([core/CONTENT-TYPE-PROVIDERS.md](core/CONTENT-TYPE-PROVIDERS.md)) |
| Identity & security | `Security/PasswordPolicyService`, `Security/MfaEnforcementService`, `Auth/SSO/` (Entra ID), `Routing/SsoRouter` |
| Media | `Media/AvifDerivativeService`, `Media/AvifRegenerationService`, `Media/ImageOrientation`, `Media/ImageMetadataStripper`, `Media/MediaUsageIndex`, `Media/MediaReferenceExtractor`, `Media/Usage/` |
| Editorial | `Content/ContentStatus`, `Content/ContentWorkflow`, `Content/ContentExpiryService`, `ReusableBlocks/ReusableBlockService` |
| Operations | `Backup/OffsiteBackupService` (+ `S3Client`, `SigV4Signer`), `Webhooks/WebhookService`, `Payments/PaymentService` (Stripe, Mollie), `Routing/PaymentRouter` |
| Landing | `LandingPageService` + `Services/Landing/` |
| AI | `Services/AI/` (admin-only in 3.5.0) |
| Member | `MemberService`, `MessageService` |
| Ops | `BackupService`, `UpdateService`, `StatusService`, `SystemService` |

---

## 6. CMS/admin/ | Backend-Struktur

**English**

408 files. Layout:

- `CMS/admin/*.php`. HTTP entry points
- `CMS/admin/modules/<domain>/`, domain modules (`comments`, `community`, `content`, `dashboard`, `downloads`, `feeds`, `forms`, `hub`, `knowledgebase`, `landing`, `legal`, `media`, `member`, `menus`, `newsletter`, `pages`, `plugins`, `posts`, `reusable-blocks`, `security`, `seo`, `settings`, `subscriptions`, `system`, `tables`, `themes`, `toc`, `trash`, `users`)
- `CMS/admin/views/`, templates
- `CMS/admin/partials/`, shared chrome (including sidebar)
- `CMS/admin/logs/`, log UI helpers
- `CMS/admin/settings-tabs/`, extension tabs of `/admin/settings` (`admin-menu.php`, `feeds.php`, `security.php`, `sso.php`)

Notable routes present as files: `/admin/cms-loginpage` (`cms-loginpage.php`), `/admin/cms-logs` (`cms-logs.php`), AI admin scripts (`ai-services.php`, `ai-content-creator.php`, `ai-seo-creator.php`, `ai-translation.php`, …), the JSON endpoints `admin-ui.php` (admin shell) and `post-editor-api.php` (post editor) plus `plugin-assignment.php`, `multisites.php` (MultiSites) and `configuration.php` (tile pages `/admin/configuration[?group=…]`). Content and tool pages added in 4.0.0: `forms.php`, `form-submissions.php`, `downloads.php`, `knowledgebase.php`, `newsletter.php`, `editorial-calendar.php`, `trash.php`, `content-changes.php`, `translations.php`, `search-index.php`, `content-exchange.php`, `data-retention.php`, `media-tools.php`, each long-running one with a JSON endpoint `*-api.php`.

**Deutsch**

408 Dateien. Aufbau:

- `CMS/admin/*.php`. HTTP-Einstiege
- `CMS/admin/modules/<domain>/`. Fachmodule
- `CMS/admin/views/`. Templates
- `CMS/admin/partials/`, gemeinsame Oberfläche (inkl. Sidebar)
- `CMS/admin/logs/`. Log-UI
- `CMS/admin/settings-tabs/`. Erweiterungs-Tabs von `/admin/settings` (`admin-menu.php`, `feeds.php`, `security.php`, `sso.php`)

Besondere Dateien: `cms-loginpage.php`, `cms-logs.php`, AI-Admin-Skripte (`ai-services.php`, `ai-content-creator.php`, `ai-seo-creator.php`, `ai-translation.php`, …), die JSON-Endpunkte `admin-ui.php` (Admin-Shell) und `post-editor-api.php` (Beitragseditor) sowie `plugin-assignment.php`, `multisites.php` (MultiSites) und `configuration.php` (Kachelseiten `/admin/configuration[?group=…]`). Seit 4.0.0 außerdem die Inhalts- und Werkzeugseiten `forms.php`, `form-submissions.php`, `downloads.php`, `knowledgebase.php`, `newsletter.php`, `editorial-calendar.php`, `trash.php`, `content-changes.php`, `translations.php`, `search-index.php`, `content-exchange.php`, `data-retention.php`, `media-tools.php`, bei langen Aufgaben mit JSON-Endpunkt `*-api.php`.

---

## 7. CMS/assets/ | Produktive Frontend-/Admin-Assets

**English**

Runtime assets are under `CMS/assets/`, not the repository `ASSETS/` folder.

- `assets/css/`. 32 stylesheets (admin incl. shell/tokens, post editor, plugin assignment, MultiSites, tile pages, sidebar editor, forms, downloads, feeds, knowledge base, newsletter, editorial calendar, hub, consent, EditorJS content, member, public)
- `assets/js/`. 59 scripts (admin modules incl. sidebar editor and batch runner, EditorJS boot, cookie consent init, member dashboard, PhotoSwipe, web vitals) plus 22 ES modules in `admin-shell/`, `editor-blocks/` and `post-editor/` (no build step)
- `assets/fonts/`. Inter, Source Serif 4, JetBrains Mono as local WOFF2 files
- bundled libraries, see [CMSFILESTRUCTUR.md](CMSFILESTRUCTUR.md)

`cookieconsent`, `elfinder`, `filepond`, and `msgraph` directories are **not** present under `CMS/assets/` in this tree. `cookieconsent-init.js` still exists.

**Deutsch**

Runtime-Assets liegen unter `CMS/assets/`, nicht im Repository-Ordner `ASSETS/`.

- `assets/css/`. 32 Stylesheets
- `assets/js/`. 59 Skripte plus 22 ES-Module in `admin-shell/`, `editor-blocks/`, `member/` und `post-editor/` (ohne Build-Schritt)
- `assets/fonts/`. Inter, Source Serif 4, JetBrains Mono als lokale WOFF2-Dateien
- gebündelte Bibliotheken, siehe [CMSFILESTRUCTUR.md](CMSFILESTRUCTUR.md)

Die Ordner `cookieconsent`, `elfinder`, `filepond` und `msgraph` liegen in diesem Baum **nicht** unter `CMS/assets/`. `cookieconsent-init.js` existiert weiterhin.

---

## 8. CMS/member/ | Benutzerbereich

**English**

17 files. Pages: dashboard, favorites, media, messages, notifications, plugin-section, privacy, profile, security, subscription. Controller: `member/includes/class-member-controller.php`. Router: `core/Routing/MemberRouter.php`. Service: `core/Services/MemberService.php`.

**Deutsch**

17 Dateien. Seiten: Dashboard, Favoriten, Medien, Nachrichten, Benachrichtigungen, Plugin-Bereich, Datenschutz, Profil, Sicherheit, Abo. Controller: `member/includes/class-member-controller.php`. Router: `core/Routing/MemberRouter.php`. Service: `core/Services/MemberService.php`.

---

## 9. CMS/plugins/ | Produktiv geladene Plugins

**English**

Loaded by `PluginManager` from `CMS/plugins/<slug>/`. Currently installed: **`cms-importer`** only. Sibling repo `365CMS.DE-PLUGINS` is not an automatic load path.

**Deutsch**

Geladen durch `PluginManager` aus `CMS/plugins/<slug>/`. Derzeit installiert: nur **`cms-importer`**. Das Nachbar-Repo `365CMS.DE-PLUGINS` ist kein automatischer Ladepfad.

---

## 10. CMS/themes/ | Produktiv geladene Themes

**English**

`ThemeManager` reads the active theme from settings and uses `themes/<slug>/`. Currently installed: **`cms-default`**. Fallback constant: `DEFAULT_THEME = cms-default`. Typical files: `style.css`, `functions.php`, `index.php`, `header.php`, `footer.php`, `page.php`, `home.php`, `404.php`, `search.php`, `theme.json`, `update.json`. Sibling repo `365CMS.DE-THEME` is not an automatic load path.

**Deutsch**

`ThemeManager` liest das aktive Theme aus den Settings und nutzt `themes/<slug>/`. Derzeit installiert: **`cms-default`**. Fallback: `DEFAULT_THEME = cms-default`. Das Nachbar-Repo `365CMS.DE-THEME` ist kein automatischer Ladepfad.

---

## 11. Other CMS areas | Weitere Runtime-Bereiche

**English** / **Deutsch**

| Path | Notes |
|---|---|
| `CMS/includes/` | 12 helper files, including `wordpress-compat.php` and `admin-menu-profiles.php` |
| `CMS/lang/` | `de.yaml`, `en.yaml` |
| `CMS/logs/` | `LOG_PATH`: not `var/logs/` (that directory does not exist here) |
| `CMS/uploads/` | public uploads; `.gitkeep` and `.htaccess` in git |
| `CMS/cache/` | cache, including optimizer output |
| `CMS/install/` | 10 installer files (six steps, including "Admin layout") |
| `CMS/vendor/` | `dompdf` only in this tree: **no TCPDF** |
| `CMS/views/` | extra views: `auth/`, `downloads/`, `feeds/`, `knowledgebase/`, `newsletter/`, `partials/` |
| `CMS/marketplace/` | core/plugin/theme index JSON |
| `CMS/backups/` | local backups, protected |

---

## 12. DOC/ | Dokumentationsbaum

**English**

Canonical `DOC/` files: `README.md`, `INDEX.md`, `INSTALLATION.md`, `DEVLIST.md`, `FILELIST.md`, `FILESTRUCTUR.md`, `CMSFILESTRUCTUR.md`.

Subfolders: `admin/`, `ai/`, `assets/`, `audit/`, `core/`, `member/`, `plugins/`, `theme/`, `workflow/`.

Asset Markdown is under `DOC/assets/`. The audit reports are in `DOC/audit/`; open gaps are tracked in `core/STATUS.md`.

**Deutsch**

Kanonische `DOC/`-Dateien: `README.md`, `INDEX.md`, `INSTALLATION.md`, `DEVLIST.md`, `FILELIST.md`, `FILESTRUCTUR.md`, `CMSFILESTRUCTUR.md`.

Unterordner: `admin/`, `ai/`, `assets/`, `audit/`, `core/`, `member/`, `plugins/`, `theme/`, `workflow/`.

Asset-Markdown liegt unter `DOC/assets/`. Die Prüfberichte liegen unter `DOC/audit/`; offene Lücken stehen in `core/STATUS.md`.

---

## 13. Sibling repositories | Benachbarte Repositories

**English**

`365CMS.DE-THEME` and `365CMS.DE-PLUGINS` hold sources. Runtime effect requires files under `CMS/themes/` or `CMS/plugins/`.

**Deutsch**

`365CMS.DE-THEME` und `365CMS.DE-PLUGINS` enthalten Quellen. Runtime-Wirkung entsteht erst unter `CMS/themes/` bzw. `CMS/plugins/`.

---

## 14. Task orientation | Schnelle Orientierung nach Aufgabenart

| If you … / Wenn du … | start at / beginne bei |
|---|---|
| Bootstrap | `CMS/core/Bootstrap.php`, `CMS/config/app.php` |
| Auth / MFA / Passkeys | `CMS/core/Auth.php`, `CMS/core/Auth/`, `CMS/views/auth/` |
| Themes | `CMS/core/ThemeManager.php`, `CMS/themes/` |
| Plugins | `CMS/core/PluginManager.php`, `CMS/plugins/` |
| Redirects / 404 | `CMS/admin/modules/seo/`, `CMS/assets/js/admin-seo-redirects.js` |
| EditorJS | `CMS/assets/js/admin-content-editor.js`, `CMS/core/Services/EditorJs/`, `CMS/assets/editorjs/` |
| Member | `CMS/member/`, `CMS/core/Routing/MemberRouter.php` |
| Admin UI | `CMS/admin/modules/`, `CMS/admin/views/`, `CMS/assets/js/admin*.js` |
| Admin shell / sidebar | `CMS/admin/partials/`, `CMS/core/Services/AdminNavigationService.php`, `CMS/assets/css/admin-shell.css`, `CMS/assets/js/admin-shell/` |
| Admin menu (profiles, editor) | `CMS/core/Services/AdminMenu/`, `CMS/admin/settings-tabs/admin-menu.php`, `CMS/assets/js/admin-sidebar-editor.js`, `TESTS/admin-menu/run.php`, [admin/ADMIN-MENU.md](admin/ADMIN-MENU.md) |
| Post editor (checklist, revisions) | `CMS/admin/views/posts/edit.php`, `CMS/admin/post-editor-api.php`, `CMS/core/Services/PostRevisionService.php`, `CMS/assets/js/post-editor/` |
| Assets | `CMS/assets/` |
| Security constants | `CMS/config/app.php`, `CMS/core/Security.php` |
| AI (admin only) | `CMS/admin/ai-*.php`, `CMS/core/Services/AI/` |
| Docs | `DOC/INDEX.md`, `DOC/DEVLIST.md`, `DOC/FILELIST.md` |

---

## 15. Summary | Kurzfazit

**English**

Read 365CMS along the runtime:

`config/` sets rules → `core/` is the foundation → `admin/` is the backend → `member/` is the member area → `assets/` is UI and libraries → `plugins/` and `themes/` are the real extension runtime → `DOC/` explains it.

**The path that is actually loaded under `CMS/` wins.** Sibling source repos and the top-level `ASSETS/` folder are not automatic runtime.

**Deutsch**

365CMS entlang der Runtime lesen:

`config/` setzt Regeln → `core/` ist das Fundament → `admin/` das Backend → `member/` der Benutzerbereich → `assets/` UI und Bibliotheken → `plugins/` und `themes/` die echte Erweiterungs-Runtime → `DOC/` erklärt das Ganze.

**Entscheidend ist der tatsächlich geladene Runtime-Pfad unter `CMS/`.** Nachbar-Quellrepos und der Top-Level-Ordner `ASSETS/` sind keine automatische Runtime.
