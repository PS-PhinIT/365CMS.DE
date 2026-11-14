# 365CMS – Projektdokumentation | Abschnitt: Community – Datenmodell
> **Stand:** 2026-11-14 | **Ziel-Version:** 4.1 (Vorschlag) · Schema **v26** | **Status:** Planung | **Übersicht:** [README.md](README.md)

## English (summary)

Database design of the Community module: 27 tables with prefix `community_`, created by
`Community\Database\Installer` through `SchemaManager` (schema v26, idempotent, one definition shared by fresh
install, update and runtime check). It lists columns, keys and indexes, denormalised counters and their maintenance,
status values, what is reused from existing core tables (users, roles, user_groups, notifications, messages,
favorites, mail_queue) and the column-level mapping from the `cms-forum` plugin tables (`{prefix}cmsforum_*`).

## Deutsch

### 1. Grundsätze

- Präfix **`{prefix}community_`**; Engine InnoDB, `utf8mb4_unicode_ci`; alle IDs `INT UNSIGNED` bzw. `BIGINT UNSIGNED` bei sehr großen Tabellen (Beiträge, Reaktionen, Lesestatus, Ereignisse).
- Zeiten als `DATETIME` in UTC (Ausgabe in der Zeitzone der Site), nicht `TIMESTAMP` (Jahr-2038-Grenze, keine automatische Zeitzone).
- Strukturierte Werte als `LONGTEXT` in Spalten `*_json` (Core-Konvention wie `form_forms.fields_json`), nicht als Typ `JSON` (MariaDB-Kompatibilität).
- Keine Fremdschlüssel-Constraints (wie im übrigen Core); Konsistenz über Dienste und den Reparatur-Job.
- **Keine Site-Spalten** ([KLARSTELLUNGEN.md](../KLARSTELLUNGEN.md) §3).
- Weiches Löschen über `status`, endgültiges Löschen nur über Papierkorb, Löschfristen oder DSGVO.
- Jede Tabelle in einer einzigen Definition (`Installer::tables()`), genutzt von Neuinstallation, Update und Laufzeitprüfung (wie `admin_menu_layouts` in v25).
- Benutzer, Rollen, Gruppen, Benachrichtigungen, Nachrichten, Favoriten und Mails bleiben in den **Core-Tabellen**; das Modul legt dafür keine eigenen Tabellen an.

### 2. Übersicht

| Bereich | Tabellen |
|---|---|
| Struktur | `community_categories`, `community_forums`, `community_forum_permissions`, `community_forum_moderators` |
| Inhalte | `community_topics`, `community_posts`, `community_post_revisions`, `community_attachments`, `community_drafts` |
| Interaktion | `community_reactions`, `community_subscriptions`, `community_read_states`, `community_forum_read_marks`, `community_polls`, `community_poll_options`, `community_poll_votes`, `community_ignores` |
| Mitglieder | `community_user_stats`, `community_ranks`, `community_badges`, `community_user_badges` |
| Moderation | `community_reports`, `community_warnings`, `community_bans`, `community_moderation_log` |
| Betrieb | `community_stats_daily`, `community_redirects` |

Einstellungen stehen in der Core-Tabelle `settings` (Gruppe `community`), nicht in einer eigenen Tabelle (Plugin: `cmsforum_settings`).

### 3. Tabellen

#### 3.1 Struktur

