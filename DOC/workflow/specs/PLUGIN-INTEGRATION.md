# 365CMS – Projektdokumentation | Abschnitt: Workflow – Spec: Plugin-Integration (cms-feed, cms-downloads, cms-contact)
> **Stand:** 2026-11-10 | **Version:** 4.0.0 | **Status:** In Umsetzung | **Update:** 2026-11-10
> **Fortschritt:** [PROGRESS.md](PROGRESS.md) · **Verbindliche Abweichungen:** [KLARSTELLUNGEN.md](KLARSTELLUNGEN.md)

## English (summary)

Moves the plugins cms-feed, cms-downloads and cms-contact into the core (FeedService, DownloadService with Editor.js block and three templates, generic FormService) including data migration and plugin retirement. The original requirement text is reproduced unchanged below. Where it conflicts with
[KLARSTELLUNGEN.md](KLARSTELLUNGEN.md) (single 4.0.0 release, dark sidebar, no site assignment for content,
hub-site rules), the clarifications take precedence.

## Deutsch

Originaltext der Anforderung (unverändert). Bei Widersprüchen gelten die
[Klarstellungen](KLARSTELLUNGEN.md), insbesondere:

- Version bleibt 4.0.0 (statt 4.1.0), Schema-Stand v25.
- Keine Site-Dimension für Inhalte: keine `site_id` bei Downloads, keine Feeds je Site; absolute URLs über die bestehende Host-/Hub-Site-Auflösung.
- Neue Inhaltsarten (Downloads, Formulare) docken über die Inhaltstyp-Provider an Papierkorb und Export/Import an, statt Sonderfälle zu bauen.

### Originaltext

