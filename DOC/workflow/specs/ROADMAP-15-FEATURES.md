# 365CMS – Projektdokumentation | Abschnitt: Workflow – Spec: Roadmap – 15 Funktionen in 4 Arbeitspaketen
> **Stand:** 2026-11-10 | **Version:** 4.0.0 | **Status:** In Umsetzung | **Update:** 2026-11-10
> **Fortschritt:** [PROGRESS.md](PROGRESS.md) · **Verbindliche Abweichungen:** [KLARSTELLUNGEN.md](KLARSTELLUNGEN.md)

## English (summary)

Fifteen core features in four work packages: trash, cache/maintenance toolbar, login thresholds, editorial calendar, revision overview, translation status, search index, content export/import, form builder, retention, media licenses, accessibility statement, thumbnail regeneration, RSS settings and environment info. The original requirement text is reproduced unchanged below. Where it conflicts with
[KLARSTELLUNGEN.md](KLARSTELLUNGEN.md) (single 4.0.0 release, dark sidebar, no site assignment for content,
hub-site rules), the clarifications take precedence.

## Deutsch

Originaltext der Anforderung (unverändert). Bei Widersprüchen gelten die
[Klarstellungen](KLARSTELLUNGEN.md), insbesondere:

- Version bleibt 4.0.0 (statt einer Version pro Arbeitspaket), ein Schema-Stand v25.
- Site-Filter/`site_id` entfallen für Inhalte (Kalender, Revisionen, Übersetzungen, Suchindex, Export/Import, Statistiken).
- Hub-Sites: Papierkorb und Export/Import ja, Suchindex/Revisionen/Kalender/Übersetzungsstatus nein.

### Übersichtstabelle

| # | Priorität | Funktion | Was konkret einbauen | Baut auf |
|---|---|---|---|---|
| 1 | 🔴 Jetzt umsetzen | **Papierkorb** für Seiten, Beiträge und Medien | Status `trash` in der UI nutzbar machen: Filter „Papierkorb“, Wiederherstellen und endgültig löschen. Nach X Tagen leert ein Cron-Job den Papierkorb automatisch. Bei Medien wird vor dem Löschen geprüft, wo sie noch verwendet werden. | DB-Status `trash`, `MediaUsageService`, `DataRetentionService` |
| 2 | 🔴 Jetzt umsetzen | **Toolbar: Cache & Wartung** | Button „Cache leeren“ mit Auswahl (alles, Assets oder OPcache) und Schalter für den Wartungsmodus, der deutlich markiert ist, solange er aktiv ist | `CacheManager`, Wartungsmodus-Setting, `admin-shell/` |
| 3 | 🔴 Jetzt umsetzen | **Login-Grenzwerte einstellbar** | Ein Setting in `cms_settings` überschreibt die Konstante, mit festen Grenzen (z. B. 3–20 Versuche). Die Konstante in `app.php` bleibt der Standardwert. | `MAX_LOGIN_ATTEMPTS`, `LOGIN_TIMEOUT` |
| 4 | 🟠 Als Nächstes | **Redaktionskalender + Widget** | Monats- und Wochenansicht für geplante Inhalte und Entwürfe, Umplanen per Drag & Drop. Dazu ein Dashboard-Widget „Meine Entwürfe / demnächst geplant“. | Filter „Geplant“, `DashboardService` |
| 5 | 🟠 Als Nächstes | **Zentrale Revisionsübersicht** | Seite „Letzte Änderungen“ über alle Inhalte (wer, wann, was) mit Sprung in den Vergleich im Editor | `PostRevisionService` |
| 6 | 🟠 Als Nächstes | **Übersetzungsstatus** | Matrix aus Inhalt und Sprache mit Status *fehlt*, *aktuell* oder *veraltet*. Von dort lässt sich die KI-Übersetzung direkt anstoßen. | `ContentLocalizationService`, `ai-translation` |
| 7 | 🟠 Als Nächstes | **Suchindex verwalten** | Button „Index neu aufbauen“ mit Fortschrittsanzeige, Statistik der Suchbegriffe und eine Liste der Suchen ohne Treffer | TNTSearch, `SiteSearchService` |
| 8 | 🟠 Als Nächstes | **Inhalts-Export/-Import** | Seiten, Beiträge, Taxonomien und optional Medien als ZIP (JSON plus Dateien) exportieren und mit Vorschau importieren. Damit lassen sich Inhalte auch zwischen den Sites einer MultiSite-Installation verschieben. | `CsvExchange`, Importer-Logik |
| 9 | 🟡 Danach | **Formular-Builder + Eingänge** | Felder per Baukasten zusammenstellen, eingegangene Formulare in einer Übersicht mit Export, Benachrichtigung per Mail. Antispam und Löschfrist lassen sich pro Formular einstellen. | `AntispamService`, `MailService`, `DataRetentionService` |
| 10 | 🟢 Sollte zeitnah | **Löschfristen erweitern** | Formulareingänge und Kommentar-IPs in die automatische Löschung aufnehmen | `DataRetentionService` |
| 11 | 🟢 Sollte zeitnah | **Copyright/Lizenz bei Medien** | Felder für Urheber, Lizenz, Quelle und optional „KI-generiert“, ausgegeben in der Bildunterschrift und im Schema.org-Markup | `MediaService`, `config/media-meta.json` |
| 12 | 🟢 Sollte zeitnah | **Barrierefreiheitserklärung** | Vorlage unter den Rechtstexten | Legal-Modul (`admin-legal-sites.js`) |
| 13 | 🟢 Sollte zeitnah | **Thumbnails neu erzeugen** | Stapelverarbeitung per Cron mit Fortschrittsanzeige, nötig nach Änderungen an den Bildgrößen | `ImageService`, `CronRunnerService` |
| 14 | 🟢 Sollte zeitnah | **RSS-Einstellungen** | Anzahl der Einträge, Volltext oder Auszug, Feed pro Kategorie | `FeedService` |
| 15 | 🟢 Sollte zeitnah | **Umgebungsinfo** | Liste der PHP-Erweiterungen (vorhanden oder fehlend, jeweils mit dem Feature, das sie braucht) sowie die Versionen von OPcache und Datenbank | `SystemService`, Systeminfo-Seite |