```sql
CREATE TABLE {p}community_categories (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(150) NOT NULL, title_en VARCHAR(150) NULL,
  slug VARCHAR(160) NOT NULL, slug_en VARCHAR(160) NULL,
  description TEXT NULL, description_en TEXT NULL,
  sort_order INT UNSIGNED NOT NULL DEFAULT 0,
  is_visible TINYINT(1) NOT NULL DEFAULT 1,
  legacy_id INT UNSIGNED NULL,                       -- cmsforum_categories.id
  created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
  UNIQUE KEY uq_slug (slug), UNIQUE KEY uq_legacy (legacy_id), KEY idx_sort (sort_order)
);

CREATE TABLE {p}community_forums (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  category_id INT UNSIGNED NOT NULL,
  parent_id INT UNSIGNED NULL,                       -- Unterforen (Baum)
  depth TINYINT UNSIGNED NOT NULL DEFAULT 0,          -- 0 = direkt in der Kategorie
  path VARCHAR(255) NOT NULL DEFAULT '',              -- materialisierter Pfad '/3/17/' für Unterbaum-Abfragen
  title VARCHAR(150) NOT NULL, title_en VARCHAR(150) NULL,
  slug VARCHAR(160) NOT NULL, slug_en VARCHAR(160) NULL,
  description TEXT NULL, description_en TEXT NULL,
  icon VARCHAR(50) NULL, color VARCHAR(9) NULL,
  type VARCHAR(20) NOT NULL DEFAULT 'discussion',     -- discussion | qa | announcements | link
  link_url VARCHAR(500) NULL,                         -- nur type = link
  status VARCHAR(20) NOT NULL DEFAULT 'open',         -- open | readonly | archived | hidden
  require_approval TINYINT(1) NOT NULL DEFAULT 0,
  slow_mode_seconds INT UNSIGNED NOT NULL DEFAULT 0,
  auto_close_days INT UNSIGNED NOT NULL DEFAULT 0,
  inherit_permissions TINYINT(1) NOT NULL DEFAULT 1,  -- Rechte vom Elternforum
  sort_order INT UNSIGNED NOT NULL DEFAULT 0,
  topic_count INT UNSIGNED NOT NULL DEFAULT 0,        -- sichtbare Themen (ohne Unterforen)
  post_count INT UNSIGNED NOT NULL DEFAULT 0,
  last_post_id BIGINT UNSIGNED NULL, last_post_at DATETIME NULL,
  last_topic_id INT UNSIGNED NULL, last_poster_id INT UNSIGNED NULL,
  version INT UNSIGNED NOT NULL DEFAULT 1,            -- Cache-Schlüssel, steigt bei jeder Änderung
  legacy_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
  UNIQUE KEY uq_slug (slug), UNIQUE KEY uq_legacy (legacy_id),
  KEY idx_category (category_id, parent_id, sort_order), KEY idx_path (path)
);

CREATE TABLE {p}community_forum_permissions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  forum_id INT UNSIGNED NOT NULL,
  subject_type VARCHAR(10) NOT NULL,                  -- guest | role | group | trust
  subject_key VARCHAR(64) NOT NULL,                   -- '' bei guest, Rollen-Slug, Gruppen-ID, Stufe 0–4
  permissions_json LONGTEXT NOT NULL,                 -- {"read":1,"create_topic":1,"reply":1,…} – Schlüssel s. PERMISSIONS-MODERATION §3
  updated_at DATETIME NOT NULL,
  UNIQUE KEY uq_subject (forum_id, subject_type, subject_key)
);

CREATE TABLE {p}community_forum_moderators (
  forum_id INT UNSIGNED NOT NULL,
  subject_type VARCHAR(10) NOT NULL,                  -- user | group
  subject_id INT UNSIGNED NOT NULL,
  include_children TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (forum_id, subject_type, subject_id), KEY idx_subject (subject_type, subject_id)
);
```

#### 3.2 Inhalte