```text
[TASK TYPE: Feature / Refactor – integrate plugins into core]

Context:
- Stack: PHP 8.4.1+ / HTML / CSS / vanilla JavaScript, self-built CMS "365CMS", version 4.0.0, schema v24
- Runtime: 365CMS.DE-MAIN/CMS/ (the only loaded runtime). Plugin sources: sibling repo 365CMS.DE-PLUGINS/
  (cms-feed, cms-downloads, cms-contact). Check both locations; the runtime copy under CMS/plugins/ wins if they differ.
- Architecture rules: read DOC/DEVLIST.md, DOC/FILELIST.md, DOC/core/HOOKS-REFERENCE.md,
  DOC/core/DATABASE-SCHEMA.md and DOC/admin/README.md before changing anything.
- Relevant core pieces: FeedService, MediaService, MediaDeliveryService, MediaUsageService, FileUploadService,
  MailService, MailQueueService, AntispamService, DataRetentionService, CookieConsentService,
  AdminNavigationService, AdminNoticeService, PluginSiteAssignmentService, PostPublishChecklistService,
  SchemaManager, MigrationManager, Hooks, PublicRouter, ThemeRouter, EditorJsService, EditorJsRenderer,
  post-editor-api.php, data requests (GDPR export/deletion), CMS/lang/de.yaml + en.yaml.

Goal:
Move the complete functionality of the plugins cms-feed, cms-downloads and cms-contact into the 365CMS core,
so that every installation has them without a plugin. No feature of the plugins may be lost, existing data
and public URLs must keep working, and the plugins become obsolete. Downloads additionally get a full
management area and an Editor.js block with three design templates.

PHASE 0 – Inventory (do this first, output before coding)
- For each plugin list: routes/URLs, shortcodes or Editor.js blocks, hooks/filters registered and consumed,
  DB tables and options/settings keys, admin pages and menu entries, templates/theme overrides, assets,
  cron tasks, mails, permissions/capabilities, i18n strings.
- Map each item to its target in core (existing service to extend or new service).
- Produce a parity checklist (plugin feature → core location). Every item must be checked off at the end.

1. cms-feed → core (extend FeedService, do not create a second feed service)
- Merge all plugin features into FeedService; the plugin and the existing core /feed must not coexist.
- Keep /feed and all plugin feed URLs (redirect 301 if a plugin URL changes).
- Settings tab "Feeds" in /admin/settings: items per feed, full text vs. excerpt, include featured image,
  per-category and per-tag feeds, per-language feeds (DE/EN), per-site (MultiSite), feed on/off.
- Output: valid RSS 2.0 (optionally Atom if the plugin had it), <link rel="alternate"> autodiscovery in the theme head,
  ETag/Last-Modified + 304 handling, cache invalidation on publish/update/delete via the existing content hooks.
- Render Editor.js content through EditorJsRenderer + PurifierService, absolute URLs for links and images.

2. cms-downloads → core (managed under Medien → Downloads, embeddable via Editor.js)

2.1 Data model & service
- New core service DownloadService (+ admin module/views) built on MediaService, FileUploadService
  and MediaDeliveryService. Downloads are the single source of truth; editor blocks only reference them.
- Download entity: title, slug, short + long description, category, tags, cover image, file type (auto),
  author, license, requirements (e.g. "PowerShell 7.4+"), external links (e.g. GitHub repo / release),
  signature info (signed yes/no, signer), access level (public / logged-in / roles / member),
  site_id (MultiSite), status (draft / published / archived / trash), SEO fields.
- Versions (1:n): version number, file (media reference), release date, changelog, SHA-256 and file size
  computed server-side, status (current / archived). Exactly one current version per download.
- Custom property fields: admins define additional fields under Downloads → Einstellungen
  (types: text, textarea, number, select, url, boolean, date). Values are stored per download and can be
  shown in the frontend templates.
- "Used in" index: on content save, record which posts/pages reference a download (like MediaUsageService).
  Show it on the download edit page and warn before deleting a download that is still in use.

2.2 Delivery & statistics
- Delivery only through a controlled route (/download/{slug} and /download/{slug}/{version}) with
  realpath/root checks, correct Content-Type/Content-Disposition, Range support if the plugin had it.
  Protected files live in a blocked directory (add .htaccess rule + Nginx snippet to DOC/INSTALLATION.md).
  Never expose a direct file URL for restricted downloads.
- Counting without personal data: no raw IPs, bot/crawler filter, de-duplication per session and version
  within a configurable time window, source page recorded via a signed parameter from the block (post id only).
- Statistics page per download and overall: total, per version, per day (30 / 90 / 365 days chart),
  top downloads, top source pages, CSV export. Configurable retention for raw counter rows
  (aggregate daily, then purge via DataRetentionService).

2.3 Admin: Medien → Downloads
- List: title, current version, category, access, downloads (total / last 30 days), last update, status,
  "used in" count. Filters, search, bulk actions (status, category, access, trash).
- Edit screen with tabs: Allgemein · Versionen · Eigenschaften · Zugriff · Statistik · Verwendung · SEO.
- Overview page "Statistik" for all downloads.
- Settings: default block template, default access level, storage directory, allowed file types, max size,
  counting rules, statistics retention, custom property field definitions.

2.4 Editor.js block "Download"
- New Editor.js tool registered in the Editor.js factory, the asset registry AND the public renderer
  (all three places – see DEVLIST pitfalls).
- Inserting the block opens a dialog with two tabs:
  a) "Hochladen": upload a new file directly. This creates a full Download entity (title, version, file;
     status published or draft per setting) using the same validation as the Downloads area, then links it.
     A link "In Downloads bearbeiten" opens the full edit screen.
  b) "Aus Downloads wählen": searchable picker (filters: category, file type, access) showing title,
     current version, size and download count. Single selection; multiple selection allowed for the
     "Kompakt" template (renders a list).
- Block data stores ONLY references and display options, never file paths or URLs:
  { downloadIds: [..], version: "latest" | "<version>", template: "compact" | "card" | "featured",
    options: { showDescription, showSize, showVersion, showDate, showHash, showChangelog, showCount,
               showProperties: [fieldKeys], buttonLabel, titleOverride } }
  Rendering always uses current data, so changes in Downloads propagate to every post.
- Block settings (tune menu): template switch, version selection (latest or pinned), visibility toggles.
- Editor preview equals frontend output: render the preview through a server endpoint in
  post-editor-api.php instead of duplicating markup in JS.

2.5 Three design templates
- "Kompakt": one row per download – file type icon, title, version, size, download button.
  Designed for inline use in running text and for lists of several downloads.
- "Karte" (default): title, short description, meta row (version, date, size, download count),
  SHA-256 with copy button, selected property fields, primary button, link "Alle Versionen".
- "Hervorgehoben": large release box – cover image or file type icon, version badge
  ("Neu" / "Aktualisiert am …"), changelog excerpt of the current version, requirements, license,
  signature note, SHA-256 with copy button, primary button plus secondary actions
  (changelog, older versions, external link e.g. GitHub).
- Templates live in core as PHP partials, are overridable by themes
  (themes/<slug>/partials/download/{compact,card,featured}.php) and styled with the theme design tokens.
  Accessible markup: real <a>/<button>, visible focus, aria-labels with file name and size.
- Restricted download + unauthorized visitor: show lock icon and login/membership hint, no file URL.
- Download missing, archived or in trash: hide the block in the frontend (no placeholder),
  show a warning in the editor, and add a check to PostPublishChecklistService
  ("Beitrag verweist auf nicht verfügbare Downloads").

2.6 Frontend pages & compatibility
- Archive page (/downloads, filter by category) and single page per download (/downloads/{slug}) with
  all versions, changelog and property fields; fallback templates in cms-default.
- Existing plugin shortcodes/blocks keep rendering and are mapped to the new block on next save.
- Schema.org markup (SoftwareApplication or DigitalDocument depending on file type).

3. cms-contact → core (foundation for a generic form system)
- Build a generic FormService (form definitions + submissions) and implement the contact form as the first
  built-in form definition. Do NOT hardcode a contact-only solution – the service must later support a form builder.
- Integrate with the existing /kontakt page and theme contact.php; remove duplicate contact logic.
- Features: all plugin fields and options, server + client validation, AntispamService (honeypot/time check/rate limit),
  consent checkbox with configurable text and link to privacy page, mail to recipient(s) via MailQueueService,
  optional confirmation mail to the sender, mail templates in de/en.
- Submissions inbox in admin: list, detail, status (new/read/done), export CSV, delete, trash.
- GDPR: retention period per form via DataRetentionService, inclusion in data export/deletion requests,
  no storage of IPs beyond what the antispam check needs.

4. Migration & plugin retirement
- Schema bump v24 → v25 via SchemaManager/MigrationManager, prefix cms_, unique FK/index names.
- Idempotent data migration: copy plugin tables/options/settings/counters/submissions into the new core tables,
  map IDs, create one version per existing download file, compute missing SHA-256 values,
  keep files in place or move them safely with path checks. Re-running the migration must not duplicate data.
- On update: if a plugin is still active, migrate its data, deactivate it automatically (do not delete files),
  show an AdminNoticeService notice "now part of the core since vX.Y.Z – plugin can be removed".
- Prevent double registration of routes, hooks and classes (no "Cannot redeclare").
- Marketplace: mark the three plugins in CMS/marketplace/plugins/index.json as integrated into core (min core version),
  so they are not offered for installation on the new version.
- Remove the plugins from the plugin assignment per site (PluginSiteAssignmentService) without errors.

5. Admin menu & permissions
- Register all new pages through AdminNavigationService like other core pages:
  Inhalte → Formulare (forms + submissions), Medien → Downloads (list, statistics, settings),
  Einstellungen → Feeds.
- Capabilities: manage_forms, view_form_submissions, manage_downloads (full management incl. access rules,
  deletion, settings), upload_downloads (create downloads from the editor block and pick existing ones),
  view_download_stats, manage_feeds. Assign all to admin by default; upload_downloads also to editors.

6. Docs, i18n, release
- Add DOC/admin docs for forms, downloads (incl. Editor.js block and template overrides) and feeds; update
  DOC/INDEX.md, FILELIST.md, CMSFILESTRUCTUR.md, DATABASE-SCHEMA.md (v25), HOOKS-REFERENCE.md (new hooks),
  INSTALLATION.md (Nginx/htaccess rules), theme/THEME-DEVELOPMENT.md (download partial overrides).
- All UI strings in CMS/lang/de.yaml and en.yaml.
- Release per project rules: next minor version (4.1.0 unless taken) in Version::CURRENT, CMS/update.json,
  CMS/marketplace/core/365cms/update.json, Changelog heading "### v4.1.0 — DD.MM.YYYY",
  RELEASE/365CMS-4.1.0-{update,full}.zip with SHA-256.

Requirements:
- CSRF on every state change, escaped output, PDO prepared statements only.
- No nested forms in admin views, no inline event handlers, CSP-compliant JS with nonces.
- No raw exceptions in user-facing or JSON responses.
- Same limits in views, modules and the editor upload (file types, size, field lengths, rate limits).
- Hide empty UI sections instead of placeholder text.

Constraints:
- Do NOT change how plugins hook into the CMS or the admin menu – the plugin API stays backward compatible.
- Do not invent a second event name for an existing hook contract; reuse existing content hooks.
- Keep all existing public URLs working (redirect where necessary).
- No new heavy dependencies; use bundled libraries under CMS/assets/.
- Edit runtime files under CMS/, not only the sibling plugin repo.

Deliverables:
- Phase 0 inventory + parity checklist (all items checked at the end)
- Core services, admin modules/views/assets, routes, Editor.js block with three templates, theme fallbacks
- Migration v25 with idempotent data transfer and automatic plugin deactivation
- Updated docs, language files and release files
- Test plan: fresh install, update from 4.0.0 with plugins active, update without plugins,
  MultiSite with two sites, download block in all three templates (public and restricted download),
  upload from the editor block, contact form submission incl. retention and GDPR export
```
