# 365CMS – Projektdokumentation | Abschnitt: Community – Architektur
> **Stand:** 2026-11-14 | **Ziel-Version:** 4.2 | **Status:** Planung | **Übersicht:** [README.md](README.md)

## English (summary)

Technical design of the Community core module: directory layout under `CMS/core/Services/Community/` (same shape as
the Knowledgebase module: `Database`, `Repository`, `Service`, `Http`, `Support`), services and their
responsibilities, public and member routes, admin pages, hooks fired and consumed, how the module plugs into
existing core services (auth, roles/groups, notifications, messages, mail queue, antispam, purifier, search, trash,
retention, private storage, admin menu, member dashboard, sitemap, GDPR), the content rendering pipeline
(Markdown → HTML → Purifier → cache), caching and performance rules for large boards.

## Deutsch

### 1. Leitlinien

1. **Core-Dienste nutzen, nicht nachbauen.** Benutzer, Rollen, Gruppen, Profile, Benachrichtigungen, Nachrichten, Mails, Antispam, Suche, Papierkorb und Löschfristen kommen aus dem Core.
2. **Gleiche Form wie die Module aus 4.0.0** (Wissensdatenbank, Formulare, Downloads): Dienste unter `core/Services/Community/`, Hooks über `FeatureHooks\Registry`, Mitgliederbereich über `PluginDashboardRegistry`, Daten über `SchemaManager`/`MigrationManager`, Ablösung des Plugins über `IntegratedPluginService` + `PluginMigrator`.
3. **Rechte serverseitig an einer Stelle:** `CommunityAccess` entscheidet jede Lese- und Schreibaktion; Controller, Suche, Feeds, Benachrichtigungen und API fragen nur dort.
4. **Speichern = Quelle, Ausgabe = gerendert:** Beiträge werden als Markdown gespeichert, gerendert, gereinigt und als HTML zwischengespeichert. Ausgegeben wird nur der gereinigte Cache.
5. **Jede Einstellung hat einen Leser und einen Test** (die Plugin-Einstellungen `smileys_enabled`, `censor_words`, `auto_lock_days`, `notification_digest`, `show_online_users` waren ohne Wirkung).
6. **Keine Site-Dimension** für Inhalte ([KLARSTELLUNGEN.md](../KLARSTELLUNGEN.md) §3).

### 2. Verzeichnisse