```sql
CREATE TABLE {p}community_topics (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  forum_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NULL,                          -- NULL = gelöschtes Mitglied (anonymisiert)
  title VARCHAR(200) NOT NULL,
  slug VARCHAR(210) NOT NULL,                         -- nur für die URL, Auflösung über id
  status VARCHAR(20) NOT NULL DEFAULT 'published',    -- published | pending | hidden | deleted | trash | moved
  moved_to_topic_id INT UNSIGNED NULL,                -- status = moved (Verweis nach Verschieben/Zusammenführen)
  moved_expires_at DATETIME NULL,
  is_pinned TINYINT(1) NOT NULL DEFAULT 0, pinned_until DATETIME NULL,
  is_announcement TINYINT(1) NOT NULL DEFAULT 0,
  is_locked TINYINT(1) NOT NULL DEFAULT 0, locked_until DATETIME NULL,
  slow_mode_seconds INT UNSIGNED NOT NULL DEFAULT 0,
  prefix VARCHAR(30) NULL,
  first_post_id BIGINT UNSIGNED NULL,
  solution_post_id BIGINT UNSIGNED NULL, solved_at DATETIME NULL, solved_by INT UNSIGNED NULL,
  has_poll TINYINT(1) NOT NULL DEFAULT 0, has_attachments TINYINT(1) NOT NULL DEFAULT 0,
  reply_count INT UNSIGNED NOT NULL DEFAULT 0,        -- sichtbare Antworten
  view_count INT UNSIGNED NOT NULL DEFAULT 0,         -- gepuffert (alle 5 min verbucht)
  reaction_count INT UNSIGNED NOT NULL DEFAULT 0,
  last_post_id BIGINT UNSIGNED NULL, last_post_at DATETIME NULL, last_poster_id INT UNSIGNED NULL,
  version INT UNSIGNED NOT NULL DEFAULT 1,
  trashed_at DATETIME NULL, previous_status VARCHAR(20) NULL,   -- Papierkorb
  legacy_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
  UNIQUE KEY uq_legacy (legacy_id),
  KEY idx_forum_list (forum_id, status, is_pinned, last_post_at),
  KEY idx_announce (is_announcement, status),
  KEY idx_user (user_id, created_at),
  KEY idx_activity (status, last_post_at),
  KEY idx_unanswered (forum_id, status, reply_count, created_at)
);

CREATE TABLE {p}community_posts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  topic_id INT UNSIGNED NOT NULL,
  forum_id INT UNSIGNED NOT NULL,                     -- denormalisiert (Rechte, Statistik, Suche)
  user_id INT UNSIGNED NULL,
  is_first_post TINYINT(1) NOT NULL DEFAULT 0,
  status VARCHAR(20) NOT NULL DEFAULT 'published',    -- published | pending | hidden | deleted | rejected
  status_reason VARCHAR(255) NULL,                    -- Grund für hidden/rejected (für Autor sichtbar)
  format VARCHAR(10) NOT NULL DEFAULT 'markdown',     -- markdown | bbcode (Altbestand, E6)
  body MEDIUMTEXT NOT NULL,                           -- Quelle
  body_html MEDIUMTEXT NULL,                          -- gerendert + gereinigt (Cache)
  rendered_version SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  quote_of_post_id BIGINT UNSIGNED NULL,
  edit_count INT UNSIGNED NOT NULL DEFAULT 0,
  edited_at DATETIME NULL, edited_by INT UNSIGNED NULL, edit_reason VARCHAR(255) NULL,
  reaction_count INT UNSIGNED NOT NULL DEFAULT 0,
  ip_hash CHAR(64) NULL,                              -- HMAC der gekürzten IP, nur Moderation, Löschfrist
  legacy_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
  UNIQUE KEY uq_legacy (legacy_id),
  KEY idx_topic (topic_id, status, id),
  KEY idx_user (user_id, created_at),
  KEY idx_queue (status, created_at),
  KEY idx_forum_time (forum_id, created_at)
);

CREATE TABLE {p}community_post_revisions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  post_id BIGINT UNSIGNED NOT NULL,
  title VARCHAR(200) NULL,                            -- nur erster Beitrag
  body MEDIUMTEXT NOT NULL, format VARCHAR(10) NOT NULL,
  edited_by INT UNSIGNED NULL, edit_reason VARCHAR(255) NULL,
  created_at DATETIME NOT NULL,
  KEY idx_post (post_id, created_at)
);

CREATE TABLE {p}community_attachments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  post_id BIGINT UNSIGNED NULL,                       -- NULL bis zum Absenden (verwaist → Löschfrist 24 h)
  user_id INT UNSIGNED NULL,
  storage_path VARCHAR(255) NOT NULL,                 -- relativ in PrivateStorageService-Bereich 'community'
  original_name VARCHAR(255) NOT NULL,
  mime_type VARCHAR(100) NOT NULL, extension VARCHAR(10) NOT NULL,
  file_size INT UNSIGNED NOT NULL,
  sha256 CHAR(64) NOT NULL,
  width SMALLINT UNSIGNED NULL, height SMALLINT UNSIGNED NULL, has_preview TINYINT(1) NOT NULL DEFAULT 0,
  download_count INT UNSIGNED NOT NULL DEFAULT 0,
  upload_token CHAR(32) NULL,                         -- Zuordnung vor dem Absenden
  legacy_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  UNIQUE KEY uq_legacy (legacy_id), KEY idx_post (post_id), KEY idx_orphans (post_id, created_at), KEY idx_user (user_id)
);

CREATE TABLE {p}community_drafts (
  user_id INT UNSIGNED NOT NULL,
  target_type VARCHAR(10) NOT NULL,                   -- forum (neues Thema) | topic (Antwort)
  target_id INT UNSIGNED NOT NULL,
  title VARCHAR(200) NULL, body MEDIUMTEXT NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (user_id, target_type, target_id), KEY idx_updated (updated_at)
);
```

