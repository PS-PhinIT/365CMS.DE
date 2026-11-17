# 365CMS Projektdokumentation | Community: Übergabe PR #1 → PR #2 bis PR #5
> **Stand:** 2026-11-17 | **Ziel-Version:** 4.2.0 · Schema **v27** (Entwicklungsstand) | **Status:** PR #1 geliefert (Schema, Capabilities, Rolle) · PR #2 bis #5 offen | **Übersicht:** [README.md](README.md)
> **Grundlage:** [IMPLEMENTATION-PLAN.md](IMPLEMENTATION-PLAN.md) · [DATA-MODEL.md](DATA-MODEL.md) · [PERMISSIONS-MODERATION.md](PERMISSIONS-MODERATION.md) · [ARCHITECTURE.md](ARCHITECTURE.md) · [TEST-PLAN.md](TEST-PLAN.md)

## English (summary)

Hand-over briefing after **PR #1** of the 4.2.0 Community module. PR #1 delivered the database schema `v27` (27 tables `community_*`, 117 in total), the six capabilities `community.*`, the system role `moderator` and the one-time update step `capabilities_v27` (marker in `settings`, never overwrites a stored permission). No services, admin pages, member pages or templates exist yet. This document is the source of truth for the exact table, column, index and capability names and lists, per pull request, the classes, routes, hooks and tests that PR #2 (services, moderation), PR #3 (admin), PR #4 (member area, front end) and PR #5 (GDPR, tests, documentation) must build, plus the decisions already made and the open ones. Where it differs from the earlier planning documents, this document and the code win; the differences are listed in section 7.

## Deutsch

### 1. Was PR #1 geliefert hat (und was nicht)

| Bereich | Stand | Wo |
|---|---|---|
| Schema v27 | 27 Tabellen `community_*`, `SCHEMA_VERSION = 'v27'` in `SchemaManager` und `MigrationManager`, Liste `SchemaManager::FEATURE_TABLES_V27`; Neuinstallation = Update (von v25 und v26) = Laufzeitprüfung | `CMS/core/SchemaManager.php`, `CMS/core/MigrationManager.php` |
| Datenbank-Update | `DatabaseUpdateRunner::missingSchemaElements()` prüft die 27 Tabellen mit (`table:community_…`) | `CMS/core/DatabaseUpdateRunner.php` |
| Capabilities | sechs `community.*`, Standardrechte, Rolle `moderator` | `CMS/core/Services/Community/Support/CommunityCapabilities.php`, `CMS/includes/functions/roles.php`, `CMS/admin/modules/users/RolesModule.php`, `CMS/core/Services/UserService.php` |
| Update-Schritt | `capabilities_v27`: einmalig, Marker `capabilities_v27` in `settings`, überschreibt nie | `CommunityCapabilities::migrate()`, aufgerufen von `MigrationManager::run()` und `SchemaManager::ensureFeatureSchema()` |
| Tests | `TESTS/schema-v27` (ohne Datenbank), `TESTS/schema-upgrade` (optional, echte MariaDB/MySQL: Neuinstallation und Updates von 4.0.0/v25 und 4.1.8/v26 ergeben dieselbe Struktur, Rechte-Schritt inklusive) | `TESTS/` |
| **Nicht geliefert** | Services, Repositories, Controller, Routen, Hooks, Admin-Seiten, Mitgliederbereich, Vorlagen, Sprachschlüssel, Einstellungen, Löschfrist-Regeln, DSGVO-Hooks, `CoreModuleService`-Eintrag | PR #2 bis #5 |

**Versionsstand.** `Version::CURRENT` bleibt **4.1.8**, ebenso `CMS/update.json`, `CMS/marketplace/core/365cms/update.json` und `RELEASE/`: Das Manifest beschreibt das veröffentlichte Paket 4.1.8 (Schema v26). `TESTS/release-3.4.0` prüft deshalb, dass `schema_version` des Manifests der Marke **im Paket** entspricht und nie höher ist als die des SchemaManagers. Mit der Release-Vorbereitung 4.2.0 (WP-C13) wechseln `Version::CURRENT`, Manifeste, Paket und Prüfsumme gemeinsam; dann gilt wieder Gleichheit. Auf Installationen mit dem Entwicklungsstand läuft die Schema-Anhebung automatisch beim ersten Request (neuer Flag-Name, `MigrationManager::run()` von v26 auf v27, danach `ensureFeatureSchema()`); das Update-System kennt sie erst mit 4.2.0.

### 2. Festlegungen aus PR #1