```text
CMS/
├── core/Services/Community/
│   ├── Database/Installer.php            Tabellen (Schema v27), Standard-Rechte, Standard-Ränge
│   ├── Repository/                       reine Datenzugriffe, nur gebundene Parameter
│   │   ├── CategoryRepository.php  ForumRepository.php  TopicRepository.php  PostRepository.php
│   │   ├── ReactionRepository.php  SubscriptionRepository.php  ReadStateRepository.php
│   │   ├── ReportRepository.php  ModerationLogRepository.php  WarningRepository.php  BanRepository.php
│   │   ├── AttachmentRepository.php  PollRepository.php  RankRepository.php  BadgeRepository.php
│   ├── Service/
│   │   ├── CommunityAccess.php           Rechteentscheidung (lesen, schreiben, moderieren …) inkl. Sperren, Stufen
│   │   ├── TopicService.php  PostService.php           Erstellen, Bearbeiten, Löschen, Zähler, Ereignisse
│   │   ├── ModerationService.php         Schließen, Anpinnen, Verschieben, Zusammenführen, Teilen, Freigabe
│   │   ├── ReportService.php  WarningService.php  BanService.php  TrustLevelService.php
│   │   ├── ContentRenderer.php           Markdown/BBCode → HTML → Purifier, Erwähnungen, Zitate, Code
│   │   ├── MentionService.php  QuoteService.php
│   │   ├── NotificationDispatcher.php    Ereignis → In-App-Hinweis / Mail (sofort, Zusammenfassung)
│   │   ├── ReadStateService.php          Gelesen/Ungelesen, „erster ungelesener Beitrag“
│   │   ├── AttachmentService.php         Upload, Prüfung, Bildverarbeitung, Auslieferung
│   │   ├── PollService.php  ReactionService.php  RankService.php  BadgeService.php  ReputationService.php
│   │   ├── CommunitySearch.php           Suche mit Rechtefilter (TNTSearch-Index + SQL-Filter)
│   │   ├── CommunityStats.php            Kennzahlen, Tagesverdichtung
│   │   └── DraftService.php
│   ├── Http/
│   │   ├── PublicController.php          Startseite, Forum, Thema, Profil-Abschnitt, Suche
│   │   ├── ComposeController.php         Neues Thema, Antworten, Bearbeiten, Vorschau
│   │   ├── ModerationController.php      Aktionen im Frontend (Moderationsmenü)
│   │   ├── ApiController.php             JSON: Reaktion, Abo, Melden, Umfrage, Erwähnung-Vorschläge, Polling
│   │   └── AttachmentController.php      Auslieferung /forum/anhang/{id}
│   ├── Admin/                            Seiten-Module für /admin/community-* (wie Downloads, Formulare)
│   ├── Support/Settings.php  Support/Slugger.php  Support/I18n.php  Support/Urls.php
│   ├── CommunityContentProvider.php      Papierkorb, Suchindex, Export/Import (ContentTypeRegistry)
│   ├── CommunityMemberSection.php        Mitgliederbereich (PluginDashboardRegistry)
│   └── CommunityRetentionRules.php       Löschfristen (DataRetentionService::registerRule)
├── core/Services/FeatureHooks/CommunityHooks.php   Registrierung aller Hooks, Cron, Badges
├── core/Services/Security/CaptchaService.php        neu, gemeinsam mit Formularen/Registrierung (E7)
├── views/community/                      Frontend-Vorlagen, per Theme überschreibbar (THEME-UI §2)
├── admin/views/community/                Admin-Ansichten
├── assets/css/community.css  assets/js/community/*.js     ohne Inline-Skripte (CSP mit Nonce)
├── assets/commonmark/  assets/highlight/                  vendored Bibliotheken (E5), keine CDNs
└── lang/de.yaml, lang/en.yaml            Schlüssel community.*
```

### 3. Öffentliche Routen

Alle Routen zusätzlich unter `/en/…` mit englischen Segmenten (`topic`, `page`, `search`, `members`). Die Basis `forum`
ist einstellbar (E2). Registrierung im Hook `register_routes` aus `CommunityHooks` **vor** den Beitragsmustern
(wie `/contact/:slug` im `ThemeRouter`), damit `/forum/...` nie als Beitrag aufgelöst wird.

| Methode | Pfad (DE) | Zweck | Rechte |
|---|---|---|---|
| GET | `/forum` | Community-Startseite (Kategorien, Foren, Statistik) | lesen (gefiltert je Forum) |
| GET | `/forum/{forum-slug}[/seite-{n}]` | Themenliste eines Forums (inkl. Unterforen-Übersicht) | `read` im Forum |
| GET | `/forum/{forum-slug}/neues-thema` · POST | Thema erstellen | `create_topic` |
| GET | `/forum/thema/{id}-{slug}[/seite-{n}]` | Thema anzeigen; falscher Slug → 301 | `read` |
| GET | `/forum/thema/{id}/ungelesen` | Sprung zum ersten ungelesenen Beitrag (302 auf Seite + Anker) | `read` |
| GET | `/forum/beitrag/{id}` | Permalink eines Beitrags (302 auf Seite + Anker) | `read` |
| GET · POST | `/forum/thema/{id}/antworten` | Antworten (Vollseite, z. B. mit Zitat) | `reply` |
| GET · POST | `/forum/beitrag/{id}/bearbeiten` | Bearbeiten | Autor im Zeitfenster oder `moderate` |
| GET | `/forum/neu`, `/forum/aktiv`, `/forum/unbeantwortet` | Neue Beiträge, aktive Themen, ohne Antwort | lesen (gefiltert) |
| GET | `/forum/suche` | Suche mit Filtern | `search` (Gäste mit Rate-Limit) |
| GET | `/forum/mitglieder/{id}` | Community-Profil (Abschnitt zum Core-Profil, siehe THEME-UI §4) | lesen |
| GET | `/forum/anhang/{id}[/vorschau]` | Auslieferung eines Anhangs mit Rechteprüfung | `read` im Forum des Beitrags |
| GET | `/forum/feed`, `/forum/{forum-slug}/feed` | RSS (nur öffentlich lesbare Foren) | Gast muss lesen dürfen |
| POST | `/forum/api/reaktion`, `/abo`, `/melden`, `/umfrage`, `/vorschau`, `/entwurf`, `/moderation` | JSON-Aktionen | je Aktion, CSRF |
| GET | `/forum/api/erwaehnung?q=`, `/forum/api/aehnliche-themen?q=`, `/forum/api/neu?topic=&after=` | Vorschläge, Polling neuer Beiträge | lesen, Rate-Limit |