#### 3.3 Interaktion

```sql
CREATE TABLE {p}community_reactions (
  post_id BIGINT UNSIGNED NOT NULL, user_id INT UNSIGNED NOT NULL,
  reaction VARCHAR(20) NOT NULL DEFAULT 'like',       -- Schlüssel aus der Admin-Auswahl
  created_at DATETIME NOT NULL,
  PRIMARY KEY (post_id, user_id), KEY idx_user (user_id, created_at)
);

CREATE TABLE {p}community_subscriptions (
  user_id INT UNSIGNED NOT NULL,
  target_type VARCHAR(10) NOT NULL,                   -- topic | forum
  target_id INT UNSIGNED NOT NULL,
  mode VARCHAR(10) NOT NULL DEFAULT 'instant',        -- instant | daily | weekly | none (nur In-App)
  notified_at DATETIME NULL,                          -- keine zweite Mail bis zum nächsten Besuch
  created_at DATETIME NOT NULL,
  PRIMARY KEY (user_id, target_type, target_id), KEY idx_target (target_type, target_id)
);

CREATE TABLE {p}community_read_states (
  user_id INT UNSIGNED NOT NULL, topic_id INT UNSIGNED NOT NULL,
  last_read_post_id BIGINT UNSIGNED NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (user_id, topic_id), KEY idx_cleanup (updated_at)
);

CREATE TABLE {p}community_forum_read_marks (           -- „alles bis hier gelesen“ je Forum (und global: forum_id = 0)
  user_id INT UNSIGNED NOT NULL, forum_id INT UNSIGNED NOT NULL,
  marked_at DATETIME NOT NULL,
  PRIMARY KEY (user_id, forum_id)
);

CREATE TABLE {p}community_polls (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  topic_id INT UNSIGNED NOT NULL,
  question VARCHAR(255) NOT NULL,
  max_choices TINYINT UNSIGNED NOT NULL DEFAULT 1,
  results_visibility VARCHAR(10) NOT NULL DEFAULT 'after_vote', -- always | after_vote | after_end
  allow_change TINYINT(1) NOT NULL DEFAULT 1,
  is_public_voters TINYINT(1) NOT NULL DEFAULT 0,
  ends_at DATETIME NULL, voter_count INT UNSIGNED NOT NULL DEFAULT 0,
  legacy_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  UNIQUE KEY uq_topic (topic_id), UNIQUE KEY uq_legacy (legacy_id)
);
CREATE TABLE {p}community_poll_options (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  poll_id INT UNSIGNED NOT NULL, label VARCHAR(200) NOT NULL,
  vote_count INT UNSIGNED NOT NULL DEFAULT 0, sort_order TINYINT UNSIGNED NOT NULL DEFAULT 0,
  legacy_id INT UNSIGNED NULL,
  KEY idx_poll (poll_id, sort_order)
);
CREATE TABLE {p}community_poll_votes (
  poll_id INT UNSIGNED NOT NULL, option_id INT UNSIGNED NOT NULL, user_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (poll_id, user_id, option_id), KEY idx_option (option_id)
);

CREATE TABLE {p}community_ignores (
  user_id INT UNSIGNED NOT NULL, ignored_user_id INT UNSIGNED NOT NULL, created_at DATETIME NOT NULL,
  PRIMARY KEY (user_id, ignored_user_id)
);
```