1. **Alle Definitionen stehen nur in `SchemaManager::getSchemaQueries()`** (Plan §5), nicht in einem `Database/Installer`. Ein `Community\Database\Installer` (PR #2) darf höchstens Standard-Ränge und Einstellungen säen, kein DDL. Dieselbe Definition nutzen Neuinstallation, Update und Laufzeitprüfung.
2. **E6 (keine Datenübernahme aus `cms-forum`) ist umgesetzt:** keine `legacy_id`-/`legacy_source`-Spalten, kein BBCode (`format` kennt nur `markdown`), `community_redirects` bleibt (27 Tabellen, 90 + 27 = 117), aber nur für `topic` und `forum` (Zusammenführen, Umbenennen), nicht für Plugin-Adressen.
3. **Keine Fremdschlüssel** (wie v25/v26, DATA-MODEL §1): Konsistenz über die Dienste und den Reparatur-Job. Fehler von Fremdschlüsseln stünden nicht in der Ignore-Liste des `MigrationManager`.
4. **Strict-Mode:** keine `NOT NULL`-Spalte ohne Default. Textspalten (`TEXT`, `MEDIUMTEXT`, `LONGTEXT`: `description`, `body`, `body_html`, `permissions_json`, `*_json`, `message`, `internal_note` …) sind `NULL`-fähig, weil MySQL < 8.0.13 keinen Default für Text kennt: **Dienste prüfen Pflichtfelder selbst** (z. B. `body` nicht leer). Zeitspalten `DATETIME NOT NULL` haben den Default `CURRENT_TIMESTAMP` als Sicherheitsnetz; **Zeiten setzen die Dienste ausdrücklich in UTC** (`gmdate('Y-m-d H:i:s')`), weil die Datenbankverbindung die Zeitzone der Site trägt. Geprüft gegen MariaDB 10.11 im MariaDB-Standardmodus, im MySQL-8-Modus (`ONLY_FULL_GROUP_BY`, `NO_ZERO_DATE`) und mit `STRICT_ALL_TABLES`: Ein leeres `INSERT … () VALUES ()` gelingt in jeder Tabelle.
5. **Indexnamen** folgen v26: `uq_community_<tabelle>_<zweck>` und `idx_community_<tabelle>_<zweck>`, im ganzen Schema eindeutig und höchstens 64 Zeichen; keine Indexspalte über 767 Byte (Präfix `path(191)`), also auch ohne DYNAMIC-Zeilenformat gültig. Die Indizes bedienen die Abfragen aus DATA-MODEL, ARCHITECTURE §8 und die Löschfristen (Spalte „Zweck“ unten). Wer eine neue Abfrage braucht, ergänzt den Index in `getSchemaQueries()` **und** (für bestehende v27-Installationen aus der Entwicklung) per `ALTER` in `ensureFeatureSchema()`, mit Test in `TESTS/schema-v27`.
6. **Schema-Änderungen nach PR #1** sind bis zum Release 4.2.0 möglich, ohne die Marke zu erhöhen (neue Tabellen: `CREATE TABLE IF NOT EXISTS` greift über `ensureFeatureSchema()`, die Laufzeit-Flag hängt am Hash von `SchemaManager.php`). Neue Spalten an v27-Tabellen brauchen `ensureColumnExists()` in `ensureFeatureSchema()` (und ein `ALTER` im `MigrationManager`, falls sie auch Installationen von v25/v26 betreffen), plus Eintrag in `SchemaManager::REQUIRED_COLUMNS`, damit das Datenbank-Update sie prüft.
7. **v27 ändert keine Bestandstabelle.** Insbesondere hat `notifications` weiterhin keine Gruppen- oder Zählerspalte (`id`, `user_id`, `type`, `title`, `message`, `url`, `is_read`, `read_at`, `created_at`): Die Bündelung („3 neue Antworten in …“) löst der `NotificationDispatcher` ohne neue Spalte, z. B. über den ungelesenen Eintrag mit gleichem `user_id`, `type` und `url`, dessen `title`/`message` er fortschreibt.
8. **`/admin` erreicht weiterhin nur die Rolle `admin`** (`Auth::isAdmin()`; `AdminRouter`). `DelegatedAdminAccess::PAGES` öffnet bisher nur Zahlungen, Rechnungen, Block-Vorlagen und Webhooks. Die Rolle `moderator` hat `community.moderate`, kommt damit aber nicht in `/admin`: Ihre Oberfläche ist das Frontend bzw. der Mitgliederbereich (PR #4). Offene Entscheidung dazu: Abschnitt 7.

### 3. Schema v27

27 Tabellen mit Präfix `{prefix}community_` (Standard `cms_community_…`); Engine InnoDB, Kollation `utf8mb4_unicode_ci`. Einstellungen liegen in `settings` (Gruppe `community`), nicht in einer Tabelle. Die Spalten- und Indexlisten wurden aus einer echten MariaDB-Installation erzeugt; `TESTS/schema-v27` vergleicht sie mit den Definitionen. Die Wertebereiche der Statusfelder stehen im Text je Tabelle (keine Datenbank-ENUMs, wie `posts.status` seit v26).

Pflege der Zähler (DATA-MODEL §4): `forums.topic_count/post_count/last_*` und `topics.reply_count/last_*` ändern die Dienste in derselben Transaktion wie den Beitrag (`version++`); `topics.view_count` wird gepuffert (Cache, Cron alle 5 Minuten); `community_user_stats` pflegen Dienst und nächtlicher Abgleich; `poll_options.vote_count` und `polls.voter_count` gehören in die Transaktion der Stimme. Reparatur: Batch-Job „Community-Zähler neu berechnen“ (`BatchJobService`).

### Struktur

#### `community_categories`

Oberste Gliederung der Startseite. `slug` eindeutig; `is_visible = 0` blendet die Kategorie samt Foren aus.

| Spalte | Typ | Eigenschaften |
|---|---|---|
| `id` | int(10) unsigned | NOT NULL, AUTO_INCREMENT |
| `title` | varchar(150) | NOT NULL, Default '' |
| `title_en` | varchar(150) | NULL |
| `slug` | varchar(160) | NOT NULL, Default '' |
| `slug_en` | varchar(160) | NULL |
| `description` | text | NULL |
| `description_en` | text | NULL |
| `sort_order` | int(10) unsigned | NOT NULL, Default 0 |
| `is_visible` | tinyint(1) | NOT NULL, Default 1 |
| `created_at` | datetime | NOT NULL, Default current_timestamp() |
| `updated_at` | datetime | NOT NULL, Default current_timestamp() |

| Schlüssel | Art | Spalten | Zweck |
|---|---|---|---|
| PRIMARY | Primärschlüssel | `id` |  |
| `idx_community_categories_sort` | Index | `is_visible`, `sort_order` | sichtbare Kategorien in Reihenfolge |
| `uq_community_categories_slug` | eindeutig | `slug` | Auflösung und Eindeutigkeit des Slugs |

#### `community_forums`

Foren und Unterforen als Baum (`parent_id`, `depth`, `path` = `/3/17/` für Unterbaum-Abfragen). `type`: `discussion` · `qa` · `announcements` · `link` (nur dann `link_url`). `status`: `open` · `readonly` · `archived` · `hidden`. Zähler (`topic_count`, `post_count`, `last_*`) denormalisiert und in derselben Transaktion gepflegt; `version` steigt bei jeder Änderung (Cache-Schlüssel). `inherit_permissions = 1`: Rechte des Elternforums.

| Spalte | Typ | Eigenschaften |
|---|---|---|
| `id` | int(10) unsigned | NOT NULL, AUTO_INCREMENT |
| `category_id` | int(10) unsigned | NOT NULL, Default 0 |
| `parent_id` | int(10) unsigned | NULL |
| `depth` | tinyint(3) unsigned | NOT NULL, Default 0 |
| `path` | varchar(255) | NOT NULL, Default '' |
| `title` | varchar(150) | NOT NULL, Default '' |
| `title_en` | varchar(150) | NULL |
| `slug` | varchar(160) | NOT NULL, Default '' |
| `slug_en` | varchar(160) | NULL |
| `description` | text | NULL |
| `description_en` | text | NULL |
| `icon` | varchar(50) | NULL |
| `color` | varchar(9) | NULL |
| `type` | varchar(20) | NOT NULL, Default 'discussion' |
| `link_url` | varchar(500) | NULL |
| `status` | varchar(20) | NOT NULL, Default 'open' |
| `require_approval` | tinyint(1) | NOT NULL, Default 0 |
| `slow_mode_seconds` | int(10) unsigned | NOT NULL, Default 0 |
| `auto_close_days` | int(10) unsigned | NOT NULL, Default 0 |
| `inherit_permissions` | tinyint(1) | NOT NULL, Default 1 |
| `sort_order` | int(10) unsigned | NOT NULL, Default 0 |
| `topic_count` | int(10) unsigned | NOT NULL, Default 0 |
| `post_count` | int(10) unsigned | NOT NULL, Default 0 |
| `last_post_id` | bigint(20) unsigned | NULL |
| `last_post_at` | datetime | NULL |
| `last_topic_id` | int(10) unsigned | NULL |
| `last_poster_id` | int(10) unsigned | NULL |
| `version` | int(10) unsigned | NOT NULL, Default 1 |
| `created_at` | datetime | NOT NULL, Default current_timestamp() |
| `updated_at` | datetime | NOT NULL, Default current_timestamp() |

| Schlüssel | Art | Spalten | Zweck |
|---|---|---|---|
| PRIMARY | Primärschlüssel | `id` |  |
| `idx_community_forums_parent` | Index | `parent_id`, `sort_order` | Unterforen eines Forums |
| `idx_community_forums_path` | Index | `path(191)` | Unterbaum per `path LIKE '/3/%'` (Präfix 191 Zeichen) |
| `idx_community_forums_tree` | Index | `category_id`, `parent_id`, `sort_order` | Foren einer Kategorie und Ebene in Reihenfolge |
| `uq_community_forums_slug` | eindeutig | `slug` | Forum über den deutschen Slug auflösen |
| `uq_community_forums_slug_en` | eindeutig | `slug_en` | Forum über den englischen Slug auflösen (mehrere NULL erlaubt) |

#### `community_forum_permissions`

Rechte-Matrix je Forum und Subjekt. `subject_type`: `guest` (`subject_key` leer) · `role` (Rollen-Slug) · `group` (Gruppen-ID) · `trust` (Stufe 0–4). `permissions_json` = `{"read":1,"create_topic":1,…}` (Schlüssel: PERMISSIONS-MODERATION §3); NULL = keine Rechte. Je Forum und Subjekt höchstens eine Zeile.

| Spalte | Typ | Eigenschaften |
|---|---|---|
| `id` | int(10) unsigned | NOT NULL, AUTO_INCREMENT |
| `forum_id` | int(10) unsigned | NOT NULL, Default 0 |
| `subject_type` | varchar(10) | NOT NULL, Default 'role' |
| `subject_key` | varchar(64) | NOT NULL, Default '' |
| `permissions_json` | longtext | NULL |
| `updated_at` | datetime | NOT NULL, Default current_timestamp() |

| Schlüssel | Art | Spalten | Zweck |
|---|---|---|---|
| PRIMARY | Primärschlüssel | `id` |  |
| `idx_community_forum_permissions_lookup` | Index | `subject_type`, `subject_key` | alle Foreneinträge der Subjekte eines Mitglieds (Rolle, Gruppen, Stufe) |
| `uq_community_forum_permissions_subject` | eindeutig | `forum_id`, `subject_type`, `subject_key` | eine Zeile je Forum und Subjekt |

#### `community_forum_moderators`

Forum-Moderatoren: `subject_type` `user` oder `group`, `include_children = 1` gilt auch für Unterforen.

| Spalte | Typ | Eigenschaften |
|---|---|---|
| `forum_id` | int(10) unsigned | NOT NULL, Default 0 |
| `subject_type` | varchar(10) | NOT NULL, Default 'user' |
| `subject_id` | int(10) unsigned | NOT NULL, Default 0 |
| `include_children` | tinyint(1) | NOT NULL, Default 1 |

| Schlüssel | Art | Spalten | Zweck |
|---|---|---|---|
| PRIMARY | Primärschlüssel | `forum_id`, `subject_type`, `subject_id` |  |
| `idx_community_forum_moderators_subject` | Index | `subject_type`, `subject_id` | Foren, die ein Mitglied oder eine Gruppe moderiert |

### Inhalte

#### `community_topics`

`status`: `published` · `pending` · `hidden` · `deleted` · `trash` · `moved` (Verweis: `moved_to_topic_id`, `moved_expires_at`). `user_id` NULL = gelöschtes Mitglied (anonymisiert). Die Adresse löst über `id` auf; `slug` dient nur der URL. `view_count` wird gepuffert (Cron verbucht alle 5 Minuten). Papierkorb: `trashed_at`, `previous_status`.

| Spalte | Typ | Eigenschaften |
|---|---|---|
| `id` | int(10) unsigned | NOT NULL, AUTO_INCREMENT |
| `forum_id` | int(10) unsigned | NOT NULL, Default 0 |
| `user_id` | int(10) unsigned | NULL |
| `title` | varchar(200) | NOT NULL, Default '' |
| `slug` | varchar(210) | NOT NULL, Default '' |
| `status` | varchar(20) | NOT NULL, Default 'published' |
| `moved_to_topic_id` | int(10) unsigned | NULL |
| `moved_expires_at` | datetime | NULL |
| `is_pinned` | tinyint(1) | NOT NULL, Default 0 |
| `pinned_until` | datetime | NULL |
| `is_announcement` | tinyint(1) | NOT NULL, Default 0 |
| `is_locked` | tinyint(1) | NOT NULL, Default 0 |
| `locked_until` | datetime | NULL |
| `slow_mode_seconds` | int(10) unsigned | NOT NULL, Default 0 |
| `prefix` | varchar(30) | NULL |
| `first_post_id` | bigint(20) unsigned | NULL |
| `solution_post_id` | bigint(20) unsigned | NULL |
| `solved_at` | datetime | NULL |
| `solved_by` | int(10) unsigned | NULL |
| `has_poll` | tinyint(1) | NOT NULL, Default 0 |
| `has_attachments` | tinyint(1) | NOT NULL, Default 0 |
| `reply_count` | int(10) unsigned | NOT NULL, Default 0 |
| `view_count` | int(10) unsigned | NOT NULL, Default 0 |
| `reaction_count` | int(10) unsigned | NOT NULL, Default 0 |
| `last_post_id` | bigint(20) unsigned | NULL |
| `last_post_at` | datetime | NULL |
| `last_poster_id` | int(10) unsigned | NULL |
| `version` | int(10) unsigned | NOT NULL, Default 1 |
| `trashed_at` | datetime | NULL |
| `previous_status` | varchar(20) | NULL |
| `created_at` | datetime | NOT NULL, Default current_timestamp() |
| `updated_at` | datetime | NOT NULL, Default current_timestamp() |

| Schlüssel | Art | Spalten | Zweck |
|---|---|---|---|
| PRIMARY | Primärschlüssel | `id` |  |
| `idx_community_topics_activity` | Index | `status`, `last_post_at` | globale Liste „Aktiv“ und „Neu“ |
| `idx_community_topics_announce` | Index | `is_announcement`, `status`, `last_post_at` | Ankündigungen oben in den Foren einer Kategorie |
| `idx_community_topics_forum_list` | Index | `forum_id`, `status`, `is_pinned`, `last_post_at` | Themenliste eines Forums: angepinnt zuerst, dann letzte Aktivität (Keyset über `last_post_at`, `id`) |
| `idx_community_topics_locked` | Index | `is_locked`, `locked_until` | Cron: befristetes Schließen aufheben |
| `idx_community_topics_moved` | Index | `status`, `moved_expires_at` | Cron: abgelaufene Verweis-Themen (`status = moved`) |
| `idx_community_topics_pinned` | Index | `is_pinned`, `pinned_until` | Cron: befristetes Anpinnen aufheben |
| `idx_community_topics_trashed` | Index | `trashed_at` | Papierkorb: abgelaufene Einträge endgültig löschen |
| `idx_community_topics_unanswered` | Index | `forum_id`, `status`, `reply_count`, `created_at` | „Unbeantwortet“ je Forum |
| `idx_community_topics_user` | Index | `user_id`, `status`, `created_at` | Themen eines Mitglieds (Profil, „Meine Themen“, Anonymisierung) |

#### `community_posts`

`status`: `published` · `pending` · `hidden` · `deleted` · `rejected` (`status_reason` für den Autor sichtbar). `body` = Markdown-Quelle, `body_html` = gereinigter Cache (neu rendern, wenn `rendered_version` älter als die Renderer-Version). `forum_id` ist denormalisiert (Rechte, Statistik, Suche). `ip_hash` = HMAC der gekürzten IP, nur für die Moderation (Löschfrist 30 Tage), nie eine Klar-IP.

| Spalte | Typ | Eigenschaften |
|---|---|---|
| `id` | bigint(20) unsigned | NOT NULL, AUTO_INCREMENT |
| `topic_id` | int(10) unsigned | NOT NULL, Default 0 |
| `forum_id` | int(10) unsigned | NOT NULL, Default 0 |
| `user_id` | int(10) unsigned | NULL |
| `is_first_post` | tinyint(1) | NOT NULL, Default 0 |
| `status` | varchar(20) | NOT NULL, Default 'published' |
| `status_reason` | varchar(255) | NULL |
| `format` | varchar(10) | NOT NULL, Default 'markdown' |
| `body` | mediumtext | NULL |
| `body_html` | mediumtext | NULL |
| `rendered_version` | smallint(5) unsigned | NOT NULL, Default 0 |
| `quote_of_post_id` | bigint(20) unsigned | NULL |
| `edit_count` | int(10) unsigned | NOT NULL, Default 0 |
| `edited_at` | datetime | NULL |
| `edited_by` | int(10) unsigned | NULL |
| `edit_reason` | varchar(255) | NULL |
| `reaction_count` | int(10) unsigned | NOT NULL, Default 0 |
| `ip_hash` | char(64) | NULL |
| `created_at` | datetime | NOT NULL, Default current_timestamp() |
| `updated_at` | datetime | NOT NULL, Default current_timestamp() |

| Schlüssel | Art | Spalten | Zweck |
|---|---|---|---|
| PRIMARY | Primärschlüssel | `id` |  |
| `idx_community_posts_created` | Index | `created_at` | Löschfrist `community.ip_hash` (30 Tage) und Statistik-Verdichtung |
| `idx_community_posts_forum_time` | Index | `forum_id`, `status`, `created_at` | neue Beiträge je Forum, gefiltert nach lesbaren Foren |
| `idx_community_posts_queue` | Index | `status`, `created_at` | Freigabe-Warteschlange (älteste zuerst) und „Neue Beiträge“ |
| `idx_community_posts_topic` | Index | `topic_id`, `status`, `id` | Beiträge eines Themas in Reihenfolge, Seitenposition über Zählung, Keyset über `id` |
| `idx_community_posts_user` | Index | `user_id`, `status`, `created_at` | Beiträge eines Mitglieds (Profil, „Meine Beiträge“, Anonymisierung) |

#### `community_post_revisions`

Bearbeitungsverlauf; `title` nur beim ersten Beitrag. Löschfrist 365 Tage, die letzte Fassung bleibt.

| Spalte | Typ | Eigenschaften |
|---|---|---|
| `id` | bigint(20) unsigned | NOT NULL, AUTO_INCREMENT |
| `post_id` | bigint(20) unsigned | NOT NULL, Default 0 |
| `title` | varchar(200) | NULL |
| `body` | mediumtext | NULL |
| `format` | varchar(10) | NOT NULL, Default 'markdown' |
| `edited_by` | int(10) unsigned | NULL |
| `edit_reason` | varchar(255) | NULL |
| `created_at` | datetime | NOT NULL, Default current_timestamp() |

| Schlüssel | Art | Spalten | Zweck |
|---|---|---|---|
| PRIMARY | Primärschlüssel | `id` |  |
| `idx_community_post_revisions_created` | Index | `created_at` | Löschfrist `community.revisions` |
| `idx_community_post_revisions_post` | Index | `post_id`, `created_at` | Verlauf eines Beitrags |

#### `community_attachments`

Anhänge; Datei im privaten Speicher (`PrivateStorageService`, Bereich `community`), `storage_path` relativ. `post_id` NULL bis zum Absenden (verwaist: Löschfrist 24 Stunden), `upload_token` ordnet vor dem Absenden zu.

| Spalte | Typ | Eigenschaften |
|---|---|---|
| `id` | int(10) unsigned | NOT NULL, AUTO_INCREMENT |
| `post_id` | bigint(20) unsigned | NULL |
| `user_id` | int(10) unsigned | NULL |
| `storage_path` | varchar(255) | NOT NULL, Default '' |
| `original_name` | varchar(255) | NOT NULL, Default '' |
| `mime_type` | varchar(100) | NOT NULL, Default '' |
| `extension` | varchar(10) | NOT NULL, Default '' |
| `file_size` | int(10) unsigned | NOT NULL, Default 0 |
| `sha256` | char(64) | NOT NULL, Default '' |
| `width` | smallint(5) unsigned | NULL |
| `height` | smallint(5) unsigned | NULL |
| `has_preview` | tinyint(1) | NOT NULL, Default 0 |
| `download_count` | int(10) unsigned | NOT NULL, Default 0 |
| `upload_token` | char(32) | NULL |
| `created_at` | datetime | NOT NULL, Default current_timestamp() |

| Schlüssel | Art | Spalten | Zweck |
|---|---|---|---|
| PRIMARY | Primärschlüssel | `id` |  |
| `idx_community_attachments_post` | Index | `post_id`, `created_at` | Anhänge eines Beitrags; verwaiste Uploads (`post_id IS NULL`, älter als 24 Stunden) |
| `idx_community_attachments_token` | Index | `upload_token` | Zuordnung beim Absenden über `upload_token` |
| `idx_community_attachments_user` | Index | `user_id`, `created_at` | Upload-Kontingent je Mitglied |

#### `community_drafts`

Server-Entwürfe, ein Entwurf je Ziel (`target_type` `forum` = neues Thema, `topic` = Antwort). Löschfrist 30 Tage.

| Spalte | Typ | Eigenschaften |
|---|---|---|
| `user_id` | int(10) unsigned | NOT NULL, Default 0 |
| `target_type` | varchar(10) | NOT NULL, Default 'topic' |
| `target_id` | int(10) unsigned | NOT NULL, Default 0 |
| `title` | varchar(200) | NULL |
| `body` | mediumtext | NULL |
| `updated_at` | datetime | NOT NULL, Default current_timestamp() |

| Schlüssel | Art | Spalten | Zweck |
|---|---|---|---|
| PRIMARY | Primärschlüssel | `user_id`, `target_type`, `target_id` |  |
| `idx_community_drafts_updated` | Index | `updated_at` | Löschfrist `community.drafts` |

### Interaktion

#### `community_reactions`

Eine Reaktion je Mitglied und Beitrag (`reaction` = Schlüssel aus der Admin-Auswahl, Standard `like`).

| Spalte | Typ | Eigenschaften |
|---|---|---|
| `post_id` | bigint(20) unsigned | NOT NULL, Default 0 |
| `user_id` | int(10) unsigned | NOT NULL, Default 0 |
| `reaction` | varchar(20) | NOT NULL, Default 'like' |
| `created_at` | datetime | NOT NULL, Default current_timestamp() |

| Schlüssel | Art | Spalten | Zweck |
|---|---|---|---|
| PRIMARY | Primärschlüssel | `post_id`, `user_id` |  |
| `idx_community_reactions_user` | Index | `user_id`, `created_at` | Reaktionen eines Mitglieds (Export, Löschung, „gegeben“) |

#### `community_subscriptions`

`target_type` `topic` oder `forum`; `mode`: `instant` · `daily` · `weekly` · `none` (nur In-App). `notified_at`: keine zweite Mail bis zum nächsten Besuch.

| Spalte | Typ | Eigenschaften |
|---|---|---|
| `user_id` | int(10) unsigned | NOT NULL, Default 0 |
| `target_type` | varchar(10) | NOT NULL, Default 'topic' |
| `target_id` | int(10) unsigned | NOT NULL, Default 0 |
| `mode` | varchar(10) | NOT NULL, Default 'instant' |
| `notified_at` | datetime | NULL |
| `created_at` | datetime | NOT NULL, Default current_timestamp() |

| Schlüssel | Art | Spalten | Zweck |
|---|---|---|---|
| PRIMARY | Primärschlüssel | `user_id`, `target_type`, `target_id` |  |
| `idx_community_subscriptions_target` | Index | `target_type`, `target_id`, `mode` | Empfänger eines Ereignisses je Thema oder Forum und Modus |

#### `community_read_states`

Letzter gelesener Beitrag je Mitglied und Thema (`last_read_post_id` nur steigend). Löschfrist 180 Tage über `updated_at`.

| Spalte | Typ | Eigenschaften |
|---|---|---|
| `user_id` | int(10) unsigned | NOT NULL, Default 0 |
| `topic_id` | int(10) unsigned | NOT NULL, Default 0 |
| `last_read_post_id` | bigint(20) unsigned | NOT NULL, Default 0 |
| `updated_at` | datetime | NOT NULL, Default current_timestamp() |

| Schlüssel | Art | Spalten | Zweck |
|---|---|---|---|
| PRIMARY | Primärschlüssel | `user_id`, `topic_id` |  |
| `idx_community_read_states_cleanup` | Index | `updated_at` | Löschfrist `community.read_states` |

#### `community_forum_read_marks`

„Alles bis hier gelesen“ je Forum; `forum_id = 0` gilt global.

| Spalte | Typ | Eigenschaften |
|---|---|---|
| `user_id` | int(10) unsigned | NOT NULL, Default 0 |
| `forum_id` | int(10) unsigned | NOT NULL, Default 0 |
| `marked_at` | datetime | NOT NULL, Default current_timestamp() |

| Schlüssel | Art | Spalten | Zweck |
|---|---|---|---|
| PRIMARY | Primärschlüssel | `user_id`, `forum_id` |  |

#### `community_polls`

Umfragen (Oberfläche ab P2): eine je Thema. `results_visibility`: `always` · `after_vote` · `after_end`.

| Spalte | Typ | Eigenschaften |
|---|---|---|
| `id` | int(10) unsigned | NOT NULL, AUTO_INCREMENT |
| `topic_id` | int(10) unsigned | NOT NULL, Default 0 |
| `question` | varchar(255) | NOT NULL, Default '' |
| `max_choices` | tinyint(3) unsigned | NOT NULL, Default 1 |
| `results_visibility` | varchar(10) | NOT NULL, Default 'after_vote' |
| `allow_change` | tinyint(1) | NOT NULL, Default 1 |
| `is_public_voters` | tinyint(1) | NOT NULL, Default 0 |
| `ends_at` | datetime | NULL |
| `voter_count` | int(10) unsigned | NOT NULL, Default 0 |
| `created_at` | datetime | NOT NULL, Default current_timestamp() |

| Schlüssel | Art | Spalten | Zweck |
|---|---|---|---|
| PRIMARY | Primärschlüssel | `id` |  |
| `idx_community_polls_ends` | Index | `ends_at` | Cron: beendete Umfragen |
| `uq_community_polls_topic` | eindeutig | `topic_id` | höchstens eine Umfrage je Thema |

#### `community_poll_options`

Antwortoptionen (`vote_count` in derselben Transaktion wie die Stimme).

| Spalte | Typ | Eigenschaften |
|---|---|---|
| `id` | int(10) unsigned | NOT NULL, AUTO_INCREMENT |
| `poll_id` | int(10) unsigned | NOT NULL, Default 0 |
| `label` | varchar(200) | NOT NULL, Default '' |
| `vote_count` | int(10) unsigned | NOT NULL, Default 0 |
| `sort_order` | tinyint(3) unsigned | NOT NULL, Default 0 |

| Schlüssel | Art | Spalten | Zweck |
|---|---|---|---|
| PRIMARY | Primärschlüssel | `id` |  |
| `idx_community_poll_options_poll` | Index | `poll_id`, `sort_order` | Optionen einer Umfrage in Reihenfolge |

#### `community_poll_votes`

Stimmen; bei Mehrfachauswahl eine Zeile je Option.

| Spalte | Typ | Eigenschaften |
|---|---|---|
| `poll_id` | int(10) unsigned | NOT NULL, Default 0 |
| `option_id` | int(10) unsigned | NOT NULL, Default 0 |
| `user_id` | int(10) unsigned | NOT NULL, Default 0 |
| `created_at` | datetime | NOT NULL, Default current_timestamp() |

| Schlüssel | Art | Spalten | Zweck |
|---|---|---|---|
| PRIMARY | Primärschlüssel | `poll_id`, `user_id`, `option_id` |  |
| `idx_community_poll_votes_option` | Index | `option_id` | Stimmen je Option |
| `idx_community_poll_votes_user` | Index | `user_id` | Stimmen eines Mitglieds (Export, Löschung) |

#### `community_ignores`

Ignorieren-Liste (P2): Beiträge ignorierter Mitglieder eingeklappt, keine Hinweise von ihnen; Moderation ausgenommen.

| Spalte | Typ | Eigenschaften |
|---|---|---|
| `user_id` | int(10) unsigned | NOT NULL, Default 0 |
| `ignored_user_id` | int(10) unsigned | NOT NULL, Default 0 |
| `created_at` | datetime | NOT NULL, Default current_timestamp() |

| Schlüssel | Art | Spalten | Zweck |
|---|---|---|---|
| PRIMARY | Primärschlüssel | `user_id`, `ignored_user_id` |  |
| `idx_community_ignores_ignored` | Index | `ignored_user_id` | wer ignoriert ein Mitglied (Hinweise unterdrücken) |

### Mitglieder

#### `community_user_stats`

Zähler, Vertrauensstufe (`trust_level` 0–4, `trust_level_locked` = von der Moderation festgesetzt), Rang, Signatur, Einstellungen (`settings_json`: Auto-Abo, Online-Sichtbarkeit, Editor). Eine Zeile je Mitglied.

| Spalte | Typ | Eigenschaften |
|---|---|---|
| `user_id` | int(10) unsigned | NOT NULL, Default 0 |
| `topic_count` | int(10) unsigned | NOT NULL, Default 0 |
| `post_count` | int(10) unsigned | NOT NULL, Default 0 |
| `reactions_received` | int(10) unsigned | NOT NULL, Default 0 |
| `reactions_given` | int(10) unsigned | NOT NULL, Default 0 |
| `solutions_count` | int(10) unsigned | NOT NULL, Default 0 |
| `reputation` | int(11) | NOT NULL, Default 0 |
| `trust_level` | tinyint(3) unsigned | NOT NULL, Default 0 |
| `trust_level_locked` | tinyint(1) | NOT NULL, Default 0 |
| `topics_read` | int(10) unsigned | NOT NULL, Default 0 |
| `days_visited` | int(10) unsigned | NOT NULL, Default 0 |
| `rank_id` | int(10) unsigned | NULL |
| `custom_title` | varchar(100) | NULL |
| `signature` | text | NULL |
| `signature_html` | text | NULL |
| `settings_json` | longtext | NULL |
| `last_post_at` | datetime | NULL |
| `last_seen_at` | datetime | NULL |
| `updated_at` | datetime | NOT NULL, Default current_timestamp() |

| Schlüssel | Art | Spalten | Zweck |
|---|---|---|---|
| PRIMARY | Primärschlüssel | `user_id` |  |
| `idx_community_user_stats_posts` | Index | `post_count` | Rangvergabe und Bestenlisten nach Beiträgen |
| `idx_community_user_stats_reputation` | Index | `reputation` | Bestenlisten nach Reputation (P3) |
| `idx_community_user_stats_trust` | Index | `trust_level`, `trust_level_locked` | nächtliche Neuberechnung der Vertrauensstufen |

#### `community_ranks`

Ränge nach Beitragszahl (`min_posts`) oder manuell (`min_posts` NULL).

| Spalte | Typ | Eigenschaften |
|---|---|---|
| `id` | int(10) unsigned | NOT NULL, AUTO_INCREMENT |
| `title` | varchar(80) | NOT NULL, Default '' |
| `title_en` | varchar(80) | NULL |
| `min_posts` | int(10) unsigned | NULL |
| `color` | varchar(9) | NULL |
| `icon` | varchar(50) | NULL |
| `sort_order` | int(10) unsigned | NOT NULL, Default 0 |

| Schlüssel | Art | Spalten | Zweck |
|---|---|---|---|
| PRIMARY | Primärschlüssel | `id` |  |
| `idx_community_ranks_min_posts` | Index | `min_posts` | Rang zu einer Beitragszahl bestimmen |
| `idx_community_ranks_sort` | Index | `sort_order` | Ränge in Reihenfolge |

#### `community_badges`

Abzeichen (Oberfläche ab P3); `rule_json` NULL = manuell vergeben.

| Spalte | Typ | Eigenschaften |
|---|---|---|
| `id` | int(10) unsigned | NOT NULL, AUTO_INCREMENT |
| `slug` | varchar(60) | NOT NULL, Default '' |
| `title` | varchar(80) | NOT NULL, Default '' |
| `title_en` | varchar(80) | NULL |
| `description` | varchar(255) | NULL |
| `icon` | varchar(50) | NULL |
| `tier` | varchar(10) | NOT NULL, Default 'bronze' |
| `rule_json` | longtext | NULL |
| `is_active` | tinyint(1) | NOT NULL, Default 1 |

| Schlüssel | Art | Spalten | Zweck |
|---|---|---|---|
| PRIMARY | Primärschlüssel | `id` |  |
| `uq_community_badges_slug` | eindeutig | `slug` | Abzeichen über den Slug auflösen |

#### `community_user_badges`

Vergebene Abzeichen.

| Spalte | Typ | Eigenschaften |
|---|---|---|
| `user_id` | int(10) unsigned | NOT NULL, Default 0 |
| `badge_id` | int(10) unsigned | NOT NULL, Default 0 |
| `granted_by` | int(10) unsigned | NULL |
| `reason` | varchar(255) | NULL |
| `granted_at` | datetime | NOT NULL, Default current_timestamp() |

| Schlüssel | Art | Spalten | Zweck |
|---|---|---|---|
| PRIMARY | Primärschlüssel | `user_id`, `badge_id` |  |
| `idx_community_user_badges_badge` | Index | `badge_id` | Inhaber eines Abzeichens |

### Moderation

#### `community_reports`

`target_type`: `post` · `topic` · `user`. `reason`: `spam` · `abuse` · `offtopic` · `illegal` · `other`. `status`: `open` · `valid` · `invalid` · `resolved`. `forum_id` für die Rechte der Forum-Moderatoren. Löschfrist für erledigte Meldungen 365 Tage.

| Spalte | Typ | Eigenschaften |
|---|---|---|
| `id` | int(10) unsigned | NOT NULL, AUTO_INCREMENT |
| `target_type` | varchar(10) | NOT NULL, Default 'post' |
| `target_id` | bigint(20) unsigned | NOT NULL, Default 0 |
| `forum_id` | int(10) unsigned | NULL |
| `reporter_id` | int(10) unsigned | NULL |
| `reason` | varchar(20) | NOT NULL, Default 'other' |
| `message` | text | NULL |
| `status` | varchar(12) | NOT NULL, Default 'open' |
| `resolution_note` | text | NULL |
| `notify_reporter` | tinyint(1) | NOT NULL, Default 1 |
| `handled_by` | int(10) unsigned | NULL |
| `handled_at` | datetime | NULL |
| `created_at` | datetime | NOT NULL, Default current_timestamp() |

| Schlüssel | Art | Spalten | Zweck |
|---|---|---|---|
| PRIMARY | Primärschlüssel | `id` |  |
| `idx_community_reports_forum` | Index | `forum_id`, `status`, `created_at` | Meldungen aus den Foren eines Forum-Moderators |
| `idx_community_reports_open` | Index | `status`, `created_at` | Meldungen nach Status, älteste zuerst; Löschfrist erledigter Meldungen |
| `idx_community_reports_reporter` | Index | `reporter_id`, `target_type`, `target_id` | Doppelmeldungen derselben Person zusammenführen; Export und Löschung |
| `idx_community_reports_target` | Index | `target_type`, `target_id`, `status` | Meldungen zu einem Ziel bündeln |

#### `community_warnings`

Verwarnungen (Oberfläche ab P2): 1–10 Punkte, Ablauf (Standard 90 Tage), widerrufbar. Löschfrist 2 Jahre nach Ablauf.

| Spalte | Typ | Eigenschaften |
|---|---|---|
| `id` | int(10) unsigned | NOT NULL, AUTO_INCREMENT |
| `user_id` | int(10) unsigned | NOT NULL, Default 0 |
| `issued_by` | int(10) unsigned | NULL |
| `post_id` | bigint(20) unsigned | NULL |
| `reason` | varchar(255) | NOT NULL, Default '' |
| `points` | tinyint(3) unsigned | NOT NULL, Default 1 |
| `expires_at` | datetime | NULL |
| `revoked_at` | datetime | NULL |
| `revoked_by` | int(10) unsigned | NULL |
| `created_at` | datetime | NOT NULL, Default current_timestamp() |

| Schlüssel | Art | Spalten | Zweck |
|---|---|---|---|
| PRIMARY | Primärschlüssel | `id` |  |
| `idx_community_warnings_expires` | Index | `expires_at` | Löschfrist `community.warnings` |
| `idx_community_warnings_user` | Index | `user_id`, `expires_at` | aktive Verwarnungen eines Mitglieds |

#### `community_bans`

Community-Sperren: `scope` `write` (nur lesen) oder `full` (kein Zugriff); `ends_at` NULL = dauerhaft (`community.ban`). Ein Ablauf wirkt bei der Abfrage, kein Cron nötig. `internal_note` sieht das Mitglied nie.

| Spalte | Typ | Eigenschaften |
|---|---|---|
| `id` | int(10) unsigned | NOT NULL, AUTO_INCREMENT |
| `user_id` | int(10) unsigned | NOT NULL, Default 0 |
| `scope` | varchar(10) | NOT NULL, Default 'write' |
| `reason` | varchar(255) | NOT NULL, Default '' |
| `internal_note` | text | NULL |
| `issued_by` | int(10) unsigned | NULL |
| `starts_at` | datetime | NOT NULL, Default current_timestamp() |
| `ends_at` | datetime | NULL |
| `lifted_at` | datetime | NULL |
| `lifted_by` | int(10) unsigned | NULL |
| `created_at` | datetime | NOT NULL, Default current_timestamp() |

| Schlüssel | Art | Spalten | Zweck |
|---|---|---|---|
| PRIMARY | Primärschlüssel | `id` |  |
| `idx_community_bans_user` | Index | `user_id`, `lifted_at`, `ends_at` | aktive Sperre eines Mitglieds (nicht aufgehoben, nicht abgelaufen) |

#### `community_moderation_log`

Moderationsprotokoll ohne IP-Adressen: `action` z. B. `topic.lock`, `topic.move`, `post.hide`, `user.ban`, `report.resolve`; `details_json` z. B. `{"from_forum":3,"to_forum":7}`. Löschfrist 365 Tage.

| Spalte | Typ | Eigenschaften |
|---|---|---|
| `id` | bigint(20) unsigned | NOT NULL, AUTO_INCREMENT |
| `actor_id` | int(10) unsigned | NULL |
| `action` | varchar(40) | NOT NULL, Default '' |
| `target_type` | varchar(10) | NOT NULL, Default '' |
| `target_id` | bigint(20) unsigned | NOT NULL, Default 0 |
| `forum_id` | int(10) unsigned | NULL |
| `reason` | varchar(255) | NULL |
| `details_json` | longtext | NULL |
| `created_at` | datetime | NOT NULL, Default current_timestamp() |

| Schlüssel | Art | Spalten | Zweck |
|---|---|---|---|
| PRIMARY | Primärschlüssel | `id` |  |
| `idx_community_moderation_log_action` | Index | `action`, `created_at` | Filter nach Aktion |
| `idx_community_moderation_log_actor` | Index | `actor_id`, `created_at` | Aktionen eines Akteurs |
| `idx_community_moderation_log_forum` | Index | `forum_id`, `created_at` | Protokoll der Foren eines Forum-Moderators |
| `idx_community_moderation_log_target` | Index | `target_type`, `target_id`, `created_at` | Verlauf eines Ziels |
| `idx_community_moderation_log_time` | Index | `created_at` | Protokoll nach Zeit; Löschfrist |

### Betrieb

#### `community_stats_daily`

Tagesverdichtung (Oberfläche ab P2); `forum_id = 0` = gesamt. Keine personenbezogenen Rohdaten.

| Spalte | Typ | Eigenschaften |
|---|---|---|
| `day` | date | NOT NULL, Default '1970-01-01' |
| `forum_id` | int(10) unsigned | NOT NULL, Default 0 |
| `topics` | int(10) unsigned | NOT NULL, Default 0 |
| `posts` | int(10) unsigned | NOT NULL, Default 0 |
| `active_members` | int(10) unsigned | NOT NULL, Default 0 |
| `new_members` | int(10) unsigned | NOT NULL, Default 0 |
| `solved` | int(10) unsigned | NOT NULL, Default 0 |
| `median_first_reply_minutes` | int(10) unsigned | NULL |

| Schlüssel | Art | Spalten | Zweck |
|---|---|---|---|
| PRIMARY | Primärschlüssel | `day`, `forum_id` |  |

#### `community_redirects`

Weiterleitungen nach Zusammenführen oder Umbenennen: `source_type` `topic` oder `forum`, `source_key` = alte ID bzw. alter Slug. Keine Plugin-Adressen (E6).

| Spalte | Typ | Eigenschaften |
|---|---|---|
| `source_type` | varchar(10) | NOT NULL, Default 'topic' |
| `source_key` | varchar(160) | NOT NULL, Default '' |
| `target_type` | varchar(10) | NOT NULL, Default 'topic' |
| `target_id` | bigint(20) unsigned | NOT NULL, Default 0 |
| `created_at` | datetime | NOT NULL, Default current_timestamp() |

| Schlüssel | Art | Spalten | Zweck |
|---|---|---|---|
| PRIMARY | Primärschlüssel | `source_type`, `source_key` |  |
| `idx_community_redirects_target` | Index | `target_type`, `target_id` | Weiterleitungen eines Ziels beim Löschen aufräumen |

### 4. Capabilities und Rolle

| Capability | Wirkung | Standard (`capabilities_v27`) |
|---|---|---|
| `community.view` | Community sehen (Gäste über die Einstellung „Gäste dürfen lesen“) | `member`, `editor`, `author`, `moderator` |
| `community.participate` | Schreiben, zusätzlich zu den Rechten je Forum | `member`, `editor`, `author`, `moderator` |
| `community.moderate` | globale Moderation: Warteschlange, Meldungen, Verwarnungen, befristete Sperren | `moderator` |
| `community.ban` | dauerhafte Sperren, fremde Sperren aufheben | nur `admin` (implizit) |
| `community.manage` | Struktur, Rechte, Ränge/Abzeichen, Einstellungen, Statistik | nur `admin` (implizit) |
| `community.view_private_data` | gekürzte IP-Kennung, Bearbeitungsverlauf gelöschter Beiträge | nur `admin` (implizit) |

**Wo sie stehen** (alle Listen sind identisch; `TESTS/schema-v27` prüft das):

- `CommunityCapabilities` (Konstanten `VIEW` … `VIEW_PRIVATE_DATA`, `ALL`, `DEFAULT_GRANTS`, `MODERATOR_BASE`, `ELEVATED`, `MARKER`): maßgebliche Quelle für Namen und Migration.
- `RolesModule::CAPABILITIES['community']` (die Konstante heißt `CAPABILITIES`, **nicht** `CAPABILITY_GROUPS` wie in den Planungsdokumenten): Katalog des Rollen-Editors. Fehlt eine Capability dort, löscht „Berechtigungen speichern“ (`savePermissions()` schreibt `role_permissions` komplett neu) ihre Zeilen. `RolesModule::getDefaultPermissions()` liefert die Standards für „Standardrechte wiederherstellen“ und die Matrix-Anzeige.
- `cms_get_default_role_definitions()` in `roles.php`: Code-Standards, die `cms_load_role_capabilities()` mit den Zeilen aus `role_permissions` überlagert (eine Zeile gewinnt immer; `granted = 0` entzieht ein Standardrecht).
- `UserService::REGISTRATION_BLOCKED_CAPABILITIES`: `community.moderate`, `.ban`, `.manage`, `.view_private_data` sperren eine Rolle für die Registrierung; `community.view`/`participate` bleiben erlaubt. `moderator` ist zusätzlich nie als Registrierungsrolle wählbar. `IdentityLinker::PRIVILEGED_ROLES` enthält `moderator` (keine automatische Entra-Verknüpfung per E-Mail).

**Die Rolle `moderator`** ist Systemrolle (`RolesModule::ROLES`, `remove_role()`, `UserService::DEFAULT_ROLES`): nicht umbenenn- oder löschbar, Anzeigename „Moderator“. Sie hat die Grundrechte von `member` (`read`, `edit_profile`, `view_content`, `pages.view`, `posts.view`, `media.view`, `comments.view`) plus `community.view`, `community.participate`, `community.moderate`; **keinen** Zugang zu `/admin`. Eine Rolle `moderator` mit Konten oder Zeilen in `role_permissions` kann es schon geben (der Rollen-Editor nannte sie als Beispiel): Dann vergibt der Schritt nur die Community-Standards und trägt keine Grundrechte nach.

**Update-Schritt `capabilities_v27`** (`CommunityCapabilities::migrate(Database $db)`):

- läuft einmal: Solange die Option `capabilities_v27` in `settings` fehlt; der Marker wird erst nach erfolgreichem Eintragen gesetzt (bei einem Fehler läuft der Schritt beim nächsten Durchlauf wieder);
- `INSERT IGNORE` auf den eindeutigen Schlüssel `uk_role_cap (role, capability)`: **überschreibt nie** eine Zeile, ein ausdrücklich entzogenes Recht (`granted = 0`) bleibt entzogen; ein zweiter Lauf ändert nichts;
- Neuinstallation: läuft über `SchemaManager::ensureFeatureSchema()` (der Installer setzt die Marke selbst, `MigrationManager::run()` kehrt dann sofort zurück); Update von v26: über `MigrationManager::run()`; Reparatur (`run(true)`): Marker verhindert eine Wiederholung;
- nutzt nur die übergebene Verbindung, nie den Singleton-Zugriff auf `Database` (DB-410-02: Die Migration läuft im Konstruktor von `Database`); die Tabelle `role_permissions` legen die Aufrufer vorher an (`cms_ensure_role_permissions_table($this->db)`).

**Rechte beliebiger Konten prüfen** (für `CommunityAccess`, PR #2): `Auth::hasCapability()` gilt nur für das angemeldete Konto. Für andere Konten gilt das Muster aus `ContentWorkflow`: `role === 'admin'` → erlaubt, sonst `!empty(cms_load_role_capabilities($role)[$capability])`, mit injizierbarer Closure für Tests. Gäste haben keine Capability: „Gäste dürfen lesen“ ist eine Einstellung. Konto-Status (gesperrt/inaktiv) prüft `hasCapability()` nicht, das macht `CommunityAccess` selbst.

### 5. Aufgaben je Pull Request

Die Zuordnung der Arbeitspakete (WP-C…) zu den Pull Requests ist ein Vorschlag auf Grundlage des Fahrplans; maßgeblich sind Abhängigkeiten und Sicherheits-Gates im [IMPLEMENTATION-PLAN.md](IMPLEMENTATION-PLAN.md) §4. Namespace `CMS\Services\Community\<Schicht>` unter `CMS/core/Services/Community/` (Schichten `Database`, `Repository`, `Service`, `Http`, `Admin`, `Support`; `Support\CommunityCapabilities` besteht bereits). Der Autoloader bildet `CMS\Services\Community\Service\TopicService` auf `core/Services/Community/Service/TopicService.php` ab. Neue Ordner unter `core/Services/` brauchen Einträge in `DOC/CMSFILESTRUCTUR.md` (`` `Community/` ``) und `DOC/core/SERVICES.md` (`` `Community\` ``): `TESTS/functions` prüft das, auch jede neue Klasse im Service-Wurzelordner.

#### 5.1 PR #2: Services und Moderation (WP-C1 Rest, C2 bis C9, Dienste)

| Baustein | Inhalt |
|---|---|
| Modul und Gerüst | `CoreModuleService::MODULES`-Eintrag `community` (Kategorie „Inhalte & Community“, `default_enabled = false`, abschaltbar; ohne Modul: öffentliche Routen 404, Admin ausgeblendet, Daten bleiben); `FeatureHooks\CommunityHooks` (über `FeatureHooks\Registry`); `Support\Settings` (Gruppe `community`, Typ, Grenze, Standard je Schlüssel), `Support\Slugger`, `Support\Urls`, `Support\I18n`; Sprachschlüssel `community.*` in `CMS/lang/de.yaml` und `en.yaml` |
| Repositories (`Repository\`) | `CategoryRepository`, `ForumRepository`, `TopicRepository`, `PostRepository`, `ReactionRepository`, `SubscriptionRepository`, `ReadStateRepository` (auch `community_forum_read_marks`), `ReportRepository`, `ModerationLogRepository`, `WarningRepository`, `BanRepository`, `AttachmentRepository`, `PollRepository`, `RankRepository`, `BadgeRepository`; nur gebundene Parameter, Sortier-/Filterspalten aus Allowlists. Für `forum_permissions`, `forum_moderators`, `post_revisions`, `drafts`, `poll_options`, `poll_votes`, `ignores`, `user_stats`, `user_badges`, `stats_daily`, `redirects` nennt ARCHITECTURE keine Klasse: Teil des jeweiligen Haupt-Repositories oder eigene Klasse, in PR #2 festlegen |
| Rechte | `Service\CommunityAccess::can(user, action, context)`: einzige Entscheidungsstelle, Ergebnis `allowed`, `reason`, `retry_after`; Reihenfolge Modul aktiv → Konto → Community-Sperre → globale Capability → Forum-Moderator → Forum-Recht → Vertrauensstufe → Zustand → Filter `cms_community_can` (darf nur einschränken); 404 statt 403 bei fehlendem Leserecht; Rechte-Cache nur je Anfrage (kein Gesamtcache je Benutzer, solange `user_role_changed` nicht überall feuert) |
| Themen und Beiträge | `TopicService`, `PostService` (eine Transaktion für Thema, ersten Beitrag, Zähler, Lesestatus, Auto-Abo; Idempotenz gegen doppeltes Absenden; Bearbeiten mit `community_post_revisions`), `QuoteService`, `ReadStateService` (`last_read_post_id` nur steigend), Keyset-Paginierung statt `OFFSET` |
| Darstellung der Quelle | `ContentRenderer` (CommonMark + GFM über `league/commonmark`, Roh-HTML aus, Link-Allowlist `http`/`https`/`mailto`/relativ, Code-Highlighting, Zitate; danach `PurifierService::purify($html, 'community')` mit neuem Profil `community`; `rendered_version`), Batch-Job zum Neu-Rendern; Vendoring über `VendorRegistry` mit SHA-256 in `DOC/assets/`, keine CDNs (E5) |
| Schutz | `CaptchaService` unter `core/Services/Security/` (eingebaut, Proof-of-Work, Bild-Captcha `gregwar/captcha`, keine Drittanbieter, E7; Core-Änderung auch für Formulare und Registrierung), Antispam-Schichten über `AntispamService::evaluate()`, `Security::checkDbRateLimit()`, Langsamer Modus, Wortfilter, Freigabe-Warteschlange; Filter `cms_community_spam_check` |
| Moderation | `ModerationService` (schließen, anpinnen, Ankündigung, verschieben mit Verweis, ausblenden/wiederherstellen, Beiträge verschieben, freigeben/ablehnen, Massenaktionen; Zusammenführen und Teilen sind P2), `ReportService` (Gründe `spam`/`abuse`/`offtopic`/`illegal`/`other`, höchstens 10/Stunde, Doppelmeldungen zusammenführen, Schwelle 3 offene Meldungen von Stufe ≥ 2), `BanService` (`write`/`full`, befristet ohne Cron, dauerhaft nur mit `community.ban`, keine Sperre gegen gleich oder höher Berechtigte), `WarningService` (Schnittstelle, Logik P2), `Http\ModerationController`; jede Aktion: POST + CSRF + `CommunityAccess` + Pflichtgrund + Eintrag in `community_moderation_log` (ohne IP) + Ereignis-Hook |
| Interaktion | `ReactionService`, `RankService` (Standard-Ränge säen), `NotificationDispatcher` (In-App in `notifications` mit Typen `community.*`, Mails über `MailQueueService`, eine Mail je Thema bis zum Besuch, Rechteprüfung je Empfänger), `AttachmentService` (Allowlist + `finfo`, Neukodierung, kein SVG, EXIF entfernt, privater Speicher `community/{yyyy}/{mm}/{random}`), `CommunitySearch` (TNTSearch + SQL-Rechtefilter), Sitemap-Provider und `MediaReferenceExtractor` `Community`; Schnittstellen für `PollService`, `BadgeService`, `ReputationService`, `CommunityStats`, `DraftService`, `MentionService`, `TrustLevelService` (Logik P2/P3, siehe Abschnitt 7 zum P1-Minimum) |
| Core-Änderungen | `user_role_changed` in `UserService::updateUser()`, `bulkAction('change_role')`, `RolesModule::updateRole()/deleteRole()` auslösen (bisher nur im SSO-Pfad, `EntraSsoService`; Signatur `(userId, from, to, source)`); der Hook für gelöschte Konten **existiert schon** als `cms_user_deleted($userId)` (`UserService::deleteUser()`, Verbraucher `FeatureHooks\InvoiceHooks`): kein zweiter `user_deleted` |

**Hooks (Namensschema `cms_community_*`, Signaturen in PR #2 festlegen und in `DOC/core/HOOKS-REFERENCE.md` dokumentieren):** Aktionen `cms_community_topic_created|updated|deleted|moved|merged`, `cms_community_post_created|updated|deleted|approved|rejected`, `cms_community_report_created|resolved`, `cms_community_user_warned|banned|unbanned`, `cms_community_solution_marked`; Filter `cms_community_render_post`, `cms_community_can`, `cms_community_spam_check`, `cms_community_notification_types`. Es fehlen noch Hooks für Teilen, Ausblenden/Wiederherstellen, Beitrag verschieben, Lösung aufheben, Reaktion und Abo.

**Genutzte Core-Hooks (vorhanden):** `register_routes` (vor den Beitragsmustern registrieren), `cms_register_content_type_providers`, `member_dashboard_init`, `dsgvo_export_data`, `dsgvo_delete_data`, `user_registered`, `user_email_verified`, `user_sessions_revoked`, `head`/`body_end`, Cron über `CronRunnerService` (`cms_cron_mail_queue`, `cms_cron_hourly`, `cms_cron_daily`).

**Tests (`TESTS/<suite>/run.php`):** `settings` (jede Einstellung hat Leser und Wirkungstest), `access` (Rechte-Matrix über jede Route × Rolle/Sperre), `content`, `render`, `xss` (Korpus ≥ 30), `spam`, `moderation`, `notify`, `uploads`, `search-seo`. Jeder Test läuft einmal gegen eine absichtlich falsche Umsetzung und muss dort fehlschlagen (Gegenprobe).

#### 5.2 PR #3: Admin (WP-C2, C6, C7 Oberfläche)

Seiten unter `/admin/community-*` (Module in `Community\Admin\`, Ansichten in `CMS/admin/views/community/`, Katalogeinträge in `AdminMenu\CoreCatalog` in beiden Menüprofilen, Gruppe „Community“, bis zur Fertigstellung ausgeblendet):

| Seite | Route | Capability |
|---|---|---|
| Übersicht (Kennzahlen, offene Meldungen, Warteschlange, Einrichtungsassistent) | `/admin/community` | `community.moderate` oder `community.manage` |
| Struktur (Kategorien und Foren, Drag & Drop **und** Tastatur) | `/admin/community-structure` | `community.manage` |
| Themen (Liste, Filter, Massenaktionen) | `/admin/community-topics` | `community.moderate` |
| Freigaben (Warteschlange; Badge `community_queue`) | `/admin/community-queue` | `community.moderate` |
| Meldungen (Badge `community_reports`) | `/admin/community-reports` | `community.moderate` |
| Verwarnungen und Sperren | `/admin/community-sanctions` | `community.moderate` (Sperren P1, Verwarnungen P2) |
| Moderationsprotokoll | `/admin/community-log` | `community.moderate` |
| Rechte (Matrix Forum × Rolle/Gruppe, „Was sieht Rolle X?“) | `/admin/community-permissions` | `community.manage` |
| Ränge und Abzeichen | `/admin/community-ranks` | `community.manage` |
| Statistik | `/admin/community-stats` | `community.manage` (P2) |
| Einstellungen (alle Gruppen aus `Support\Settings`) | `/admin/community-settings` | `community.manage` |

Dazu `BadgeProvider::register()` (Badges `community_queue`, `community_reports`), `AdminNoticeService::registerCheck()` („Warteschlange älter als 24 Stunden“). **Entfällt:** `/admin/community-import` (E6). **Zugang:** `/admin` ist nur für `admin` offen; die Seiten sind Admin-Sache. Sollen Moderatoren Teile nutzen, müssen die Seiten in `DelegatedAdminAccess::PAGES` stehen (Entscheidung in Abschnitt 7); `TESTS/admin-menu` gleicht Katalog-Capabilities mit den Seitenprüfungen ab. Jede Seite: CSRF je Aktion, `Auth`/`CommunityAccess`, kein Inline-Skript.

#### 5.3 PR #4: Mitgliederbereich und Frontend (WP-C10, C3, C7)

| Bereich | Inhalt |
|---|---|
| Öffentliche Routen | `/forum` (Start), `/forum/{forum-slug}[/seite-{n}]`, `/forum/{forum-slug}/neues-thema`, `/forum/thema/{id}-{slug}[/seite-{n}]` (falscher Slug → 301 über die ID; EN: `/en/forum/topic/{id}-{slug}`), `/forum/thema/{id}/ungelesen`, `/forum/beitrag/{id}`, `/forum/thema/{id}/antworten`, `/forum/beitrag/{id}/bearbeiten`, `/forum/neu`, `/forum/aktiv`, `/forum/unbeantwortet`, `/forum/suche`, `/forum/mitglieder/{id}`, `/forum/anhang/{id}[/vorschau]`; alle auch unter `/en/…`. **Reservierte Segmente** für Forum-Slugs (`neu`, `aktiv`, `unbeantwortet`, `suche`, `feed`, `thema`, `beitrag`, `anhang`, `api`, `mitglieder`) in `Support\Slugger` sperren |
| JSON-API | `POST /forum/api/reaktion`, `/abo`, `/melden`, `/vorschau` (nur angemeldet), `/moderation`; P2: `/umfrage`, `/entwurf`, `GET /forum/api/erwaehnung`; `GET /forum/api/aehnliche-themen`, `/forum/api/neu` (Polling 60 s, ETag/304). CSRF-Token im Header, Rate-Limit je Aktion. Ohne Definition im Plan: Upload-Endpunkt, „Alle als gelesen markieren“, signierter Abmeldelink, „Bestätigungsmail erneut senden“, Captcha-Challenge, Formular-Fallbacks ohne JavaScript für Melden und Moderation |
| Mitgliederbereich | `CommunityMemberSection` über `PluginDashboardRegistry`, Slug `community`, `/member/plugin/community`: Meine Themen, Meine Beiträge, Abonnements, Benachrichtigungen, Signatur und Community-Profil (Lesezeichen, Ignorierte, eigene Verwarnungen P2). **Moderation für die Rolle `moderator` läuft hier und im Frontend** (Moderationsmenü je Beitrag, Warteschlange, Meldungen), nicht in `/admin` |
| Vorlagen | `CMS/views/community/` (`index`, `forum`, `topic`, `compose`, `search`, `listing`, `profile-section`, `partials/…`), per Theme überschreibbar (`themes/<aktiv>/community/<name>.php` zuerst); Variablen im Kopfkommentar jeder Vorlage |
| Assets | `CMS/assets/css/community.css`, `CMS/assets/js/community/*.js` als ES-Module mit `defer`, kein Inline-Skript/-Style, Trusted-Types-konform (nur DOMPurify-Policy), keine externen Ressourcen; Richtwerte CSS ≤ 25 KB und JS ≤ 30 KB gzip (Editor ≤ 60 KB, lädt erst bei Fokus) |
| SEO und Cache | kanonische URL, `rel prev/next`, `noindex` (Suche, Antwort-/Bearbeitungsseiten, leere Foren, „Neue Beiträge“, interne Foren), Schema.org `DiscussionForumPosting`/`QAPage`; Gäste erhalten Seiten aus dem Cache (Schlüssel mit Forum-/Thema-Version und Sprache), Seiten mit Formular oder Token sind `private, no-store`; interne Foren nie im öffentlichen Cache, in Suche, Feed oder Sitemap |
| Barrierefreiheit | WCAG 2.2 AA, `<article>` je Beitrag, `aria-live`, Fokusfalle in Dialogen, „ungelesen“ nicht nur über Farbe |
| Theme | Das Theme `cms-phinit` (`member/forum.php` leitet bisher auf das Plugin) liegt in einem **anderen Repository** (PS-PhinIT/365CMS.DE-PhinIT_THEME): eigener Theme-PR, nicht Teil dieses Repositories |

Alle Routen fragen nur `CommunityAccess`; Weiterleitungsziele nur lokale Pfade. **Entfällt (E6):** alte Plugin-Adressen (`/forum/thread/{id}` …) und ihre 301/410-Antworten.

#### 5.4 PR #5: DSGVO, Tests, Dokumentation und Release-Vorbereitung (WP-C11, C13)

| Bereich | Inhalt |
|---|---|
| DSGVO | `dsgvo_export_data` (Themen, Beiträge, Reaktionen, Abos, Stimmen, eigene Meldungen, Verwarnungen, Sperren, Profilfelder) und `dsgvo_delete_data` (Anonymisieren = `user_id` NULL, Anzeige „Gelöschtes Mitglied“, Profil 404, Zitate neutralisieren; oder Löschen, Einstellung mit Hinweis im Datenschutztext); Löschantrag und Kontolöschung wirken gleich (`cms_user_deleted`); Papierkorb-Provider (`TrashProvider` über `ContentTypeRegistry`); Legal-Textbausteine |
| Löschfristen | `CommunityRetentionRules` über `DataRetentionService::registerRule()`: `community.ip_hash` 30 Tage (`community_posts.ip_hash`, Index `idx_community_posts_created`) · `community.read_states` 180 Tage (`idx_community_read_states_cleanup`) · `community.orphan_uploads` 24 Stunden (`idx_community_attachments_post`) · `community.drafts` 30 Tage (`idx_community_drafts_updated`) · `community.reports` 365 Tage, erledigte (`idx_community_reports_open`) · `community.moderation_log` 365 Tage (`idx_community_moderation_log_time`) · `community.warnings` 2 Jahre (`idx_community_warnings_expires`) · `community.revisions` 365 Tage, letzte Fassung bleibt (`idx_community_post_revisions_created`) |
| Tests | `privacy` (Export, Anonymisieren/Löschen, jede Löschfrist-Regel), `performance` (≤ 6 Abfragen je Seite bei warmem Cache, Lasttest 10.000 Themen/500.000 Beiträge, kein `OFFSET`-Scan per `EXPLAIN`), `browser` (Playwright, 360/320 px, Dark Mode, Tastatur), `a11y` (axe-core ohne *serious/critical*), `lighthouse` (mobil ≥ 90); `settings`/`access`/`xss` vervollständigen; Gegenproben dokumentieren; `php TESTS/run-all.php` und mit `CMS_TEST_DB_HOST` auch `schema-upgrade` |
| Dokumentation | `DOC/admin/community/` (neu), `DOC/member/`, `DOC/core/HOOKS-REFERENCE.md`, `DOC/core/DATABASE-SCHEMA.md`, `DOC/core/SERVICES.md`, `DOC/CMSFILESTRUCTUR.md`, `DOC/FILELIST.md`, `DOC/FILESTRUCTUR.md`, `DOC/INDEX.md`, `DOC/admin/users-groups/RBAC.md` (Rolle `moderator`, Gruppe „Community“), `Changelog.md` (Abschnitt `v4.2.0`), ROADMAP-Fortschritt |
| Release | `Version::CURRENT`, `CMS/update.json` und `CMS/marketplace/core/365cms/update.json` (Version, `schema_version` = `v27`, Prüfsumme), Pakete `RELEASE/365CMS-4.2.0-*` mit `README-4.2.0.md` und SHA-256; `security.yml` (Semgrep/Trivy) ohne neue Befunde; `TESTS/release-3.4.0` prüft Paket, Manifest und Marke gemeinsam |

### 6. Prüfen und Arbeiten (alle Pull Requests)

- Vor jedem Push: `php -l` über alle geänderten PHP-Dateien, `php TESTS/run-all.php` (aktuell 36 Suiten). Für Änderungen an Schema oder Rechten zusätzlich `CMS_TEST_DB_HOST=localhost CMS_TEST_DB_USER=root php TESTS/schema-upgrade/run.php` gegen eine MariaDB/MySQL (auch mit `CMS_TEST_SQL_MODE` = MySQL-8-Standard). In der CI läuft nur `run-all.php` unter PHP 8.4 und 8.5, **ohne** Datenbank.
- `TESTS/functions/doc-references.php` verlangt: jede `TESTS/*/run.php`-Suite in `DOC/FILELIST.md` und `DOC/FILESTRUCTUR.md`; neue Service-Ordner und -Klassen in `CMSFILESTRUCTUR.md` und `SERVICES.md`; die Tabellenzahl „The base schema (N tables)“ in `DATABASE-SCHEMA.md`.
- Code im Konstruktor-Pfad von `Database` (`SchemaManager`, `MigrationManager`, Update-Schritte) nutzt nur die übergebene Verbindung (`get_option()`, `OptionStore`, `SettingsService` rufen `Database::instance()` auf und erzeugen die Rekursion aus DB-410-02).
- `Changelog.md` beginnt mit einem BOM; Einträge gehören unter `### v4.2.0` über `v4.1.9`.
- Sicherheits-Checkliste je Paket: [SECURITY-PRIVACY.md](SECURITY-PRIVACY.md) §6; Einstellungen: jeder Schlüssel hat einen Leser im Code und einen Wirkungstest.

### 7. Abweichungen von den Planungsdokumenten und offene Entscheidungen

**Bereits entschieden (PR #1), Planungsdokumente weichen ab:**

1. `RolesModule::CAPABILITY_GROUPS` (PERMISSIONS-MODERATION §2, IMPLEMENTATION-PLAN §6) heißt im Code `CAPABILITIES`.
2. IMPLEMENTATION-PLAN §6 beschreibt `capabilities_v27` mit „Marker in `settings`“: umgesetzt. (Der Schritt `capabilities_v26` hat keinen Marker, siehe DOC-410-03.)
3. ARCHITECTURE §2 und ROADMAP führen `Database/Installer.php` als Ort der Tabellen; der Plan §5 und PR #1 legen die DDL nur in `SchemaManager` ab (Abschnitt 2, Punkt 1).
4. DATA-MODEL §3 zeigt `legacy_id`, `legacy_source` und `community_redirects` für Plugin-Adressen; wegen E6 entfallen die Spalten, die Tabelle bleibt für `topic`/`forum`. Die Textspalten sind `NULL`-fähig, die übrigen Spalten haben Defaults (Abschnitt 2, Punkt 4).
5. `user_deleted` gibt es schon als `cms_user_deleted`; neu einzuführen ist nur `user_role_changed` außerhalb des SSO-Pfads.
6. IMPLEMENTATION-PLAN §1 nennt `Version::CURRENT = '4.1.0'`, §9 „aktuell 31 Suiten“: tatsächlich 4.1.8 und 36 Suiten (35 + `schema-v27`).
7. IMPLEMENTATION-PLAN §5: „Tabellen mit Präfix `cms_`“ meint `{prefix}community_…`.

**Offene Entscheidungen vor oder in PR #2:**

1. **P1-Minimum für Vertrauensstufen und Erwähnungen.** Beides ist P2 (WP-C14/C15), wird aber in P1 vorausgesetzt (Prüfreihenfolge in `CommunityAccess`, Grenzen je Stufe in WP-C5, Erwähnungs-Limit im Gate von WP-C7). Festlegen, was P1 liefert (Vorschlag: Stufe 0/1/2 aus E-Mail-Bestätigung und Beitragszahl ohne Cron-Neuberechnung; Erwähnungen nur als Link ohne Benachrichtigung).
2. **Oberfläche der Rolle `moderator`.** Der Plan schließt `/admin` für sie aus; die Warteschlange und die Meldungen müssen deshalb im Frontend/Mitgliederbereich (PR #4) bedienbar sein, nicht nur als Hinweis (WORKFLOWS W8). Alternative: Moderationsseiten in `DelegatedAdminAccess::PAGES` aufnehmen.
3. **Einstellungsschlüssel.** Ob Option `community.title` (ein Schlüssel) oder Gruppe `community` + Schlüssel `title`: an der Konvention von `SettingsService` ausrichten und in `Support\Settings` festhalten. Die Zuordnung der Plugin-Schlüssel zu den Core-Schlüsseln steht in MIGRATION-CMS-FORUM §4 (nur als Liste der benötigten Einstellungen; keine Übernahme).
4. **Captcha.** Die Pläne sprechen teils von „Anbietern“ mit Schlüsseln; E7 erlaubt nur lokale Verfahren. Der `CaptchaService` ist eine Core-Änderung auch für Formulare und Registrierung: Umfang und eigener Pull Request festlegen.
5. **E6-Reste in den Planungsdokumenten** (ARCHITECTURE §4 `community-import`, `default_enabled` bei aktivem `cms-forum`, BBCode-Altbeiträge; ROADMAP WP-C4 BBCode-Renderer; PERMISSIONS-MODERATION §2 „Datenübernahme“): nicht umsetzen.
6. **Benachrichtigungen bündeln** ohne Spalte (Abschnitt 2, Punkt 7) oder eine Spalte an `notifications` ergänzen (Änderung eines Core-Schemas, Test und Changelog).
7. **Lücken in den Plänen:** Hook-Signaturen und -Namen (siehe 5.1), Endpunkte ohne Definition (5.3), englische URL-Segmente (`neues-thema`, `antworten`, `bearbeiten`, `ungelesen`, `beitrag`, `anhang`, `aktiv`, `unbeantwortet`), Namen und Zeitpläne der Cron-Aufgaben außer `community-digest`, Namen der Admin-Klassen.

### Verwandte Dokumente

[README.md](README.md) · [IMPLEMENTATION-PLAN.md](IMPLEMENTATION-PLAN.md) · [DATA-MODEL.md](DATA-MODEL.md) · [PERMISSIONS-MODERATION.md](PERMISSIONS-MODERATION.md) · [ARCHITECTURE.md](ARCHITECTURE.md) · [SECURITY-PRIVACY.md](SECURITY-PRIVACY.md) · [TEST-PLAN.md](TEST-PLAN.md) · [../../core/DATABASE-SCHEMA.md](../../../core/DATABASE-SCHEMA.md) · [../../audit/RELEASE-AUDIT-4.1.0-2026-11-15.md](../../../audit/RELEASE-AUDIT-4.1.0-2026-11-15.md)