**Alte Plugin-Adressen** (Weiterleitungen 301, siehe [MIGRATION-CMS-FORUM.md](MIGRATION-CMS-FORUM.md) §3):
`/forum/thread/{id}` → `/forum/thema/{id}-{slug}`, `/forum/post/{id}/edit` → `/forum/beitrag/{id}/bearbeiten`,
`/forum/search` → `/forum/suche`, `/forum/user/{id}` → `/forum/mitglieder/{id}`, `/forum/{slug}/new-thread` → `/forum/{slug}/neues-thema`;
`POST /forum/api/{like,subscribe,report,poll-vote}` bleiben als Aliasse für ein Release (alte JavaScript-Caches), danach 410.

### 4. Mitgliederbereich und Admin

**Mitgliederbereich** (`CommunityMemberSection`, Slug `community`, URL `/member/plugin/community`; die alte
Theme-Adresse `/member/forum` leitet um):
Meine Themen · Meine Beiträge · Abonnements · Lesezeichen (Core-Favoriten) · Benachrichtigungen (Einstellungen je
Ereignis, verlinkt auf `/member/notifications`) · Signatur & Community-Profil · Ignorierte Mitglieder ·
Verwarnungen (eigene). Dashboard-Kachel mit ungelesenen Themen und offenen Benachrichtigungen.

**Admin** (`/admin/community-*`, Katalogeinträge in `AdminMenu\CoreCatalog`, Gruppe „Community“ in beiden Profilen):

| Seite | Route | Capability |
|---|---|---|
| Übersicht | `/admin/community` | `community.moderate` oder `community.manage` |
| Struktur (Kategorien & Foren, Drag & Drop) | `/admin/community-structure` | `community.manage` |
| Themen (Liste, Filter, Massenaktionen) | `/admin/community-topics` | `community.moderate` |
| Freigaben (Warteschlange) | `/admin/community-queue` | `community.moderate` · Badge `community_queue` |
| Meldungen | `/admin/community-reports` | `community.moderate` · Badge `community_reports` |
| Verwarnungen & Sperren | `/admin/community-sanctions` | `community.moderate` |
| Moderationsprotokoll | `/admin/community-log` | `community.moderate` |
| Rechte (Matrix Forum × Rolle/Gruppe) | `/admin/community-permissions` | `community.manage` |
| Ränge & Abzeichen | `/admin/community-ranks` | `community.manage` |
| Statistik | `/admin/community-stats` | `community.manage` |
| Einstellungen | `/admin/community-settings` | `community.manage` |
| Datenübernahme cms-forum | `/admin/community-import` | `manage_plugins` + `community.manage` |

Modul-Eintrag in `CoreModuleService::MODULES`: Schlüssel `community`, Kategorie „Inhalte & Community“,
`default_enabled = false` bei Neuinstallation (aktivierbar im Installer-Schritt Core-Module), `true` bei Updates mit
aktivem `cms-forum`.

### 5. Anbindung an Core-Dienste