#### 3.4 Mitglieder

```sql
CREATE TABLE {p}community_user_stats (
  user_id INT UNSIGNED PRIMARY KEY,
  topic_count INT UNSIGNED NOT NULL DEFAULT 0, post_count INT UNSIGNED NOT NULL DEFAULT 0,
  reactions_received INT UNSIGNED NOT NULL DEFAULT 0, reactions_given INT UNSIGNED NOT NULL DEFAULT 0,
  solutions_count INT UNSIGNED NOT NULL DEFAULT 0,
  reputation INT NOT NULL DEFAULT 0,
  trust_level TINYINT UNSIGNED NOT NULL DEFAULT 0, trust_level_locked TINYINT(1) NOT NULL DEFAULT 0,
  topics_read INT UNSIGNED NOT NULL DEFAULT 0, days_visited INT UNSIGNED NOT NULL DEFAULT 0,
  rank_id INT UNSIGNED NULL, custom_title VARCHAR(100) NULL,
  signature TEXT NULL, signature_html TEXT NULL,
  settings_json LONGTEXT NULL,                        -- Auto-Abo, Online-Sichtbarkeit, Editor-Vorlieben
  last_post_at DATETIME NULL, last_seen_at DATETIME NULL,
  updated_at DATETIME NOT NULL,
  KEY idx_posts (post_count), KEY idx_reputation (reputation)
);

CREATE TABLE {p}community_ranks (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(80) NOT NULL, title_en VARCHAR(80) NULL,
  min_posts INT UNSIGNED NULL,                        -- NULL = nur manuell
  color VARCHAR(9) NULL, icon VARCHAR(50) NULL,
  sort_order INT UNSIGNED NOT NULL DEFAULT 0,
  legacy_id INT UNSIGNED NULL,
  KEY idx_min_posts (min_posts)
);

CREATE TABLE {p}community_badges (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  slug VARCHAR(60) NOT NULL, title VARCHAR(80) NOT NULL, title_en VARCHAR(80) NULL,
  description VARCHAR(255) NULL, icon VARCHAR(50) NULL, tier VARCHAR(10) NOT NULL DEFAULT 'bronze',
  rule_json LONGTEXT NULL,                            -- automatische Regel, NULL = manuell
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uq_slug (slug)
);
CREATE TABLE {p}community_user_badges (
  user_id INT UNSIGNED NOT NULL, badge_id INT UNSIGNED NOT NULL,
  granted_by INT UNSIGNED NULL, reason VARCHAR(255) NULL, granted_at DATETIME NOT NULL,
  PRIMARY KEY (user_id, badge_id)
);
```

#### 3.5 Moderation