### Originaltext

```text
[TASK TYPE: Feature – core roadmap, 15 items in 4 work packages]

Context:
- Stack: PHP 8.4.1+ / HTML / CSS / vanilla JavaScript, self-built CMS "365CMS", runtime under CMS/
- Read DOC/DEVLIST.md, DOC/FILELIST.md, DOC/core/HOOKS-REFERENCE.md, DOC/core/DATABASE-SCHEMA.md,
  DOC/admin/README.md and DOC/core/STATUS.md before changing anything.
- This task runs AFTER the plugin integration (cms-feed, cms-contact, cms-downloads → core). FormService,
  the "Feeds" settings tab and the forms inbox may already exist – extend them, never duplicate them.
- Relevant core pieces: CacheManager, OpcacheWarmupService, AssetOptimizerService, Security, AuthManager,
  DashboardService, PostRevisionService, ContentLocalizationService, ContentLanguageCopyService,
  Services/AI (ai-translation), SiteSearchService, SearchService (TNTSearch), CsvExchange, FormService,
  AntispamService, MailQueueService, DataRetentionService, CommentService, MediaService, MediaUsageService,
  ImageService, CronRunnerService, FeedService, StatusService, SystemService, AuditLogger,
  AdminNavigationService, AdminNoticeService, admin-ui.php, admin-shell/ ES modules, legal module.

Goal:
Implement the 15 items below. Deliver them as 4 work packages, one PR each, every PR with its own version
per project rules (Version::CURRENT, both update.json files, Changelog heading "### vX.Y.Z — DD.MM.YYYY",
RELEASE zips with SHA-256). Use one schema bump per work package that needs DB changes.

PHASE 0 (before coding, per work package)
- Check what already exists for each item (code, settings, tables, hooks). Report: exists / partial / missing,
  and the exact files you will change. Do not rebuild existing functionality.

══════════ WORK PACKAGE 1 – "Jetzt umsetzen" ══════════

1. Trash for pages, posts and media
- Make the existing DB status "trash" usable: list filter "Papierkorb" with count, row and bulk actions
  "In den Papierkorb", "Wiederherstellen", "Endgültig löschen", "Papierkorb leeren".
- Store previous_status, trashed_at, trashed_by (migration if columns are missing). Restore returns the
  previous status; resolve slug conflicts on restore.
- Trashed items are removed from frontend, sitemap, feeds and search index; restore re-adds them.
- Permanent delete also removes revisions and usage records. Media: before deleting, check MediaUsageService;
  if still used, list the usages and require explicit confirmation.
- Auto-empty after N days (setting, 0 = never) via daily cron, logged in AuditLogger.
- Reuse the UI pattern of the existing comment trash.

2. Toolbar: cache & maintenance
- Admin shell header: dropdown "Cache leeren" with options: everything, page/object cache, optimized assets,
  OPcache (only if available; warm up again via OpcacheWarmupService). Result as toast.
- Maintenance mode switch in the header: custom confirm dialog (no native alert/confirm), and a clearly
  visible badge/banner in the admin shell as long as maintenance is active.
- JSON actions via admin-ui.php with CSRF, capability manage_system, audit log entry per action.

3. Configurable login thresholds
- Settings → Sicherheit: max login attempts (allowed range 3–20) and lockout window in seconds (60–3600).
- Effective value = setting if set and within range, otherwise the constant from config/app.php
  (MAX_LOGIN_ATTEMPTS, LOGIN_TIMEOUT). Clamp server-side. Show effective value and its source
  ("Konstante" / "Einstellung") in the UI.
- Applies to every path that uses login_attempts (admin, member, password reset). Audit log on change.

══════════ WORK PACKAGE 2 – "Als Nächstes" ══════════

4. Editorial calendar + dashboard widget
- Inhalte → Redaktionskalender: month and week view, vanilla JS (no new calendar library).
- Shows scheduled content, drafts with an optional "planned date" field (add if missing) and optionally
  published content. Color by status/type; filters: author, type, category, language, site.
- Drag & drop reschedules (permission check, confirmation; moving a scheduled item into the past asks
  whether to publish now). Keyboard alternative: date picker in a popover. Click opens the editor.
- Dashboard widget "Meine Entwürfe" (last 5 edited) and "Demnächst geplant" (next 7 days).
- Use the site timezone from settings.

5. Central revision overview
- Inhalte → Letzte Änderungen: revisions across all pages/posts with date, user, content, change type
  (created, updated, status change, restored). Filters: user, type, date range, site. Pagination.
- Link opens the editor with the revisions panel on that revision (existing post-editor revisions module).
- Add a DB index on the revision timestamp if missing.

6. Translation status
- Inhalte → Übersetzungen: matrix content × configured languages with status
  missing / current / outdated. Outdated = source changed after the translation was last synced
  (store the source revision or hash at translation time; migration if needed).
- Actions per cell: create translation (ContentLanguageCopyService), translate with AI (existing admin-only
  ai-translation; result is always saved as draft, never auto-published), mark as current.
- Filters (type, language, status, site) and counters per status.

7. Search index management
- System → Suchindex: status (documents, last build, size) per site/language.
- "Index neu aufbauen": batched, resumable, with progress bar, lock against parallel runs.
- Search term statistics: log frontend searches anonymously (normalized term, result count, timestamp;
  no IP, no user id), aggregate daily, show top terms and zero-result terms, setting to disable logging,
  retention via DataRetentionService.

8. Content export / import
- System → Import/Export.
- Export: choose site, content types (pages, posts, taxonomies), status, date range, optional media files.
  ZIP with manifest.json (CMS version, schema version, site, counts, SHA-256 per file), content as
  Editor.js JSON, taxonomies, media metadata + files.
- Import: upload ZIP into a staging directory (size limit, zip-slip protection, realpath checks),
  validate manifest and schema compatibility, preview (counts, slug conflicts), options:
  conflict = skip / overwrite / rename, author mapping, target site, import as draft.
  Run in batches with a final report. Re-sanitize Editor.js content via the existing services and
  rewrite internal media references to the new IDs.
- Reuse CsvExchange where sensible. The WordPress import stays in the cms-importer plugin.

══════════ WORK PACKAGE 3 – "Danach" ══════════

9. Form builder + submissions
- Extend the existing FormService (from the contact integration) into a builder under Inhalte → Formulare.
- Field types: text, email, textarea, number, date, select, radio, checkbox, consent, hidden, file upload,
  static text/heading. Per field: label, placeholder, help text, required, validation, width.
  Labels in DE/EN.
- Per form: recipients, subject, optional confirmation mail, success message or redirect, antispam level,
  store submissions yes/no, retention days.
- File uploads: allowed types and size, stored in a private directory, accessible only in the admin.
- Embedding via Editor.js block "Formular" (registered in factory, asset registry and public renderer).
- Submissions inbox (already from contact) gets per-form filters and CSV export.

10. Extend retention periods
- Form submissions: retention per form plus a global default.
- Comment IPs: anonymize or remove IPs after N days (setting) – the comment itself stays.
- Show all retention rules in one overview with last run and a dry-run count.

══════════ WORK PACKAGE 4 – "Sollte zeitnah" ══════════

11. Copyright / license for media
- Fields: creator, license (select: all rights reserved, CC variants, public domain, custom), source URL,
  credit text, "KI-generiert" flag with optional tool name.
- Editable in the media modal and as a bulk action; column in the media list.
- Prefill from IPTC/EXIF on upload where available.
- Output: optional credit line in figure captions (theme can disable) and Schema.org ImageObject
  (creator, license, copyrightNotice, creditText).
- Follow the existing media metadata storage; if it is the JSON file config/media-meta.json,
  make writes concurrency-safe or move to the DB with migration.

12. Accessibility statement
- New template in the legal module (Rechtstexte), DE/EN, with editable fields: conformance status,
  non-accessible content and reasons, date of creation/last review, feedback contact, enforcement body.
- Generates a page, optional footer link, yearly review reminder via AdminNoticeService.
- Admin help text: template only, no legal advice.

13. Regenerate thumbnails
- Medien → Werkzeuge: regenerate all / selected / only missing sizes, optionally delete obsolete sizes.
- Batched and resumable (AJAX chunks or cron), progress bar, lock, error report, respects memory limits.

14. RSS settings
- If the plugin integration already delivered the "Feeds" settings tab, only verify completeness:
  items per feed, full text vs. excerpt, per-category feeds. Otherwise implement it in FeedService.

15. Environment info
- Extend the existing system info page: PHP extensions table with status (present / missing), category
  (required / feature) and the affected feature (e.g. zip → backups/updates, gd → thumbnails,
  intl → i18n, curl → updates/AI/mail).
- PHP version, SAPI, memory_limit, upload limits, max_execution_time, OPcache status/version/hit rate/memory,
  DB server type and version, charset/collation, CMS and schema version, writable directory checks.
- Button "Systembericht kopieren" (plain text, never includes secrets, keys or passwords).

══════════ Cross-cutting requirements ══════════
- Register every new admin page through AdminNavigationService (existing plugin menu API unchanged).
- New capabilities where needed (e.g. manage_trash, manage_system, view_editorial_calendar,
  manage_translations, manage_search_index, import_export, manage_forms); admin gets all by default.
- MultiSite: every list, export, index and statistic respects site_id and the site switcher.
- CSRF on every state change, escaped output, PDO prepared statements, no raw exceptions in responses.
- No nested forms, no inline event handlers, CSP-compliant JS with nonces, no native alert/confirm dialogs.
- Long-running jobs: batched, resumable, locked, visible progress; never one blocking request.
- Audit log for all destructive or security-relevant actions.
- All strings in CMS/lang/de.yaml and en.yaml.
- Docs per work package: DOC/admin/* for each new page, plus updates to INDEX.md, FILELIST.md,
  CMSFILESTRUCTUR.md, DATABASE-SCHEMA.md and HOOKS-REFERENCE.md.

Constraints:
- Do not change how plugins hook into the CMS or the admin menu.
- Do not invent a second event name for an existing hook contract.
- Keep all existing routes and URLs unchanged.
- No new heavy dependencies; use bundled libraries under CMS/assets/.
- Hide empty UI sections instead of placeholder text.

Deliverables per work package:
- Phase 0 report, implementation, migration (if needed), docs, language files, release files
- Short test plan covering fresh install, update from the previous version and MultiSite with two sites
```