| Bedarf | Core-Dienst | Nutzung |
|---|---|---|
| Registrierung, Login, Passwort, E-Mail-Bestätigung | `CmsAuthPageService`, `EmailVerificationService`, `Auth` | Links mit `redirect` zurück ins Forum; Schreibrecht nur mit bestätigter Adresse |
| Rollen, Gruppen | `roles`, `user_groups`, `user_group_members`, `RolesModule::CAPABILITY_GROUPS` | Capabilities `community.*`, Rechte je Forum für Rollen **und** Gruppen |
| Profil, Avatar, Sichtbarkeit | `MemberService` (`avatar`, `bio`, `public_profile_fields`), `user_meta` | Community-Felder (Signatur, Beitragszahl) in `community_user_stats`; Avatar aus dem Core |
| Benachrichtigungen | `notifications`, `MemberService::getNotificationPreferences()`, `/member/notifications` | Typen `community.*`; Einstellungen je Ereignis |
| Nachrichten | `MessageService` | „Nachricht senden“; Gruppen-Konversationen als Core-Erweiterung (E8) |
| Mails | `MailQueueService`, `MailService`, Cron `mail-queue` | Sofort-Mails und Zusammenfassungen, Abmeldelink signiert |
| Antispam, Grenzen | `AntispamService::evaluate()`, `Security::checkDbRateLimit()`, `security_log` | Honeypot, Mindestzeit, Link-Limit; Langsamer Modus |
| Captcha | **neu** `CaptchaService` (E7) | auch für Formulare und Registrierung |
| Reinigung | `PurifierService` (neues Profil `community`) | Ausgabe aller Nutzerinhalte |
| Uploads, Bilder | `FileUploadService`, `ImageService`, `PrivateStorageService` (Bereich `community`) | Anhänge außerhalb des Webroots |
| Suche | `SearchDocumentProvider`, TNTSearch, `SiteSearchService`, `search_queries` | Index der Themen; Rechtefilter beim Abfragen |
| Papierkorb | `TrashProvider` über `ContentTypeRegistry` | Themen (mit Beiträgen) wiederherstellbar |
| Export/Import | `ExchangeProvider` | Struktur, Rechte, Einstellungen (P2) |
| Löschfristen | `DataRetentionService::registerRule()` | Lesestatus, gekürzte IPs, Protokolle, Meldungen, verwaiste Uploads, Entwürfe |
| Admin-Hinweise | `AdminNoticeService::registerCheck()` | z. B. „Captcha-Anbieter ohne Schlüssel“, „Warteschlange älter als 24 h“ |
| Admin-Menü, Badges | `AdminMenu\CoreCatalog`, `BadgeProvider::register()` | Gruppe „Community“, Badges Meldungen/Freigaben |
| Mitgliederbereich | `PluginDashboardRegistry` | `CommunityMemberSection` |
| Cron | `CronRunnerService`, `FeatureHooks` | Zusammenfassungen, automatisches Schließen, Stufen, Statistik, Aufräumen |
| Sitemap, SEO | `SitemapService`, `SEOService` | öffentliche Foren/Themen, kanonische URLs, Schema.org |
| Feeds | `FeedService` | RSS je Forum (P2) |
| DSGVO | Hooks `dsgvo_export_data`, `dsgvo_delete_data`, `member/privacy.php` | Export, Anonymisierung/Löschung |
| Rechtstexte | Legal-Modul | Textbausteine Forum/Captcha |
| Favoriten | `favorites` | Lesezeichen (P2) |
| Zwischenspeicher | `CacheManager` | Seiten- und Fragment-Cache, Invalidierung über Ereignisse |

### 6. Hooks

**Gefeuert** (für Plugins und Themes; Namensschema `cms_community_*`):

| Hook | Typ | Daten |
|---|---|---|
| `cms_community_topic_created` / `_updated` / `_deleted` / `_moved` / `_merged` | Aktion | Thema-ID, Forum-ID, Benutzer-ID |
| `cms_community_post_created` / `_updated` / `_deleted` / `_approved` / `_rejected` | Aktion | Beitrag-ID, Thema-ID, Benutzer-ID |
| `cms_community_report_created` / `_resolved` | Aktion | Meldung-ID |
| `cms_community_user_warned` / `_banned` / `_unbanned` | Aktion | Benutzer-ID, Grund |
| `cms_community_solution_marked` | Aktion | Thema-ID, Beitrag-ID |
| `cms_community_render_post` | Filter | gereinigtes HTML (nach dem Purifier nur noch Filter, die selbst escapen) |
| `cms_community_can` | Filter | Entscheidung von `CommunityAccess` (Plugins können **einschränken**, nicht erweitern über Sperren hinaus) |
| `cms_community_spam_check` | Filter | Ergebnis der Spam-Prüfung (z. B. KI-Signal, COM-PLUS-11) |
| `cms_community_notification_types` | Filter | zusätzliche Benachrichtigungsarten |