```sql
CREATE TABLE {p}community_reports (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  target_type VARCHAR(10) NOT NULL,                   -- post | topic | user
  target_id BIGINT UNSIGNED NOT NULL,
  forum_id INT UNSIGNED NULL,                         -- für Rechte der Forum-Moderatoren
  reporter_id INT UNSIGNED NULL,
  reason VARCHAR(20) NOT NULL,                        -- spam | abuse | offtopic | illegal | other
  message TEXT NULL,
  status VARCHAR(12) NOT NULL DEFAULT 'open',         -- open | valid | invalid | resolved
  resolution_note TEXT NULL, notify_reporter TINYINT(1) NOT NULL DEFAULT 1,
  handled_by INT UNSIGNED NULL, handled_at DATETIME NULL,
  legacy_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  KEY idx_open (status, created_at), KEY idx_target (target_type, target_id), KEY idx_forum (forum_id, status)
);

CREATE TABLE {p}community_warnings (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL, issued_by INT UNSIGNED NULL,
  post_id BIGINT UNSIGNED NULL,
  reason VARCHAR(255) NOT NULL, points TINYINT UNSIGNED NOT NULL DEFAULT 1,
  expires_at DATETIME NULL, revoked_at DATETIME NULL, revoked_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  KEY idx_user_active (user_id, expires_at)
);

CREATE TABLE {p}community_bans (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  scope VARCHAR(10) NOT NULL DEFAULT 'write',         -- write (nur lesen) | full (kein Zugriff)
  reason VARCHAR(255) NOT NULL, internal_note TEXT NULL,
  issued_by INT UNSIGNED NULL, starts_at DATETIME NOT NULL, ends_at DATETIME NULL,   -- NULL = dauerhaft
  lifted_at DATETIME NULL, lifted_by INT UNSIGNED NULL,
  legacy_source TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  KEY idx_user_active (user_id, lifted_at, ends_at)
);

CREATE TABLE {p}community_moderation_log (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  actor_id INT UNSIGNED NULL,
  action VARCHAR(40) NOT NULL,                        -- topic.lock, topic.move, post.hide, user.ban, report.resolve …
  target_type VARCHAR(10) NOT NULL, target_id BIGINT UNSIGNED NOT NULL,
  forum_id INT UNSIGNED NULL,
  reason VARCHAR(255) NULL, details_json LONGTEXT NULL, -- z. B. {from_forum, to_forum}
  created_at DATETIME NOT NULL,
  KEY idx_time (created_at), KEY idx_target (target_type, target_id), KEY idx_actor (actor_id, created_at)
);
```

#### 3.6 Betrieb

```sql
CREATE TABLE {p}community_stats_daily (
  day DATE NOT NULL, forum_id INT UNSIGNED NOT NULL DEFAULT 0,  -- 0 = gesamt
  topics INT UNSIGNED NOT NULL DEFAULT 0, posts INT UNSIGNED NOT NULL DEFAULT 0,
  active_members INT UNSIGNED NOT NULL DEFAULT 0, new_members INT UNSIGNED NOT NULL DEFAULT 0,
  solved INT UNSIGNED NOT NULL DEFAULT 0, median_first_reply_minutes INT UNSIGNED NULL,
  PRIMARY KEY (day, forum_id)
);

CREATE TABLE {p}community_redirects (                  -- alte Plugin-IDs/Slugs und Zusammenführungen
  source_type VARCHAR(10) NOT NULL,                   -- legacy_thread | legacy_post | legacy_forum | topic
  source_key VARCHAR(160) NOT NULL,
  target_type VARCHAR(10) NOT NULL, target_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (source_type, source_key)
);
```

### 4. Zähler und ihre Pflege

| Zähler | Ändert sich bei | Pflege |
|---|---|---|
| `forums.topic_count/post_count/last_*` | Thema/Beitrag erstellt, freigegeben, ausgeblendet, gelöscht, verschoben, zusammengeführt | `TopicService`/`PostService`/`ModerationService` in derselben Transaktion; `version++` |
| `topics.reply_count/last_*/reaction_count` | Antwort, Statuswechsel, Reaktion | dito |
| `topics.view_count` | Aufruf | gepuffert im Cache, Cron verbucht alle 5 min (keine Schreiblast je Aufruf) |
| `user_stats.*` | Beitrag, Reaktion, Lösung, Verwarnung | Dienst + nächtlicher Abgleich |
| `poll_options.vote_count`, `polls.voter_count` | Stimme | Transaktion mit `community_poll_votes` |

Reparatur: Batch-Job „Community-Zähler neu berechnen“ (`BatchJobService`) je Forum, mit Fortschritt im Admin.

### 5. Wiederverwendete Core-Tabellen

| Core-Tabelle | Nutzung |
|---|---|
| `users`, `user_meta` | Mitglieder; Avatar und Biografie aus dem Core-Profil |
| `roles`, `user_groups`, `user_group_members` | Subjekte in `community_forum_permissions` und `community_forum_moderators` |
| `notifications` | In-App-Hinweise mit Typen `community.*` (Bündelung über einen Gruppenschlüssel je Thema) |
| `messages` | Private Nachrichten (E8) |
| `favorites` | Lesezeichen (Typ `community_post`, `community_topic`) |
| `mail_queue` | Benachrichtigungs- und Zusammenfassungsmails |
| `security_log` | Abgelehnte Spam-/Captcha-Versuche, Sperren |
| `sessions` | „Wer ist online“ (ohne zusätzliche Speicherung) |
| `settings` | Gruppe `community` |
| `search_queries` | Suchstatistik (Quelle `community`) |

### 6. Zuordnung der Plugin-Tabellen (`{prefix}cmsforum_*`)

Die Tabellen heißen im Plugin `cmsforum_*` (nicht `forum_*`). Die Übernahme läuft über `legacy_id`, ein zweiter Lauf
ändert nichts. Details und Prüfungen: [MIGRATION-CMS-FORUM.md](MIGRATION-CMS-FORUM.md).

| Plugin-Tabelle | Ziel | Hinweise |
|---|---|---|
| `cmsforum_categories` | `community_categories` | `name` → `title`, `is_active` → `is_visible` |
| `cmsforum_forums` | `community_forums` | `name` → `title`, `is_locked` → `status = readonly`, `is_active = 0` → `hidden`; `depth`/`path` berechnen; `thread_count` → `topic_count` |
| `cmsforum_threads` | `community_topics` | `type` `sticky` → `is_pinned`, `announcement` → `is_announcement`; `status`/`is_locked` → `is_locked`; `accepted_post_id` → `solution_post_id` (neue ID), `accepted_at` → `solved_at` |
| `cmsforum_posts` | `community_posts` | `content` → `body`, `format = 'bbcode'`; `content_html` verwerfen und neu rendern; `is_deleted` → `deleted`, `is_approved = 0` → `pending`; `ip_address` **nicht** übernehmen (Datensparsamkeit) bzw. nur als `ip_hash` bei Beiträgen < 30 Tage; `like_count` → `reaction_count` |
| `cmsforum_likes` | `community_reactions` | `reaction = 'like'` |
| `cmsforum_subscriptions` | `community_subscriptions` | `target_type` `thread` → `topic`; `notify_email` 0 → `mode = none` |
| `cmsforum_read_tracking` | `community_read_states` | `last_read_at` → letzter Beitrag ≤ Zeitpunkt (`last_read_post_id`) |
| `cmsforum_attachments` | `community_attachments` + Dateien | Dateien in den privaten Speicher kopieren, SHA-256 berechnen, Original bleibt bis zur Abschaltung des Plugins |
| `cmsforum_polls`, `_poll_options`, `_poll_votes` | `community_polls`, `_options`, `_votes` | Options-Spalte heißt je nach Plugin-Stand `text` **oder** `option_text` (Plugin prüft beides) – Migrator ebenso; `is_public` → `is_public_voters`; `vote_count` → `voter_count` neu zählen |
| `cmsforum_reports` | `community_reports` | `post_id` → `target_type = post`; `description` → `message` |
| `cmsforum_mod_log` | `community_moderation_log` | Aktionsnamen abbilden (`thread_pin` → `topic.pin` …); `ip_address` verwerfen |
| `cmsforum_ranks` | `community_ranks` | `is_special = 1` → `min_posts = NULL` |
| `cmsforum_user_meta` | `community_user_stats` (+ `community_bans`) | `is_banned`/`ban_reason`/`ban_expires` → Eintrag in `community_bans` (`legacy_source = 1`); `location`/`website` → Core-Profilfelder, falls dort leer |
| `cmsforum_permissions` | `community_forum_permissions` | `group_type` `guest` → `subject_type = guest`; `member` → Rolle `member`; `moderator` → Moderationsrecht für Rolle `moderator` bzw. `community_forum_moderators`; Spalten `can_*` → Schlüssel in `permissions_json` |
| `cmsforum_settings` | `settings` (Gruppe `community`) | Schlüsselzuordnung in MIGRATION §4; Schlüssel ohne Wirkung im Plugin werden übernommen **und** wirksam gemacht |