**Genutzt** (vorhanden im Core 4.0.0): `register_routes`, `cms_register_content_type_providers`,
`member_dashboard_init`, `cms_admin_menu` (nur Kompatibilität), `head`/`body_end` (Assets), `dsgvo_export_data`,
`dsgvo_delete_data`, `user_registered`, `user_email_verified` (Vertrauensstufe 0 → 1 möglich), `user_sessions_revoked`,
Cron-Hooks über `CronRunnerService`.

**Neu im Core einzuführen** (gibt es in 4.0.0 nicht, Prüfung vom 14.11.2026): `user_deleted` in
`UserService::deleteUser()` (Anonymisierung der Beiträge, falls das Konto ohne DSGVO-Antrag gelöscht wird) und
`user_role_changed` (Rechte-Cache von `CommunityAccess` leeren). Bis dahin prüft `CommunityAccess` die Rolle bei jeder
Anfrage frisch und ein täglicher Cron-Lauf anonymisiert Beiträge gelöschter Konten.

### 7. Rendering-Pipeline

```text
Eingabe (Markdown)
  → Längen-/Formatprüfung, Wortfilter (sperren oder ersetzen)
  → speichern in community_posts.body (Quelle) + format = 'markdown'
  → ContentRenderer:
       CommonMark (+ GFM: Tabellen, Aufgaben, Durchstreichen, Autolinks), Roh-HTML aus, unsichere Links aus
       → Erwähnungen (@name → Profil-Link, nur existierende, sichtbare Mitglieder)
       → Zitate (> [quote=post:123] → Quellverweis, Rechteprüfung beim Anzeigen)
       → Code-Blöcke (Sprache aus Allowlist, Highlighting E5)
       → Bilder nur aus eigenen Anhängen bzw. erlaubten Hosts (Einstellung), loading="lazy", width/height
       → Links: rel="nofollow ugc noopener", externe mit Hinweis-Symbol
  → PurifierService::purify($html, 'community')
  → community_posts.body_html (Cache) + rendered_version
Ausgabe: nur body_html; bei geändertem rendered_version (Renderer-Update) neu rendern (Batch-Job).
BBCode-Altbeiträge (format = 'bbcode'): übernommener BBCodeParser → PurifierService → body_html.
```

Die Vorschau im Editor nutzt denselben Weg über `POST /forum/api/vorschau` (nichts wird gespeichert, Rate-Limit).

### 8. Leistung und Zwischenspeicher

- **Zähler denormalisiert:** `topic_count`, `post_count`, `last_post_id`, `last_post_at` an Forum und Thema; Pflege in derselben Transaktion wie der Beitrag; Reparatur-Job „Zähler neu berechnen“ (`BatchJobService`).
- **Indizes** für alle Listen: `(forum_id, is_pinned, last_post_at)`, `(topic_id, status, id)`, `(user_id, created_at)` – siehe [DATA-MODEL.md](DATA-MODEL.md).
- **Seiten in langen Themen:** Position eines Beitrags = Zählung über `(topic_id, status, id)`; ab Seite 50 Keyset-Abfrage (`id > letzter`) statt `OFFSET`.
- **Cache:** Gäste erhalten ganze Seiten aus dem Cache (Schlüssel enthält Forum-/Thema-Version und Sprache), Angemeldete nur Fragmente (gerenderte Beiträge, Forenbaum) – keine Vermischung personenbezogener Teile. Seiten mit Formularen/Token sind `private` (wie im Theme mit `sendCoreFormCacheHeaders`).
- **Polling** neuer Beiträge nur bei sichtbarem Tab, Intervall 60 s, Antwort mit `ETag`/304.
- **Abfragebudget:** Startseite, Forum und Thema ≤ 6 SQL-Abfragen bei warmem Cache (Test im TEST-PLAN).

### 9. Konfiguration (Einstellungsgruppe `community`)

Alle Schlüssel in `Support/Settings.php` mit Typ, Grenze und Standard; jede Einstellung hat einen Leser im Code und
einen Test. Gruppen: Allgemein · Beiträge & Editor · Rechte-Standards · Spam & Grenzen · Captcha · Anhänge ·
Benachrichtigungen · Vertrauensstufen · SEO · Darstellung. Die Übernahme der Plugin-Schlüssel steht in
[MIGRATION-CMS-FORUM.md](MIGRATION-CMS-FORUM.md) §4.
