# 365CMS Projektdokumentation | Community: Übergabe PR #1 (#176) und PR #2a → PR #2b bis PR #5
> **Stand:** 2026-11-19 | **Ziel-Version:** 4.2.0 · Schema **v27** (Entwicklungsstand) | **Status:** PR #1 geliefert (Schema, Capabilities, Rolle) · PR #2a geliefert (Fundament der Dienste, siehe §1a) · PR #2b bis #5 offen | **Übersicht:** [README.md](README.md)
> **Grundlage:** [IMPLEMENTATION-PLAN.md](IMPLEMENTATION-PLAN.md) · [DATA-MODEL.md](DATA-MODEL.md) · [PERMISSIONS-MODERATION.md](PERMISSIONS-MODERATION.md) · [ARCHITECTURE.md](ARCHITECTURE.md) · [TEST-PLAN.md](TEST-PLAN.md)

## English (summary)

Hand-over briefing after **PR #1** (#176) and **PR #2a** (#178) of the 4.2.0 Community module. PR #1 delivered the database schema `v27` (27 tables `community_*`, 117 in total), the six capabilities `community.*`, the system role `moderator` and the one-time update step `capabilities_v27` (marker in `settings`, never overwrites a stored permission). PR #2a delivered the service layer without any user interface (section 1a): the core module `community` (off by default), `Support\Settings`, the single access decision `CommunityAccess::can()`, repositories, `TopicService`, `PostService`, `QuoteService`, `ReadStateService` and the hooks for topics and posts. Follow-up fixes after independent reviews are recorded in section 2a, item 10 (PR #2a) and section 2, items 7, 9 and 10 (PR #1: two `community_topics` indexes for the global "unanswered" list and the "unsolved" filter, with a column order that keeps the plans of the existing forum list queries, `community_badges.description_en`, identical `notifications` columns on every path including development installations from PR #1, and a database update check that also reports these indexes and column definitions; the schema marker stays `v27`). `TopicRepository::listForum()` now reads the stream in two steps (IDs over the index, then the rows by primary key), because `SELECT *` read a number of index entries growing with the cursor position in large forums (section 2, item 10). Routes, admin pages, member pages and templates do not exist yet. This document is the source of truth for the exact table, column, index and capability names and lists, per pull request, the classes, routes, hooks and tests that PR #2b (rendering, moderation, reports, notifications, attachments, search), PR #3 (admin), PR #4 (member area, front end) and PR #5 (GDPR, tests, documentation) must build, plus the decisions already made and the open ones. Where it differs from the earlier planning documents, this document and the code win; the differences are listed in section 7.

## Deutsch

### 1. Was PR #1 geliefert hat (und was nicht)

| Bereich | Stand | Wo |
|---|---|---|
| Schema v27 | 27 Tabellen `community_*` (PR #2b ergänzt `community_notify_queue`: 28, 118 insgesamt, 65 Nicht-Primär-Indizes und 283 Spalten in den Community-Tabellen) und zwei Spalten an der Core-Tabelle `notifications` (`group_key`, `event_count`); `SCHEMA_VERSION = 'v27'` in `SchemaManager` und `MigrationManager`, Liste `SchemaManager::FEATURE_TABLES_V27`; Neuinstallation = Update (von v25 und v26) = Laufzeitprüfung (Typ, NULL, Default und Indizes, bei `notifications` auch Kommentar und Kollation; beim Update hängen neue Spalten an Bestandstabellen am Ende an, §2 Nr. 7) | `CMS/core/SchemaManager.php`, `CMS/core/MigrationManager.php` |
| Datenbank-Update | `DatabaseUpdateRunner::missingSchemaElements()` prüft die Tabellen aus `FEATURE_TABLES_V27` (27, seit PR #2b 28) mit (`table:community_…`), die Spalten an `notifications` samt Kommentar und Kollation (`REQUIRED_COLUMN_DEFINITIONS`), die Indizes aus `REQUIRED_INDEXES` (`index:…`, §2 Nr. 9) und den Marker `capabilities_v27` (`option:capabilities_v27`) | `CMS/core/DatabaseUpdateRunner.php` |
| Capabilities | sechs `community.*`, Standardrechte, Rolle `moderator` | `CMS/core/Services/Community/Support/CommunityCapabilities.php`, `CMS/includes/functions/roles.php`, `CMS/admin/modules/users/RolesModule.php`, `CMS/core/Services/UserService.php` |
| Update-Schritt | `capabilities_v27`: einmalig, Marker `capabilities_v27` in `settings`, überschreibt nie | `CommunityCapabilities::migrate()`, aufgerufen von `MigrationManager::run()` und `SchemaManager::ensureFeatureSchema()` |
| Tests | `TESTS/schema-v27` (ohne Datenbank), `TESTS/schema-upgrade` (optional, echte MariaDB/MySQL: Neuinstallation und Updates von 4.0.0/v25 und 4.1.8/v26 ergeben dieselbe Struktur, Rechte-Schritt inklusive) | `TESTS/` |
| **Nicht geliefert** | Services, Repositories, Controller, Routen, Hooks, Admin-Seiten, Mitgliederbereich, Vorlagen, Sprachschlüssel, Einstellungen, Löschfrist-Regeln, DSGVO-Hooks, `CoreModuleService`-Eintrag | PR #2 bis #5 |

**Versionsstand.** `Version::CURRENT` bleibt **4.1.8**, ebenso `CMS/update.json`, `CMS/marketplace/core/365cms/update.json` und `RELEASE/`: Das Manifest beschreibt das veröffentlichte Paket 4.1.8 (Schema v26). `TESTS/release-3.4.0` prüft deshalb, dass `schema_version` des Manifests der Marke **im Paket** entspricht und nie höher ist als die des SchemaManagers. Mit der Release-Vorbereitung 4.2.0 (WP-C13) wechseln `Version::CURRENT`, Manifeste, Paket und Prüfsumme gemeinsam; dann gilt wieder Gleichheit. Auf Installationen mit dem Entwicklungsstand läuft die Schema-Anhebung automatisch beim ersten Request (neuer Flag-Name, `MigrationManager::run()` von v26 auf v27, danach `ensureFeatureSchema()`); das Update-System kennt sie erst mit 4.2.0.

### 1a. Was PR #2a geliefert hat (und was nicht)

PR #2 ist auf Wunsch des Auftraggebers in zwei Pull Requests geteilt: **#2a** (Fundament, ohne Oberfläche und ohne Darstellung der Beiträge) und **#2b** (Darstellung, Moderation, Meldungen, Benachrichtigungen, Anhänge, Suche, Reaktionen, Abos). Das Captcha folgt als **eigener PR**.

| Bereich | Stand | Wo (`CMS/core/Services/Community/`, sonst angegeben) |
|---|---|---|
| Modul | Eintrag `community` in `CoreModuleService::MODULES` (erstes Modul mit `default_enabled = false`), `FeatureHooks\CommunityHooks` (fail-closed Modul-Gate, Listener `user_role_changed`/`user_email_verified` leeren den Rechte-Cache), Eintrag in `FeatureHooks\Registry` | `CMS/core/Services/CoreModuleService.php`, `CMS/core/Services/FeatureHooks/` |
| Support | `Settings` (21 Einstellungen, PR #2b: 33, Gruppe `community`, flache snake_case-Schlüssel, jede mit Leser und Wirkungstest), `Slugger`, `Urls`, `I18n`, `Events` (Hook-Namen, `fire()` nach Commit, `applyFilter()`), `CommunityModule`, `Codes` (alle Ablehnungsgründe und Fehlercodes), `Cursor`, `Page`, `Transaction` | `Support/` |
| Repositories | `CategoryRepository`, `ForumRepository` (Rechte-Zeilen, Moderatoren, Vererbungskette), `TopicRepository` (Keyset), `PostRepository` (Verlauf), `ReadStateRepository` (inkl. Foren-Marken), `MemberRepository`, `BanRepository` (nur lesend), `UserStatsRepository` | `Repository/` |
| Rechte | `CommunityAccess::can()` (neun Schritte laut PERMISSIONS-MODERATION §4, im Code zusätzlich Schritt 0 „Aktion bekannt“; Freigabepfade siehe §2a Nr. 5; `AccessDecision` mit `initialStatus`/`view`), `filterReadable()`, `readableForumIds()`, `limitsFor()`, `forgetUser()`; `BasicTrustPolicy` (Vertrauensstufen P1) | `Service/` |
| Dienste | `TopicService` (anlegen, löschen, Liste), `PostService` (antworten, bearbeiten, löschen, Liste, Position/Seite), `QuoteService`, `ReadStateService` | `Service/` |
| Hooks | `cms_community_topic_created\|updated\|deleted`, `cms_community_post_created\|updated\|deleted`, Filter `cms_community_can`; Core-Hook `user_role_changed` (Quellen `admin_update`, `admin_bulk`, `role_renamed`, `role_deleted`, vorhanden `sso`) | `DOC/core/HOOKS-REFERENCE.md`, `UserService`, `RolesModule` |
| Sprachschlüssel | `community.access.<code>` und `community.error.<code>` für jeden Code aus `Support\Codes`, Deutsch und Englisch | `CMS/lang/de.yaml`, `en.yaml` |
| Tests | `community-settings`, `community-repositories`, `community-access`, `community-content` (alle ohne Datenbank, laufen in der CI), `community-db` (echte MariaDB: Rollback, Wettlauf mit 8 Prozessen, UTC bei Ortszeit, EXPLAIN der Listen; Selbst-Skip ohne `CMS_TEST_DB_HOST`), `community-mutants` (Gegenprobe: jede abgesicherte Regel läuft gegen eine absichtlich falsche Umsetzung und muss scheitern), `user-role-hook` | `TESTS/` |
| **Nicht geliefert** | `ContentRenderer`/Purifier-Profil, Moderationsdienst, Meldungen, Sperren-/Verwarnungsdienst, Benachrichtigungen, Abos und Auto-Abo, Reaktionen, Ränge, Anhänge, Suche, Antispam/Wortfilter, Link-/Bild-/Anhangsgrenzen, Captcha, `ip_hash`, Routen, Admin-Seiten, Mitgliederbereich, Vorlagen | PR #2b bis #5 |

### 2. Festlegungen aus PR #1

1. **Alle Definitionen stehen nur in `SchemaManager::getSchemaQueries()`** (Plan §5), nicht in einem `Database/Installer`. Ein `Community\Database\Installer` (ab PR #2b) sät höchstens Standard-Ränge und gegebenenfalls die Standard-Kategorie und -Foren des Einrichtungsassistenten (PR #3), **nie Einstellungen** (deren Standards stehen nur in `Support\Settings::DEFINITIONS`; kein Installer und kein Update-Schritt sät sie in die Datenbank, §2a Nr. 1) und kein DDL. Dieselbe Definition nutzen Neuinstallation, Update und Laufzeitprüfung.
2. **E6 (keine Datenübernahme aus `cms-forum`) ist umgesetzt:** keine `legacy_id`-/`legacy_source`-Spalten, kein BBCode (`format` kennt nur `markdown`), `community_redirects` bleibt (27 Tabellen, 90 + 27 = 117), aber nur für `topic` und `forum` (Zusammenführen, Umbenennen), nicht für Plugin-Adressen.
3. **Keine Fremdschlüssel** (wie v25/v26, DATA-MODEL §1): Konsistenz über die Dienste und den Reparatur-Job. Fehler von Fremdschlüsseln stünden nicht in der Ignore-Liste des `MigrationManager`.
4. **Strict-Mode:** keine `NOT NULL`-Spalte ohne Default. Textspalten (`TEXT`, `MEDIUMTEXT`, `LONGTEXT`: `description`, `body`, `body_html`, `permissions_json`, `*_json`, `message`, `internal_note` …) sind `NULL`-fähig, weil MySQL < 8.0.13 keinen Default für Text kennt: **Dienste prüfen Pflichtfelder selbst** (z. B. `body` nicht leer). Zeitspalten `DATETIME NOT NULL` haben den Default `CURRENT_TIMESTAMP` als Sicherheitsnetz; **Zeiten setzen die Dienste ausdrücklich in UTC** (`gmdate('Y-m-d H:i:s')`), weil die Datenbankverbindung die Zeitzone der Site trägt (`Database` setzt `SET time_zone` auf den PHP-Offset: `NOW()` und `CURRENT_TIMESTAMP` liefern Ortszeit). Zeitvergleiche in SQL (`ends_at`, `locked_until`, `pinned_until`, `moved_expires_at`, Löschfristen) binden daher einen `gmdate()`-Wert aus der Uhr-Closure, nie `NOW()`, `CURRENT_TIMESTAMP` oder `UTC_TIMESTAMP()` (§2a Nr. 3, Wächter in `community-content`); auch `starts_at` in `community_bans` ausdrücklich in UTC setzen. Geprüft gegen MariaDB 10.11 im MariaDB-Standardmodus, im MySQL-8-Modus (`ONLY_FULL_GROUP_BY`, `NO_ZERO_DATE`) und mit `STRICT_ALL_TABLES`: Ein leeres `INSERT … () VALUES ()` gelingt in jeder Tabelle.
5. **Indexnamen** folgen v26: `uq_community_<tabelle>_<zweck>` und `idx_community_<tabelle>_<zweck>`, im ganzen Schema eindeutig und höchstens 64 Zeichen; keine Indexspalte über 767 Byte (Präfix `path(191)`), also auch ohne DYNAMIC-Zeilenformat gültig. Die Indizes bedienen die Abfragen aus DATA-MODEL, ARCHITECTURE §8 und die Löschfristen (Spalte „Zweck“ unten). Wer eine neue Abfrage braucht, ergänzt den Index in `getSchemaQueries()` **und** (für bestehende v27-Installationen aus der Entwicklung) in `SchemaManager::REQUIRED_INDEXES` (gewöhnlicher Index, ganze Spalten); einen eigenen Aufruf in `ensureFeatureSchema()` braucht er nicht. `ensureFeatureSchema()` legt jeden fehlenden Index aus `REQUIRED_INDEXES` per `ALTER` an, für jede Tabelle dort und nach allen Spaltennachträgen, also auch auf einer Spalte, die derselbe Lauf erst ergänzt; das Datenbank-Update prüft ihn (§2 Nr. 9; `TESTS/schema-v27` prüft die Regeln, `TESTS/schema-upgrade` den Weg mit einem Eintrag für eine weitere Tabelle). Danach erzeugt er den Snapshot `TESTS/schema-v27/golden.json` neu (`CMS_TEST_DB_HOST=localhost CMS_TEST_DB_USER=root php TESTS/schema-v27/dump-golden.php`, braucht eine MariaDB/MySQL) und passt die Tabellen in Abschnitt 3 an: `TESTS/schema-v27` vergleicht Definition, Snapshot und diese Tabellen (Spalten mit Typ, NULL, Default, Primärschlüssel, Indizes, in beide Richtungen), `TESTS/schema-upgrade` die echte Datenbank mit dem Snapshot.
6. **Schema-Änderungen nach PR #1** sind bis zum Release 4.2.0 möglich, ohne die Marke zu erhöhen (neue Tabellen: `CREATE TABLE IF NOT EXISTS` greift über `ensureFeatureSchema()`, die Laufzeit-Flag hängt am Hash von `SchemaManager.php`). Neue Spalten an v27-Tabellen brauchen `ensureColumnExists()` in `ensureFeatureSchema()` (und ein `ALTER` im `MigrationManager`, falls sie auch Installationen von v25/v26 betreffen), plus Eintrag in `SchemaManager::REQUIRED_COLUMNS`, damit das Datenbank-Update sie prüft.
7. **Einzige Änderung an einer Bestandstabelle: `notifications`** (`group_key VARCHAR(120) NULL`, `event_count INT UNSIGNED NOT NULL DEFAULT 1`, Index `idx_user_group (user_id, group_key, is_read)`). DATA-MODEL sieht die Bündelung je Thema vor („3 neue Antworten in …“, Gruppenschlüssel z. B. `community:topic:{id}`, Folgeereignisse erhöhen einen Zähler statt neue Zeilen); ohne Spalte ließe sie sich nur über Textvergleiche lösen. Die Spalten stehen in der Neuinstallation (`getSchemaQueries()`), im `MigrationManager`, in `ensureFeatureSchema()` und in `REQUIRED_COLUMNS` (das Datenbank-Update prüft sie); Bestandszeilen haben keinen Schlüssel und den Zähler 1. Alle Wege nutzen dieselbe Spaltendefinition samt Kommentar. `group_key` erhält in beiden `ALTER` ausdrücklich `CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci`, weil `ADD COLUMN` sonst die Kollation der Tabelle erbt (Tabellen aus 4.0.0 tragen die Standardkollation des Servers: MariaDB und MySQL 5.7 `utf8mb4_general_ci`, MySQL 8 `utf8mb4_0900_ai_ci`); im CREATE bestimmt sie die Tabelle, die dort fest `utf8mb4_unicode_ci` trägt (keine Spaltenkollation in CREATE-Texten, wie `TESTS/schema-v27` für die Community-Tabellen verlangt). Entwicklungsinstallationen, die v27 mit dem Code von PR #1 erreicht haben, tragen beide Spalten ohne Kommentar und `group_key` mit der Kollation der Tabelle; `ensureFeatureSchema()` gleicht sie per `MODIFY COLUMN` mit derselben Definition an (`alignColumnDefinitions()`: nur bei abweichendem Kommentar oder abweichender Kollation, die Position bleibt, ein zweiter Lauf ändert nichts). Die Definition steht einmal in `SchemaManager::REQUIRED_COLUMN_DEFINITIONS` (der `MigrationManager` trägt denselben Text, `TESTS/schema-v27` vergleicht). Die Konstante gilt nur für `notifications`: `ensureFeatureSchema()` liest diesen Schlüssel ausdrücklich; eine weitere Tabelle bräuchte dort einen eigenen `ensureColumnExists()`/`alignColumnDefinitions()`-Aufruf und einen Eintrag in `REQUIRED_COLUMNS`, `TESTS/schema-v27` verlangt deshalb genau den Schlüssel `notifications`. Das Datenbank-Update meldet eine Abweichung als `column:notifications.group_key (Kommentar/Kollation)`. Beim Update hängen die Spalten am Ende an (kein `AFTER`, wie bei den v26-Spalten von `orders`); `TESTS/schema-upgrade` vergleicht Typ, NULL, Default, Kommentar und Kollation auf allen Wegen (auch Entwicklungsinstallation aus PR #1 nach Update von 4.0.0 und 4.1.8), nicht die Position. Der `NotificationDispatcher` (PR #2) sucht den ungelesenen Hinweis mit gleichem `user_id`, `group_key` und `is_read = 0`, erhöht `event_count` und schreibt `title`/`message` fort; ohne `group_key` verhalten sich Hinweise wie bisher. **Nicht geändert** (offene Entscheidungen, Abschnitt 7): `favorites` (kein Typfeld, Fremdschlüssel auf `posts`) und `search_queries` (keine Quelle für die Community-Suche).
8. **`/admin` erreicht weiterhin nur die Rolle `admin`** (`Auth::isAdmin()`; `AdminRouter`). `DelegatedAdminAccess::PAGES` öffnet bisher nur Zahlungen, Rechnungen, Block-Vorlagen und Webhooks. Die Rolle `moderator` hat `community.moderate`, kommt damit aber nicht in `/admin`: Ihre Oberfläche ist das Frontend bzw. der Mitgliederbereich (PR #4). Offene Entscheidung dazu: Abschnitt 7.
9. **Nachträge nach PR #1 (vor dem Release, Marke bleibt `v27`):** `community_topics` erhält `idx_community_topics_unanswered_all` (`status`, `reply_count`, `created_at`) und `idx_community_topics_solved` (`solved_at`, `forum_id`, `status`, `is_pinned`, `last_post_at`), `community_badges` die Spalte `description_en` (wie `title_en` zu `title`). Neuinstallation und Update von v26 erhalten sie über die CREATE-Definition, Entwicklungsinstallationen aus PR #1 über `ensureFeatureSchema()`: Indizes aus `SchemaManager::REQUIRED_INDEXES` per `ALTER` (`ensureRequiredIndexes()` für jede Tabelle dort, am Ende von `ensureFeatureSchema()`; ein gleichnamiger Index mit anderen Spalten wird in derselben Anweisung ersetzt, etwa `idx_community_topics_solved` in einer der beiden früheren Spaltenfolgen), die Spalte per `ensureColumnExists()` nach `description`; `REQUIRED_COLUMNS` enthält `community_badges.description_en`.
    - **Spaltenfolge von `idx_community_topics_solved`:** `solved_at` steht vorn. Dann nutzt nur eine Abfrage mit Bedingung auf `solved_at` den Index, und nur `idx_community_topics_forum_list` beginnt mit `forum_id` und überdeckt die ID-Abfrage des Stroms der Forenliste (`forum_id`, `status`, `is_pinned`, `last_post_at`). Zwei frühere Formen des Nachlaufs scheiterten an MariaDB 10.11:
        - (`forum_id`, `status`, `is_pinned`, `solved_at`, `last_post_at`): Der Optimierer schätzte den neuen Index je nach Seitenlayout billiger, er sortiert aber nicht nach `last_post_at`; er wich deshalb für den Strom der Forenliste und für `lastPublished()` auf `idx_community_topics_activity` aus (Forum mit 16 % der Themen und älterer Aktivität: erste Seite 5955 statt 67 Lesezugriffe, `lastPublished()` 5889 statt 2), und die Abfrage nach abgelaufenen Pins nahm auf Folgeseiten `ref` auf dem neuen Index mit Filesort.
        - (`forum_id`, `solved_at`, `status`, `is_pinned`, `last_post_at`): Die ID-Abfrage des Stroms nahm in kleinen Foren auf Folgeseiten `ref` auf dem neuen Index nur über `forum_id` (`key_len` 4) mit Filesort und las das ganze Forum. Betroffen waren Foren mit 30 bis 1200 Themen, fast alle veröffentlicht und nicht angeheftet (das gewöhnliche kleine Diskussionsforum): 77.360 Themen in 66 Foren, alle 25.759 Seitengrenzen für 5, 10, 20, 50 und 100 Themen je Seite, davon 234 mit Spaltenstatistik, 83 ohne; ein Forum mit 800 Themen las für die zweite Seite 801 statt 12 Einträge.
    - **Messung** der jetzigen Form mit 77.360 Themen in 66 Foren (MariaDB 10.11; schiefe Verteilung, sechs Status, drei Q&A-Foren, Pins ohne Frist, abgelaufen und künftig, 20 Foren mit 30 bis 3000 Themen; `ANALYZE TABLE … PERSISTENT FOR ALL`, mit und ohne Spaltenstatistik): Alle 1348 Fälle aus `TopicRepository`, `PostRepository` und `ReadStateRepository` behalten gegenüber dem Stand ohne die Nachträge (`5ab828e`) Index, Filesort-Status und Ergebnis, ohne Indexvorgabe; die zweite Form wich mit bzw. ohne Spaltenstatistik in 92 bzw. 62 Fällen ab (Forenliste in bis zu 16 Foren mit 30 bis 1200 Themen, bei 20 je Seite bis 1223 statt 44 Lesezugriffe). Die ID-Abfrage des Stroms nimmt an allen 25.759 Seitengrenzen (5, 10, 20, 50 und 100 je Seite) `idx_community_topics_forum_list` ohne Filesort mit höchstens Seitengröße + 2 Lesezugriffen. Ein Filesort bleibt nur, wo er schon ohne die Nachträge stand: `lastOfUser()`, `findRecentDuplicate()`, `PostRepository::placeholdersInRange()` und je nach Daten die abgelaufenen Pins, jeweils über wenige Zeilen eines Mitglieds, des ID-Bereichs einer Seite bzw. eines Forums. Die neuen Indizes wählt der Optimierer nur für die neuen Abfragen.
    - **Tests:** `TESTS/schema-v27` verlangt, dass nur `idx_community_topics_forum_list` mit (`forum_id`, `status`, `is_pinned`) beginnt, dass außer ihm kein Index mit `forum_id` beginnt und die ID-Abfrage des Stroms überdeckt und dass `idx_community_topics_solved` mit `solved_at` beginnt; ob der Optimierer ausweicht, hängt vom Seitenlayout ab, die Regeln prüfen deshalb die Definition. `TESTS/community-db` (Abschnitt `explain`) prüft die Pläne, auch für Pins mit `pinned_until` leer, abgelaufen und in der Zukunft, in einem großen älteren Forum (4000 Themen, erste Seite, 100 Themen, `lastPublished()`) und im ruhigen Forum (60 Themen, Folgeseite ab Position 20). `TESTS/schema-upgrade` prüft, dass die Laufzeitprüfung beide früheren Formen in Entwicklungsinstallationen ersetzt.
    - **Gescheiterter Nachtrag:** Scheitert ein `ALTER` der Laufzeitprüfung (Lock-Timeout, `KILL QUERY`, `max_statement_time`), schreibt `ensureRuntimeSchemaOnce()` die Laufzeit-Flag trotzdem (wie für alle Schritte seit v25), weitere Requests versuchen es erst nach der nächsten Änderung von `SchemaManager.php` (neuer Name der Flag) wieder. Das Datenbank-Update erkennt den Rest: `missingSchemaElements()` meldet fehlende oder abweichende Indizes aus `REQUIRED_INDEXES` (`index:<tabelle>.<name>`, Name und Spalten) und abweichenden Kommentar oder abweichende Kollation der Spalten aus `REQUIRED_COLUMN_DEFINITIONS`. Die Updates-Seite bietet dann *Datenbank-Update* an; es holt den Schritt nach und meldet keinen Erfolg, solange er scheitert (`TESTS/schema-upgrade`, Weg „Gescheiterter Nachtrag“). Bricht der Prozess selbst ab, fehlt die Flag, und der nächste Request holt alles nach.
10. **Lösung und Filter „ungelöst“:** Dienste setzen bzw. leeren `solved_at` (UTC) genau dann, wenn sie `solution_post_id` setzen bzw. leeren, in derselben Anweisung (`solved_by` ebenso). Listen filtern nur über `solved_at`, nie über `solution_post_id`. Abfrageformen (Keyset wie in der Forenliste, angeheftete Themen weiter über `TopicRepository::pinned()`):
    - ungelöst je Forum in zwei Schritten wie die Forenliste: die IDs über `SELECT id, last_post_at … WHERE forum_id = ? AND status = ? AND is_pinned = 0 AND solved_at IS NULL [Keyset] ORDER BY last_post_at DESC, id DESC LIMIT ?` (nur Indexzugriff auf `idx_community_topics_solved`) und, wie in `listForum()`, die ungelösten Themen mit abgelaufener Anheftung über `SELECT * … WHERE forum_id = ? AND status = ? AND is_pinned = 1 AND pinned_until IS NOT NULL AND pinned_until <= ? AND solved_at IS NULL [Keyset] ORDER BY last_post_at DESC, id DESC LIMIT ?` (`?` ist die Zeit der Uhr in UTC, Nr. 4). Ein Thema mit abgelaufener Frist behält `is_pinned = 1`, bis der Cron aufräumt, und den gibt es noch nicht; `listForum()` zeigt es im Strom, `pinned()` nicht. Ohne die zweite Abfrage fehlte es in „ungelöst“ und in „ungelöste zuerst“. Beide Teillisten sind auf Seitengröße + 1 begrenzt, werden nach (`last_post_at`, `id`) zusammengeführt, und der Cursor ist der der letzten Zeile der Vereinigung; danach die Zeilen der ID-Abfrage über `id IN (…)` (Primärschlüssel). Gemessen mit den Daten aus Nr. 9 und je 2 bis 5 ungelösten abgelaufenen Pins in den Q&A-Foren, mit und ohne Spaltenstatistik: die ID-Abfrage an allen 24.278 Seitengrenzen (5, 10, 20, 50 und 100 je Seite) über `idx_community_topics_solved`, nur Indexzugriff, höchstens Seitengröße + 2 Lesezugriffe; die Pin-Abfrage über `idx_community_topics_forum_list` oder `idx_community_topics_solved` ohne Filesort mit höchstens 15 Lesezugriffen (mit 80 ungelösten abgelaufenen Pins in einem Forum höchstens 84); komplett durchgeblättert (Foren mit 23, 277 und 1273 ungelösten Themen, 5, 20 und 100 je Seite) vollständig und in der Reihenfolge der Forenliste, bei 20 je Seite höchstens 52 Lesezugriffe. Mit `SELECT *` statt der ID-Abfrage wählte MariaDB 10.11 ohne Spaltenstatistik (nur `ANALYZE TABLE`, der Normalfall) teils `idx_community_topics_forum_list`, die dieselbe Sortierung liefert, und las das ganze Forum (926 Lesezugriffe für 14 Treffer in einem Forum mit 925 Themen); die ID-Abfrage blieb in jedem Lauf auf dem neuen Index (15 Lesezugriffe);
    - „ungelöste zuerst“ im Q&A-Forum: erst diese Liste samt ungelösten abgelaufenen Pins, danach als zweiter Strom die gewöhnliche Forenliste über `TopicRepository::listForum()`, ungelöste Zeilen dort in PHP überspringen (auch die ungelösten abgelaufenen Pins, die schon der erste Strom zeigt). Komplett durchgeblättert in den drei Q&A-Foren aus Nr. 9 (5, 20 und 100 je Seite): jedes Thema genau einmal, in dieser Reihenfolge. `listForum()` läuft seit dem Nachlauf ebenfalls in zwei Schritten: `SELECT id, last_post_at … WHERE forum_id = ? AND status = ? AND is_pinned = 0 [Keyset] ORDER BY last_post_at DESC, id DESC LIMIT ?` (nur Indexzugriff auf `idx_community_topics_forum_list`), danach die Zeilen über `id IN (…)`; eine Zeile, die beim zweiten Schritt nicht mehr zur Liste gehört (anderes Forum, anderer Status, angeheftet, gelöscht), fehlt auf der Seite, der Cursor bleibt der des ersten Schritts. Mit `SELECT *` wählte MariaDB 10.11 in großen Foren bei einem Cursor in der oberen Hälfte der Liste `ref` statt `range`; der Keyset wirkte nur noch als Filter, und die Lesezugriffe wuchsen mit der Position (Forum mit 15.000 Themen, 13.186 im Strom: Position 250 mit 271, Position 6000 mit 6021; 69.000 Themen mit schiefer Verteilung: größtes Forum bis 6771 an Position 6750, Q&A-Forum mit 6393 Themen im Strom bis 421 an Position 400). Die ID-Abfrage blieb mit Cursor an allen Positionen `range` mit höchstens 22 Lesezugriffen, `listForum()` samt abgelaufenen Pins und Zeilen liest dort 53 bis 58. Flache Seiten kosten dafür eine Anweisung und je Zeile einen Zugriff über den Primärschlüssel mehr (69.000 Themen, 306 Aufrufe in neun Foren mit allen Status, Seitengrößen 20 und 100 und Positionen bis 12.000: gleiches Ergebnis, höchstens 275 statt 5124 Lesezugriffe; die ID-Abfrage nahm mit und ohne Spaltenstatistik immer `idx_community_topics_forum_list` ohne Filesort). In kleinen Foren galt das mit `idx_community_topics_solved` in der Form (`forum_id`, `solved_at`, …) nicht (Nr. 9); seit er mit `solved_at` beginnt, kann der Strom ihn nicht nutzen. Die Foren des Abschnitts `explain` von `TESTS/community-db` (rund 900 Themen) zeigen das nicht; der Abschnitt `explain-forum-large` prüft `listForum()` deshalb mit 15.000 Themen und Cursorn bis Position 6000. Ein SQL-Filter `solved_at IS NOT NULL` ließ MariaDB auf `idx_community_topics_activity` ausweichen (417 Lesezugriffe für 21 Zeilen), ein gemeinsames `ORDER BY (solved_at IS NULL) DESC, …` erzeugt ein Filesort;
    - „Unbeantwortet“ global in zwei Schritten wie „ungelöst“: `SELECT id, created_at … WHERE status = 'published' AND reply_count = 0 [Keyset] ORDER BY created_at DESC, id DESC LIMIT ?`, Keyset `(created_at < ? OR (created_at = ? AND id < ?))` (nur Indexzugriff auf `idx_community_topics_unanswered_all`), danach die Zeilen über `id IN (…)`. Mit `SELECT *` wählte MariaDB 10.11 bei einem Cursor im ersten Viertel der Liste `ref` statt `range` auf demselben Index; der Keyset wirkte nur noch als Filter, und die Lesezugriffe wuchsen mit der Position (60.000 Themen, 7544 Treffer: Position 250 mit 272, Position 1500 mit 1522 Lesezugriffen; die ID-Form an beiden mit 22). Die Daten des Abschnitts `explain` von `TESTS/community-db` (24.060 Themen, rund 2600 Treffer) zeigen das nicht; der Abschnitt `explain-unanswered` prüft die ID-Form deshalb zusätzlich mit 60.000 Themen;
    - „Unbeantwortet“ mit Rechtefilter `forum_id IN (lesbare Foren)` in zwei Schritten wie „ungelöst“: `SELECT id, created_at … AND forum_id IN (…) [Keyset] ORDER BY created_at DESC, id DESC LIMIT ?`, danach die Zeilen über `id IN (…)`. Plan und Lesezugriffe hängen von der Dichte der lesbaren Foren und von der Listengröße ab: Mit `range` auf `idx_community_topics_unanswered_all` ist `forum_id IN` nur ein Filter, jede Seite liest dann rund 21 × (alle Treffer ÷ Treffer der lesbaren Foren), an jeder Position; bei wenigen Treffern und am Listenende (etwa die letzten 8 bis 10 % der Liste) wählt MariaDB 10.11 `idx_community_topics_unanswered` mit Filesort über die restlichen Treffer der lesbaren Foren, und dieser Rest wächst mit der Liste. Gemessen in der ID-Form mit 40.060 Themen, eine Seite je 100 Positionen: Foren 1 bis 30 (4292 Treffer) 152 Lesezugriffe an Position 4200, alle 41 Foren höchstens 551 an Position 4500 von 4969, Foren 38 bis 40 (195 Treffer) immer Filesort mit 198. Mit 69.000 Themen (8413 Treffer), eine Seite je 10 Positionen: 3 Foren (380 Treffer) 559 an Position 70 (`range`), die 8 kleinsten Foren (389 Treffer) 640 an Position 80, alle 46 Foren ab Position 7620 Filesort mit höchstens 885. Diese Zahlen sind Messwerte, keine Grenze. Das Gate in PR #4 lässt beide Indizes zu und legt die Obergrenze der Lesezugriffe relativ zu diesen Größen fest (Verhältnis aller Treffer zu den Treffern der lesbaren Foren, Listengröße) oder begrenzt die Seitentiefe; darf das Mitglied alle Foren lesen, entfällt `forum_id IN` (Form ohne Rechtefilter, an allen Positionen höchstens 22). Mit `SELECT *` wich MariaDB 10.11 auf Folgeseiten auf `ref` bzw. `index_merge` mit Filesort aus und las mit der Seitentiefe wachsend (Daten von `TESTS/community-db`, 15 von 20 Foren, Position 1500: 755 Lesezugriffe gegen 31 in der ID-Form; 40.060 Themen, 30 von 40 Foren, Position 3289: 3953 gegen 903 im alten Stand mit Filesort).

### 2a. Festlegungen aus PR #2a

1. **Einstellungen:** Gruppe `community`, Option `community.<schlüssel>`, flache snake_case-Schlüssel (nicht gepunktet), Zugriff nur über `Support\Settings` (einmal `getGroup('community')` je Request; kein Installer und kein Update-Schritt schreibt Standards, `save()` schreibt nur Werte, die vom wirksamen Wert abweichen, siehe Nr. 10). Nur Schlüssel mit Leser: `forum_max_depth` ist in #2a **entfallen** (kein Leser) und kommt mit der Forenverwaltung in PR #3 zurück (`ForumRepository::create($data, $maxDepth, $nowUtc)` nimmt die Tiefe als Parameter). Jede neue Einstellung braucht einen Leser im Code und einen Abschnitt „Wirkung: <schlüssel>“ in einer Suite; `community-settings` prüft beides.
2. **Modul:** `community` ist abschaltbar und standardmäßig **aus**. `CommunityHooks::register()` meldet nichts an, solange das Modul aus ist oder die Prüfung scheitert (fail-closed). `DSGVO`-Hooks und Löschfristen (PR #5) müssen auch bei abgeschaltetem Modul laufen: dafür einen Teil „immer“ in `CommunityHooks` anlegen, nicht hinter das Gate stellen.
3. **Zeit:** alles UTC über eine Uhr-Closure (`\Closure(): int`), gebunden als `gmdate('Y-m-d H:i:s', $ts)`; verboten in `Community/**`: `NOW()`, `CURRENT_TIMESTAMP`, `CURDATE()`, `UTC_TIMESTAMP()`, `date(`, `OFFSET` (Wächter in `community-content`). `Support\I18n::date()` rechnet für die Anzeige in die Site-Zeitzone.
4. **Datenbank:** nur `execute()`; Transaktionen über `Support\Transaction::run()` (Savepoint verschachtelt, Wiederholung bei Deadlock 1213/Lock-Timeout 1205); Zähler nur atomar; **Sperrreihenfolge Forum → Thema → Beitrag → `community_user_stats`** (gilt für #2b), und zwar **alle Sperren vor der ersten nicht sperrenden Leseanweisung der Transaktion** (sonst sieht `can(fresh)` unter REPEATABLE READ einen veralteten Schnappschuss und Zeit- und Zählgrenzen lassen sich mit gleichzeitigen Anfragen umgehen); gesperrte Zeilen müssen vorher existieren (die Statistikzeile des Mitglieds entsteht per `UserStatsRepository::ensure()` vor der Transaktion im Autocommit, die Mutex-Zeile `community_lock_forum_slugs` der Forum-Slugs ebenso), sonst entstehen Lückensperren und Deadlocks; Ablehnungen aus Zeit- oder Zählgrenzen (`rate_limited`, `slow_mode`, `trust_limit`, `pending_limit`) sind vorläufig (eine Dublette liefert das vorhandene Ergebnis), alle anderen endgültig. SQL muss in beiden Datenbanken laufen (MariaDB und das SQLite-Double der CI): kein `ON DUPLICATE KEY`, `GREATEST`, `JSON_*`, Zeilenkonstruktoren; `FOR UPDATE` nur über `AbstractRepository::forUpdate()` (das Double entfernt den Anhang).
5. **Zugriff (`CommunityAccess`, maßgeblich ist der Docblock der Klasse):** Jeder Schritt lehnt ab oder gibt an den nächsten weiter, kein Schritt hebt die Ablehnung eines früheren auf, und jede Erlaubnis läuft zuletzt durch den Filter (Schritt 9). Schritt 4 lehnt mit `no_capability` ab (bei `read` `not_found`) und entscheidet `ban`, `manage` und `view_private_data` ohne die Schritte 5 bis 8, ebenso Entscheidungen ohne Ziel (`read` und `search` mit `scope => 'global'`, `moderate` für globale Moderation; `decideWithoutTarget()`); der Filter (Schritt 9) kann sie weiterhin ablehnen. **Globale Moderation** (`admin` oder `community.moderate`) braucht in Schritt 4 weder `community.view` noch `.participate` und überspringt die Schritte 6 (Rechtematrix) und 7 (Tages- und Stundenlimit, Deckel `max_pending_per_user`, Abstände, Langsamer Modus). **Forum-Moderatoren** (Schritt 5: Mitglied oder aktive Gruppe, mit `include_children` auch in Unterforen) überspringen im eigenen Forum die Leseprüfung der Matrix und verborgene Vorfahren, das Bearbeitungsfenster, den Langsamen Modus, die Abstände `rate_post_seconds`/`rate_topic_seconds` und die Freigabepflicht. Sie sehen wartende, ausgeblendete und gelöschte Themen sowie wartende, abgelehnte und ausgeblendete Beiträge (gelöschte Beiträge voll nur mit `community.view_private_data`; Themen im Papierkorb sieht nur `admin`, auch nicht die globale Moderation über `community.moderate`), bearbeiten und löschen fremde Beiträge, legen in Foren vom Typ `announcements` Themen an, schreiben in `readonly`-Foren und in geschlossenen, wartenden und ausgeblendeten Themen, markieren Lösungen auch in fremden Themen und außerhalb von Q&A-Foren und führen alle Moderationsaktionen aus, außer `topic.announce` (nur global) und Verschieben in ein Forum, das sie nicht moderieren. **Nicht übersprungen** wird für Forum-Moderatoren die Rechtematrix bei gewöhnlichen Schreibaktionen (`create_topic` außerhalb von Ankündigungsforen, `reply`, `upload`, `poll`, `vote`, `react`, `mark_solution`) und das Tages- und Stundenlimit der Stufe (`trust_limit`); für jede Moderation der Zustand `archived` (sperrt auch Moderationsaktionen) und bei Schreibaktionen Foren vom Typ `link` (keine Themen, keine Antworten) sowie Themen in `deleted`, `trash` oder `moved` (`decideContent()`, `topicStateReason()`). Moderationsaktionen prüfen den Themenzustand nicht (`decideModeration()`: Moderatorrecht, `topic.announce`, Zielforum, `archived`): Ein Forum-Moderator darf z. B. `topic.lock` auf ein gelöschtes oder verschobenes Thema anwenden. Das Thema muss aber sichtbar sein: Auf ein Thema im Papierkorb ergibt `read` und jede Moderationsaktion außer für `admin` `not_found`. Der Moderationsdienst (#2b) legt fest, welche Aktionen dort zulässig sind, und ergänzt die Prüfung. Sperren gelten vor der Moderation (nicht für `admin`); `trust:N` heißt Stufe ≥ N, `trust:0` alle Angemeldeten; hat die Vererbungskette eines Forums Rechte-Zeilen, gelten nur diese (kein Rückfall auf die Standards je Subjekt, sonst rutschten `editor`/`author`/eigene Rollen in interne Foren); eine eigene Zeile ersetzt die geerbte desselben Subjekts. `guests_can_read` ist standardmäßig aus, `require_verified_email` an (Rolle `admin` ausgenommen; im Core ist die Bestätigungspflicht standardmäßig aus: ohne sie können Mitglieder nicht schreiben, bis sie bestätigt sind). Ziele, die der Akteur nicht sehen darf, ergeben `not_found` auch bei Schreibaktionen; gelöschte Beiträge zeigen Platzhalter (voll nur mit `community.view_private_data`); der Filter `cms_community_can` darf nur ablehnen oder verschärfen. Die Zahlen der Vertrauensstufen ab Stufe 2 und die Grenzen `trust_limits` sind **Platzhalter**, die der Auftraggeber bestätigen muss.
6. **Vertrauensstufen (P1-Minimum):** Stufe 0/1/2 aus E-Mail-Bestätigung und Beitragszahl ohne Cron, Stufe 3/4 nur manuell (`trust_level_locked`); Freigabepflicht über `post_count < new_member_approval_posts`, Deckel `max_pending_per_user`. Link-, Bild- und Anhangsgrenzen und Antispam folgen mit dem Renderer in #2b (bis dahin bewusst nicht durchgesetzt).
7. **Dienste:** Doppeltes Absenden verhindert ein Inhaltsvergleich im Fenster `duplicate_window_seconds` unter der Zeilensperre (Variante B); der Sitzungsschlüssel (Variante A) gehört in den Controller (PR #4). `pending` zählt in keinem Zähler; die **Freigabe in #2b muss nachbuchen** (Forum- und Themenzähler, `last_*`, Statistik des Autors) und darf dabei keine veröffentlichte Antwort in einem wartenden Thema doppelt buchen. Beitragstexte werden roh gespeichert (`body_html` NULL, `rendered_version` 0, `ip_hash` NULL); der Renderer in #2b befüllt `body_html` beim ersten Lesen. Die Beitragsliste läuft mit **einem Status je Abfrage** (`published`), Platzhalter (`hidden`/`deleted`) per zweiter Abfrage über den ID-Bereich der Seite; Themenliste mit Cursor `(last_post_at, id)`, angeheftete Themen nur auf der ersten Seite; `OFFSET` gibt es nicht. Den Strom liest `listForum()` in zwei Schritten (IDs über `idx_community_topics_forum_list`, dann die Zeilen über den Primärschlüssel, §2 Nr. 10).
8. **Core-Hook `user_role_changed`:** wird jetzt je Konto bei Admin-Änderung, Sammelaktion, Umbenennen und Löschen einer Rolle ausgelöst (nach dem Schreiben bzw. Commit, nur bei tatsächlichem Wechsel, Ausnahmen von Verbrauchern werden protokolliert). **Bekannte Lücke:** `community_forum_permissions.subject_key` trägt den Rollen-Slug; Umbenennen oder Löschen einer Rolle lässt diese Zeilen veraltet zurück, und eine später mit demselben Slug angelegte Rolle erbt sie. Dafür braucht es Rollen-Hooks (`cms_role_renamed`, `cms_role_deleted`) und eine Aufräumung, spätestens in PR #3.
9. **Tests:** `community-mutants` verlangt je Mutante Exit 1 der zuständigen Suite (Katalog `TESTS/community-mutants/catalog.php`, `CMS_TEST_MUTANTS=all` für den vollen Lauf); `community-db` und die Mutanten für die Datenbank laufen nur mit `CMS_TEST_DB_HOST`/`CMS_TEST_DB_USER` (in der CI nicht aktiv; Lauf als Nachweis in der PR-Beschreibung).

10. **Nachlauf zu PR #2a (Befunde der unabhängigen Prüfung):**
    - **Ziellose Abfragen:** `can()` erlaubt `read`/`search` ohne Ziel nur mit `context['scope'] = 'global'` (Startseite, Suche); ein leerer Zielschlüssel (`forum_id => null`) oder ein Array unter `*_id` ist ein unbekanntes Ziel (`not_found`). Zeilen nimmt `can()` nur unter `forum`, `topic`, `post` an; der Kontext stammt nur aus Dienst- oder Controller-Code, nie aus Anfragedaten.
    - **Filter `cms_community_can`:** läuft Filter für Filter (`Support\Events::filters()` über `Hooks::getCallbacks()`); die erste Ablehnung steht fest, Verschärfungen bleiben, Erweiterungen werden verworfen und protokolliert, eine Ausnahme gilt als Ablehnung. Bei `read`/`search` wird jede Filter-Ablehnung zu `not_found` (keine Unterscheidung „gibt es nicht“/„darfst du nicht“).
    - **Ereignisse:** `Support\Events::fire()` sichert jeden Verbraucher einzeln ab (`Hooks::doActionIsolated()`); ein werfender Verbraucher hindert die übrigen nicht.
    - **Kontingente:** Tages-, Stunden-, Abstands- und Langsamer-Modus-Grenzen zählen jeden im Fenster angelegten Beitrag und jedes Thema unabhängig vom heutigen Status (auch gelöscht, abgelehnt, ausgeblendet; verschobene Themen nicht): eigenes Löschen gibt kein Kontingent frei.
    - **fail-closed:** ein Forum mit unbekanntem Status oder beschädigtem `path` gilt als verborgen. **Bewusst nicht vererbt:** ein `readonly`- oder `archived`-Elternforum macht seine Unterforen nicht schreibgeschützt; die Forenverwaltung (PR #3) setzt den Zustand je Forum, auch beim Archivieren eines Teilbaums.
    - **Lesepfade:** `PostService::listPage()` gibt Thema und Forum jedem `can('read')` mit (eine Seite mit 100 Beiträgen: wenige Abfragen statt über 300); `readableForumIds()`/`filterReadable()` prüfen mit einer Abfrage, ob überhaupt Rechte-Zeilen existieren (ohne Zeilen keine Abfrage je Forum). Offen für #2b: eine Mengenabfrage der Rechte- und Moderatorenzeilen aller Foren für Bäume mit vielen Rechte-Zeilen.
    - **Zitate:** `QuoteService` entschärft jedes Muster `[quote=post:…]` im übernommenen Text (Backslash), Marker ist nur die erste Zeile des erzeugten Blocks.
    - **Grenzfälle:** ungültige Cursor (auch unmögliche Zeitpunkte) ergeben die erste Seite, `afterId` wird geklemmt; Titel, Texte und Bearbeitungsgründe ohne sichtbare Zeichen (Format- und Steuerzeichen) gelten als zu kurz; `topics.last_post_at` fällt nach dem Löschen der einzigen veröffentlichten Antwort eines wartenden Themas auf `created_at` zurück (nie NULL); reservierte Segmente sind auch beim Schreiben von Forum-Slugs gesperrt; Spaltenlängen und Bereiche prüfen `ForumRepository`/`CategoryRepository` vor dem Schreiben (`invalid_input`); `isDuplicateKey()` erkennt nur echte UNIQUE-Verletzungen (1062); der Fehlercode `not_deletable` entfällt.
    - **Transaktionen:** Savepoints heißen `cms_community_tx_<Tiefe>` (beliebig tief verschachtelbar); wiederholt wird nur bei Deadlock (1213) und SQLSTATE 40001, **nicht** bei Lock-Timeout (1205: einmal warten, dann `db_error`).
    - **Für PR #2b/#4:** `TopicService::delete()` liefert für ein bereits gelöschtes Thema dem Starter und der Forum-Moderation `already_deleted` (der Controller behandelt das wie einen Erfolg, ohne zweiten Hook); der Anheft-Dienst muss höchstens 50 angeheftete Themen je Forum erzwingen (`TopicRepository::MAX_PINNED`, mehr erscheinen in keiner Liste); Cron und Worker rufen `CommunityAccess::flush()` je Auftrag.
    - **Bewusst offen:** Rollenrechte fallen bei Lesefehlern in `cms_load_role_capabilities()` auf die Code-Standards zurück (Rollenverwaltung); `Settings::save()` kann Standardwerte schreiben (Einstellungsseite PR #3); `base_path` kennt die festen Core-Routen nicht (PR #4); `slug_en` der Kategorien ist nicht eindeutig (bräuchte eine Schemaänderung); `ReadStateRepository::advance()` kann bei gleichzeitigen Aufrufen desselben Mitglieds und Themas in einen wiederholten Deadlock laufen; der Ungelesen-Zähler liest im Fall „alles gelesen“ alle Themen des Horizonts (Deckelung mit der Startseite in PR #4).

**Was PR #2b übernehmen muss:** Renderer (`league/commonmark`, `PurifierService`-Profil `community`, `rendered_version`), Moderationsdienst mit Freigabe (Nachbuchen der Zähler, siehe 7), Verschieben mit Verweis (`community_redirects`), Meldungen, Sperren-/Verwarnungsdienst (`BanRepository` ist nur lesend), Benachrichtigungen (auch `group_key`/`event_count`), Abos und Auto-Abo (Listener auf `cms_community_topic_created`/`post_created`), Reaktionen, Ränge, Anhänge, Suche, Antispam/Wortfilter/Link-/Bild-/Anhangsgrenzen, `ip_hash` (HMAC, Löschfrist 30 Tage), die Hooks `cms_community_post_approved|rejected`, `topic_moved|merged`, `cms_community_render_post` (Namen und Konstanten stehen bereits in `Support\Events`), Sprachschlüssel für die Zitat-Platzhalter, eine Mengenabfrage der Rechte-Zeilen mehrerer Foren für die Startseite (`readableForumIds()` braucht heute 2 bis 3 Abfragen je Forum).

### 3. Schema v27

28 Tabellen mit Präfix `{prefix}community_` (Standard `cms_community_…`; 27 aus PR #1, dazu `community_notify_queue` aus PR #2b, Block „Betrieb“); Engine InnoDB, Kollation `utf8mb4_unicode_ci`. Einstellungen liegen in `settings` (Gruppe `community`), nicht in einer Tabelle. Die Spalten- und Indexlisten wurden aus einer echten MariaDB-Installation erzeugt; `TESTS/schema-v27` vergleicht sie mit den Definitionen. Die Wertebereiche der Statusfelder stehen im Text je Tabelle (keine Datenbank-ENUMs, wie `posts.status` seit v26).

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

`status`: `published` · `pending` · `hidden` · `deleted` · `trash` · `moved` (Verweis: `moved_to_topic_id`, `moved_expires_at`). `user_id` NULL = gelöschtes Mitglied (anonymisiert). Die Adresse löst über `id` auf; `slug` dient nur der URL. `view_count` wird gepuffert (Cron verbucht alle 5 Minuten). Papierkorb: `trashed_at`, `trashed_by`, `previous_status` (wie bei Beiträgen, Seiten und Hub-Sites).

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
| `trashed_by` | int(10) unsigned | NULL |
| `previous_status` | varchar(20) | NULL |
| `created_at` | datetime | NOT NULL, Default current_timestamp() |
| `updated_at` | datetime | NOT NULL, Default current_timestamp() |

| Schlüssel | Art | Spalten | Zweck |
|---|---|---|---|
| PRIMARY | Primärschlüssel | `id` |  |
| `idx_community_topics_activity` | Index | `status`, `last_post_at` | globale Liste „Aktiv“ und „Neu“ |
| `idx_community_topics_announce` | Index | `is_announcement`, `status`, `last_post_at` | Ankündigungen oben in den Foren einer Kategorie |
| `idx_community_topics_forum_list` | Index | `forum_id`, `status`, `is_pinned`, `last_post_at` | Themenliste eines Forums: angepinnt zuerst, dann letzte Aktivität (Keyset über `last_post_at`, `id`; der Strom als ID-Abfrage in zwei Schritten, auch der zweite Strom von „ungelöste zuerst“, Abschnitt 2, Punkt 10) |
| `idx_community_topics_locked` | Index | `is_locked`, `locked_until` | Cron: befristetes Schließen aufheben |
| `idx_community_topics_moved` | Index | `status`, `moved_expires_at` | Cron: abgelaufene Verweis-Themen (`status = moved`) |
| `idx_community_topics_pinned` | Index | `is_pinned`, `pinned_until` | Cron: befristetes Anpinnen aufheben |
| `idx_community_topics_solved` | Index | `solved_at`, `forum_id`, `status`, `is_pinned`, `last_post_at` | Filter „ungelöst“ je Forum (`solved_at IS NULL`, ID-Abfrage nur über den Index, dazu die ungelösten abgelaufenen Pins) und erster Strom von „ungelöste zuerst“ im Q&A-Forum (Keyset über `last_post_at`, `id`; Abschnitt 2, Punkt 10); beginnt mit `solved_at`, damit der Strom der Forenliste ihn nicht nutzt (Abschnitt 2, Punkt 9) |
| `idx_community_topics_trashed` | Index | `trashed_at` | Papierkorb: abgelaufene Einträge endgültig löschen |
| `idx_community_topics_unanswered` | Index | `forum_id`, `status`, `reply_count`, `created_at` | „Unbeantwortet“ je Forum |
| `idx_community_topics_unanswered_all` | Index | `status`, `reply_count`, `created_at` | globale Liste „Unbeantwortet“ (`/forum/unbeantwortet`), mit und ohne `forum_id IN (…)` der lesbaren Foren immer als ID-Abfrage in zwei Schritten (Abschnitt 2, Punkt 10; Keyset über `created_at`, `id`) |
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
| `idx_community_subscriptions_mode` | Index | `mode`, `user_id` | Zusammenfassungs-Cron: Abonnenten je Modus (`daily`, `weekly`) |
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
| `idx_community_read_states_topic` | Index | `topic_id` | Zusammenführen und Löschen eines Themas: Lesestatus je Thema ohne Tabellenscan |

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

Abzeichen (Oberfläche ab P3); `rule_json` NULL = manuell vergeben. Titel und Beschreibung auch englisch (`title_en`, `description_en`, COM-CORE-13).

| Spalte | Typ | Eigenschaften |
|---|---|---|
| `id` | int(10) unsigned | NOT NULL, AUTO_INCREMENT |
| `slug` | varchar(60) | NOT NULL, Default '' |
| `title` | varchar(80) | NOT NULL, Default '' |
| `title_en` | varchar(80) | NULL |
| `description` | varchar(255) | NULL |
| `description_en` | varchar(255) | NULL |
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
| `idx_community_reports_handled` | Index | `status`, `handled_at` | Löschfrist `community.reports`: erledigte Meldungen nach `handled_at` |
| `idx_community_reports_open` | Index | `status`, `created_at` | Meldungen nach Status, älteste zuerst (Bearbeitung, Badge) |
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

#### `community_notify_queue`

Offene Zustellaufträge der Benachrichtigungen (PR #2b, einzige Schemaänderung nach PR #1; Vertrag DESIGN-2b §3.9, §4.5). Ein Auftrag je Ereignis, angelegt von den Listenern nach dem Commit des Auslösers, zugestellt im Cron (`NotificationDispatcher::runQueue()`); erledigte Aufträge werden gelöscht, Aufträge älter als 48 Stunden verfallen. `kind`: `reply` · `new_topic` · `reaction` · `solution` · `moderation` · `report`. `post_id` trägt bei `report` die Meldungs-ID. `detail`: Reaktion, `approved` · `rejected` · `hidden` · `moved` bzw. der Meldestatus. `phase` und `cursor_user_id` sind der Cursor des Fan-outs (genau einmal je Auftrag und Empfänger), `lease_token` und `locked_until` die Lease eines Laufs, `attempts` zählt abgebrochene Leases (über 5: gelöscht). Bestandsinstallationen v27 erhalten die Tabelle über `ensureFeatureSchema()`. PR #5: Löschfrist und DSGVO-Behandlung von `actor_id`.

| Spalte | Typ | Eigenschaften |
|---|---|---|
| `id` | bigint(20) unsigned | NOT NULL, AUTO_INCREMENT |
| `kind` | varchar(12) | NOT NULL, Default '' |
| `topic_id` | int(10) unsigned | NOT NULL, Default 0 |
| `post_id` | bigint(20) unsigned | NOT NULL, Default 0 |
| `actor_id` | int(10) unsigned | NOT NULL, Default 0 |
| `detail` | varchar(20) | NULL |
| `phase` | tinyint(3) unsigned | NOT NULL, Default 0 |
| `cursor_user_id` | int(10) unsigned | NOT NULL, Default 0 |
| `attempts` | tinyint(3) unsigned | NOT NULL, Default 0 |
| `steps` | int(10) unsigned | NOT NULL, Default 0 |
| `lease_token` | char(32) | NULL |
| `locked_until` | datetime | NULL |
| `created_at` | datetime | NOT NULL, Default current_timestamp() |
| `updated_at` | datetime | NOT NULL, Default current_timestamp() |

| Schlüssel | Art | Spalten | Zweck |
|---|---|---|---|
| PRIMARY | Primärschlüssel | `id` |  |
| `idx_community_notify_queue_kind` | Index | `kind` | Stufen der Zustellung (`dueIds()` je Stufe, innerhalb nach `id`) |

### 4. Capabilities und Rolle

| Capability | Wirkung | Standard (`capabilities_v27`) |
|---|---|---|
| `community.view` | Community sehen (Gäste über die Einstellung „Gäste dürfen lesen“) | `member`, `editor`, `author`, `moderator` |
| `community.participate` | Schreiben, zusätzlich zu den Rechten je Forum | `member`, `editor`, `author`, `moderator` |
| `community.moderate` | globale Moderation: Warteschlange, Meldungen, Verwarnungen, befristete Sperren | `moderator` |
| `community.ban` | dauerhafte Sperren, fremde Sperren aufheben | nur `admin` (implizit) |
| `community.manage` | Struktur, Rechte, Ränge/Abzeichen, Einstellungen, Statistik | nur `admin` (implizit) |
| `community.view_private_data` | gekürzte IP-Kennung, Bearbeitungsverlauf gelöschter Beiträge | nur `admin` (implizit) |

**Wo sie stehen** (die Listen in `CommunityCapabilities`, `RolesModule` und `roles.php` sind identisch; `TESTS/schema-v27` prüft das. `UserService::DEFAULT_ROLE_CAPABILITIES` ist ein älterer Rückfall mit anderem Umfang, den `cms_load_role_capabilities()` nie erreicht: Der Test sichert nur, dass er außer `community.moderate` für `moderator` keine erhöhten Rechte enthält):

- `CommunityCapabilities` (Konstanten `VIEW` … `VIEW_PRIVATE_DATA`, `ALL`, `DEFAULT_GRANTS`, `MODERATOR_BASE`, `ELEVATED`, `MARKER`): maßgebliche Quelle für Namen und Migration.
- `RolesModule::CAPABILITIES['community']` (die Konstante heißt `CAPABILITIES`, **nicht** `CAPABILITY_GROUPS` wie in den Planungsdokumenten): Katalog des Rollen-Editors. Fehlt eine Capability dort, erscheint sie im Rollen-Editor nur als „eigenes Recht“ in einer aus dem Präfix abgeleiteten Gruppe (`getKnownCapabilities()` liest zusätzlich `SELECT DISTINCT capability FROM role_permissions`), und „Berechtigungen speichern“ (`savePermissions()` löscht `role_permissions` und schreibt Rolle × bekannte Capability neu) schreibt für jede nicht angehakte bekannte Capability `granted = 0`: Zeilen für Rechte außerhalb des Katalogs (z. B. `read`) würden so anderen Rollen entzogen. Neue Capabilities gehören deshalb in den Katalog, und eine Migration trägt nur Katalog-Rechte ein. `RolesModule::getDefaultPermissions()` liefert die Standards für „Standardrechte wiederherstellen“ und die Matrix-Anzeige.
- `cms_get_default_role_definitions()` in `roles.php`: Code-Standards, die `cms_load_role_capabilities()` mit den Zeilen aus `role_permissions` überlagert (eine Zeile gewinnt immer; `granted = 0` entzieht ein Standardrecht).
- `UserService::REGISTRATION_BLOCKED_CAPABILITIES`: `community.moderate`, `.ban`, `.manage`, `.view_private_data` sperren eine Rolle für die Registrierung; `community.view`/`participate` bleiben erlaubt. `moderator` ist zusätzlich nie als Registrierungsrolle wählbar. `IdentityLinker::PRIVILEGED_ROLES` enthält `moderator` (keine automatische Entra-Verknüpfung per E-Mail).

**Die Rolle `moderator`** ist Systemrolle (`RolesModule::ROLES`, `remove_role()`, `UserService::DEFAULT_ROLES`): nicht umbenenn- oder löschbar, Anzeigename „Moderator“. Sie hat die Grundrechte von `member` (`read`, `edit_profile`, `view_content`, `pages.view`, `posts.view`, `media.view`, `comments.view`) plus `community.view`, `community.participate`, `community.moderate`; **keinen** Zugang zu `/admin`. Die Grundrechte stehen nur in den Code-Standards (`roles.php`), **nicht** als Zeilen in `role_permissions`: `read`, `edit_profile` und `view_content` sind nicht im Rollen-Katalog, erschienen im Rollen-Editor als „eigene Rechte“, und das Speichern der Matrix schriebe für Mitglied, Editor und Autor `granted = 0` (ein Zeilen-Eintrag gewinnt gegen die Code-Standards). Der Schritt trägt daher nur die sechs Katalog-Zeilen `community.*` ein (9 Zeilen: member 2, editor 2, author 2, moderator 3). Eine Rolle `moderator` mit Konten oder Zeilen in `role_permissions` kann es schon geben (der Rollen-Editor nannte sie als Beispiel): Sie wird zur Systemrolle, und die Standards (Grundrechte, `community.view`, `.participate`, `.moderate`) gelten wirksam zusätzlich zu ihren gespeicherten Zeilen; ein ausdrücklicher Entzug bleibt. Das steht im Changelog als Hinweis für Betreiber (Rolle vor dem Update prüfen).

**Entscheidung: übrige Rollen.** `subscriber`, `contributor` und eigene Rollen, die vor dem Update bestehen, erhalten `community.view` und `community.participate` **nicht automatisch**: `capabilities_v27` trägt nur `CommunityCapabilities::DEFAULT_GRANTS` ein (`member`, `editor`, `author`, `moderator`), und die Code-Standards von `subscriber` und `contributor` in `roles.php` enthalten kein `community.*`. Ohne `community.view` lehnt `CommunityAccess` in Schritt 4 jede Aktion eines angemeldeten Kontos ab (`not_found` beim Lesen, sonst `no_capability`), unabhängig von der Rechtematrix je Forum; Gäste lesen dagegen, wenn `guests_can_read` an ist. Betreiber vergeben die Rechte im Rollen-Editor (Gruppe „Community“). Eine nach dem Update angelegte Rolle übernimmt die Katalog-Rechte ihrer Vorlage (`RolesModule::addRole()`, `copy_role`, Standard `member`), also auch `community.view`/`.participate`. PERMISSIONS-MODERATION §1 („weitere Core-Rollen erhalten standardmäßig die Mitgliederrechte“) gilt damit nur für `editor` und `author`. Optional für PR #3: ein Hinweis über `AdminNoticeService` auf Rollen mit Konten, aber ohne `community.view`, solange das Modul aktiv ist.

**Entscheidung: SSO-Standardrolle.** Stand die Entra-Standardrolle (`default_role`) vor dem Update auf einer eigenen Rolle `moderator`, gilt ab 4.2.0 `member` (`EntraSettings::normalize()`, zweite Sicherung `IdentityLinker::defaultRole()`). Mit „Rollen synchronisieren“ (`sync_roles`) setzt die nächste Anmeldung über SSO angelegte Konten mit der Rolle `moderator` ohne Mapping-Treffer auf `member` (`IdentityLinker::syncTarget()`, Herabstufen ist dort erlaubt); sie verlieren damit auch die Rechte, die die eigene Rolle hatte. Wer `moderator` über SSO vergeben will, legt vor dem Update eine Mapping-Regel an. Dokumentiert in `DOC/admin/security/SSO-ENTRA.md` und im Changelog.

**Update-Schritt `capabilities_v27`** (`CommunityCapabilities::migrate(Database $db)`):

- läuft einmal: Solange die Option `capabilities_v27` in `settings` fehlt; der Marker wird erst nach erfolgreichem Eintragen gesetzt. Bei einem Fehler fehlt er: *Admin → Updates → Datenbank-Update* meldet `option:capabilities_v27` und wiederholt den Schritt über `MigrationManager::run(true)` (eine automatische Wiederholung beim nächsten Request gibt es nicht, die Laufzeit-Flags bleiben gesetzt);
- `INSERT IGNORE` auf den eindeutigen Schlüssel `uk_role_cap (role, capability)`: **überschreibt nie** eine Zeile, ein ausdrücklich entzogenes Recht (`granted = 0`) bleibt entzogen; ein zweiter Lauf ändert nichts;
- Neuinstallation: läuft über `SchemaManager::ensureFeatureSchema()` (der Installer setzt die Marke selbst, `MigrationManager::run()` kehrt dann sofort zurück); Update von v26: über `MigrationManager::run()`; Reparatur (`run(true)`): ein vorhandener Marker verhindert eine Wiederholung;
- nutzt nur die übergebene Verbindung, nie den Singleton-Zugriff auf `Database` (DB-410-02: Die Migration läuft im Konstruktor von `Database`); legt `role_permissions` selbst an, falls sie fehlt (`CommunityCapabilities::ensurePermissionsTable()`, gleiche Definition wie `cms_ensure_role_permissions_table()`; `MigrationManager` und `ensureFeatureSchema()` rufen sie auch vor dem v26-Schritt auf, wenn `includes/functions` fehlt; 4.0.0 legt sie erst beim ersten Lesen an, und `update.php` lädt `roles.php` nicht; `TESTS/schema-v27` vergleicht beide Texte).

**Rechte beliebiger Konten prüfen** (für `CommunityAccess`, PR #2): `Auth::hasCapability()` gilt nur für das angemeldete Konto. Für andere Konten gilt das Muster aus `ContentWorkflow`: `role === 'admin'` → erlaubt, sonst `!empty(cms_load_role_capabilities($role)[$capability])`, mit injizierbarer Closure für Tests. Gäste haben keine Capability: „Gäste dürfen lesen“ ist eine Einstellung. Konto-Status (gesperrt/inaktiv) prüft `hasCapability()` nicht, das macht `CommunityAccess` selbst.

### 5. Aufgaben je Pull Request

Die Zuordnung der Arbeitspakete (WP-C…) zu den Pull Requests ist ein Vorschlag auf Grundlage des Fahrplans; maßgeblich sind Abhängigkeiten und Sicherheits-Gates im [IMPLEMENTATION-PLAN.md](IMPLEMENTATION-PLAN.md) §4. Namespace `CMS\Services\Community\<Schicht>` unter `CMS/core/Services/Community/` (Schichten `Database`, `Repository`, `Service`, `Http`, `Admin`, `Support`; `Support\CommunityCapabilities` besteht bereits). Der Autoloader bildet `CMS\Services\Community\Service\TopicService` auf `core/Services/Community/Service/TopicService.php` ab. Neue Ordner unter `core/Services/` brauchen Einträge in `DOC/CMSFILESTRUCTUR.md` (`` `Community/` ``) und `DOC/core/SERVICES.md` (`` `Community\` ``): `TESTS/functions` prüft das, auch jede neue Klasse im Service-Wurzelordner.

#### 5.1 PR #2: Services und Moderation (WP-C1 Rest, C2 bis C9, Dienste)

**Stand:** Der Zuschnitt in zwei Pull Requests gilt: Was in §1a als geliefert steht (Modul und Gerüst, Repositories für Kategorien/Foren/Themen/Beiträge/Lesestatus/Mitglieder/Sperren lesend/Statistik, Rechte, `TopicService`, `PostService`, `QuoteService`, `ReadStateService`, `user_role_changed`, die Hooks für Themen und Beiträge, die Suiten `settings`/`access`/`content` unter den Namen `community-*`) ist erledigt; die folgende Tabelle ist der Plan für **#2b**. Erledigt in #2a sind die Zeilen „Modul und Gerüst“, „Rechte“, „Themen und Beiträge“ (ohne Auto-Abo) und „Core-Änderungen“, „Repositories“ teilweise; die Zeilen sind gekennzeichnet. Das Captcha ist ein eigener PR.

| Baustein | Inhalt |
|---|---|
| Modul und Gerüst | **Erledigt in #2a** (Sprachschlüssel bisher nur `community.access.*`/`community.error.*`, die übrigen kommen mit den Oberflächen). `CoreModuleService::MODULES`-Eintrag `community` (Kategorie „Inhalte & Community“, `default_enabled = false`, abschaltbar; ohne Modul: öffentliche Routen 404, Admin ausgeblendet, Daten bleiben); `FeatureHooks\CommunityHooks` (über `FeatureHooks\Registry`); `Support\Settings` (Gruppe `community`, Typ, Grenze, Standard je Schlüssel), `Support\Slugger`, `Support\Urls`, `Support\I18n`; Sprachschlüssel `community.*` in `CMS/lang/de.yaml` und `en.yaml` |
| Repositories (`Repository\`) | **Teilweise erledigt in #2a:** `CategoryRepository`, `ForumRepository` (auch `community_forum_permissions`, `community_forum_moderators`), `TopicRepository`, `PostRepository` (auch `community_post_revisions`), `ReadStateRepository` (auch `community_forum_read_marks`), `MemberRepository`, `UserStatsRepository` (`community_user_stats`), `BanRepository` (nur lesend). **Offen für #2b:** `ReactionRepository`, `SubscriptionRepository`, `ReportRepository`, `ModerationLogRepository`, `WarningRepository`, Schreibzugriffe in `BanRepository`, `AttachmentRepository`, `PollRepository`, `RankRepository`, `BadgeRepository`; nur gebundene Parameter, Sortier-/Filterspalten aus Allowlists. Für `drafts`, `poll_options`, `poll_votes`, `ignores`, `user_badges`, `stats_daily` und `redirects` nennt ARCHITECTURE keine Klasse: Teil des jeweiligen Haupt-Repositories oder eigene Klasse, mit dem jeweiligen Dienst festlegen |
| Rechte | **Erledigt in #2a** (§2a Nr. 5). `Service\CommunityAccess::can(user, action, context)`: einzige Entscheidungsstelle, Ergebnis `allowed`, `reason`, `retry_after`; Reihenfolge Modul aktiv → Konto → Community-Sperre → globale Capability → Forum-Moderator → Forum-Recht → Vertrauensstufe → Zustand → Filter `cms_community_can` (darf nur einschränken); 404 statt 403 bei fehlendem Leserecht; Rechte-Cache nur je Anfrage (kein Gesamtcache je Benutzer, solange `user_role_changed` nicht überall feuert) |
| Themen und Beiträge | **Erledigt in #2a bis auf das Auto-Abo** (Listener auf `cms_community_topic_created`/`post_created` in #2b). `TopicService`, `PostService` (eine Transaktion für Thema, ersten Beitrag, Zähler, Lesestatus, Auto-Abo; Idempotenz gegen doppeltes Absenden; Bearbeiten mit `community_post_revisions`), `QuoteService`, `ReadStateService` (`last_read_post_id` nur steigend), Keyset-Paginierung statt `OFFSET` |
| Darstellung der Quelle | `ContentRenderer` (CommonMark + GFM über `league/commonmark`, Roh-HTML aus, Link-Allowlist `http`/`https`/`mailto`/relativ, Code-Highlighting, Zitate; danach `PurifierService::purify($html, 'community')` mit neuem Profil `community`; `rendered_version`), Batch-Job zum Neu-Rendern; Vendoring über `VendorRegistry` mit SHA-256 in `DOC/assets/`, keine CDNs (E5) |
| Schutz | `CaptchaService` unter `core/Services/Security/` (eingebaut, Proof-of-Work, Bild-Captcha `gregwar/captcha`, keine Drittanbieter, E7; Core-Änderung auch für Formulare und Registrierung), Antispam-Schichten über `AntispamService::evaluate()`, `Security::checkDbRateLimit()`, Langsamer Modus, Wortfilter, Freigabe-Warteschlange; Filter `cms_community_spam_check` |
| Moderation | `ModerationService` (schließen, anpinnen, Ankündigung, verschieben mit Verweis, ausblenden/wiederherstellen, Beiträge verschieben, freigeben/ablehnen, Massenaktionen; Zusammenführen und Teilen sind P2), `ReportService` (Gründe `spam`/`abuse`/`offtopic`/`illegal`/`other`, höchstens 10/Stunde, Doppelmeldungen zusammenführen, Schwelle 3 offene Meldungen von Stufe ≥ 2), `BanService` (`write`/`full`, befristet ohne Cron, dauerhaft nur mit `community.ban`, keine Sperre gegen gleich oder höher Berechtigte), `WarningService` (Schnittstelle, Logik P2), `Http\ModerationController`; jede Aktion: POST + CSRF + `CommunityAccess` + Pflichtgrund + Eintrag in `community_moderation_log` (ohne IP) + Ereignis-Hook |
| Interaktion | `ReactionService`, `RankService` (Standard-Ränge säen), `NotificationDispatcher` (In-App in `notifications` mit Typen `community.*` und Bündelung über `group_key`/`event_count`, Mails über `MailQueueService`, eine Mail je Thema bis zum Besuch, Rechteprüfung je Empfänger), `AttachmentService` (Allowlist + `finfo`, Neukodierung, kein SVG, EXIF entfernt, privater Speicher `community/{yyyy}/{mm}/{random}`), `CommunitySearch` (TNTSearch + SQL-Rechtefilter), Sitemap-Provider und `MediaReferenceExtractor` `Community`; Schnittstellen für `PollService`, `BadgeService`, `ReputationService`, `CommunityStats`, `DraftService`, `MentionService`, `TrustLevelService` (Logik P2/P3, siehe Abschnitt 7 zum P1-Minimum) |
| Core-Änderungen | **Erledigt in #2a.** `user_role_changed` in `UserService::updateUser()`, `bulkAction('change_role')`, `RolesModule::updateRole()/deleteRole()` auslösen (bisher nur im SSO-Pfad, `EntraSsoService`; Signatur `(userId, from, to, source)`); der Hook für gelöschte Konten **existiert schon** als `cms_user_deleted($userId)` (`UserService::deleteUser()`, Verbraucher `FeatureHooks\InvoiceHooks`): kein zweiter `user_deleted` |

**Hooks (Namensschema `cms_community_*`; die Namen stehen seit #2a als Konstanten in `Support\Events`, außer den Filtern `cms_community_spam_check` und `cms_community_notification_types`; Signaturen mit dem jeweiligen Dienst festlegen und in `DOC/core/HOOKS-REFERENCE.md` dokumentieren):** Aktionen `cms_community_topic_created|updated|deleted|moved|merged`, `cms_community_post_created|updated|deleted|approved|rejected`, `cms_community_report_created|resolved`, `cms_community_user_warned|banned|unbanned`, `cms_community_solution_marked`; Filter `cms_community_render_post`, `cms_community_can`, `cms_community_spam_check`, `cms_community_notification_types`. Es fehlen noch Hooks für Teilen, Ausblenden/Wiederherstellen, Beitrag verschieben, Lösung aufheben, Reaktion und Abo.

**Genutzte Core-Hooks (vorhanden):** `register_routes` (vor den Beitragsmustern registrieren), `cms_register_content_type_providers`, `member_dashboard_init`, `dsgvo_export_data`, `dsgvo_delete_data`, `user_registered`, `user_email_verified`, `user_sessions_revoked`, `head`/`body_end`, Cron über `CronRunnerService` (`cms_cron_mail_queue`, `cms_cron_hourly`, `cms_cron_daily`).

**Tests (`TESTS/<suite>/run.php`):** `settings` (jede Einstellung hat Leser und Wirkungstest), `access` (Rechte-Matrix über jede Route × Rolle/Sperre), `content`, `render`, `xss` (Korpus ≥ 30), `spam`, `moderation`, `notify`, `uploads`, `search-seo`. Jeder Test läuft einmal gegen eine absichtlich falsche Umsetzung und muss dort fehlschlagen (Gegenprobe).

#### 5.2 PR #3: Admin (WP-C2, C6, C7 Oberfläche)

**Stand (PR #3, Teil 1 und 2):** Geliefert sind alle zehn Seiten außer „Statistik“ (P2): `/admin/community`, `-queue`, `-reports`, `-topics`, `-sanctions`, `-log`, `-structure`, `-permissions`, `-ranks`, `-settings` (Einstiegsdateien `CMS/admin/community*.php`, Logik `CMS/admin/modules/community/CommunityAdminModule.php`, gemeinsame Einstiegslogik `entry.php`, Ansicht `CMS/admin/views/community/index.php`), die Katalogeinträge `community.*` mit den Gruppen „Community“ und „Community-Verwaltung“ in beiden Profilen, die Badges `community_queue`/`community_reports` und der Hinweis „Warteschlange älter als 24 Stunden“ (beides in `CommunityHooks`), die Rollen-Hooks `cms_role_renamed`/`cms_role_deleted` mit Aufräumen der Rechte-Zeilen (`ForumRepository::renameRoleSubject()`/`deleteRoleSubject()`, Listener vor dem Modul-Gate), die `admin_pages` des Moduls und die Suite `community-admin`. Ort der Module: `CMS/admin/modules/community/`. Moderatoren ohne Rolle `admin` erreichen `/admin` weiterhin nicht (Abschnitt 7, offene Entscheidung 2). **Bewusst offen:** Ziehen mit der Maus in der Struktur (bisher Pfeil-Schaltflächen), Löschen von Kategorien und Foren (bisher ausblenden bzw. archivieren), Gruppen als Subjekt der Rechte-Matrix (Moderatoren dürfen Gruppen sein), Statistik (P2). Die Texte der Oberfläche stehen als deutsche Literale in der Ansicht (noch keine Sprachschlüssel `community.admin.*`).

Seiten unter `/admin/community-*` (Module in `Community\Admin\`, Ansichten in `CMS/admin/views/community/`, Katalogeinträge in `AdminMenu\CoreCatalog` in beiden Menüprofilen, Gruppe „Community“, bis zur Fertigstellung ausgeblendet). **Pflichtschritte für jeden neuen Menüeintrag** (sonst schlägt `TESTS/admin-menu` fehl, geprüft mit einem Probeeintrag): ID-Präfix in `CoreCatalog::AREA_LABELS`, Platzierung in beiden Layouts von `ProfileLayouts::classic()` und `::structured()`, Eintrag in `RESERVED_FILES` von `TESTS/admin-menu/run.php` (solange die Seite fehlt, bleibt der Eintrag ausgeblendet) und eine Einstiegsdatei `CMS/admin/community-*.php` je Route (vorhandene Seiten: `CMS/admin/<slug>.php`, Module unter `CMS/admin/modules/<bereich>/`); den Ort der Module (`CMS/admin/modules/community/` oder `core/Services/Community/Admin/`) vorab festlegen. Weitere Stellen, die `TESTS/admin-menu/run.php` prüft: die erwarteten Hauptpunkt-Listen (Teil 4, vier Stellen), `KNOWN_BADGES` (für die Badges `community_queue` und `community_reports`), `category` und `desc` bei Kachelgruppen und gegebenenfalls `OPTIONAL_MODULES`. Der Prüflauf mit einem Probeeintrag zeigt die Liste:

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

**Rollen-Hooks und Aufräumen der Rechte-Zeilen (aus §2a Nr. 8, spätestens in PR #3):** Core-Hooks `cms_role_renamed` und `cms_role_deleted` in `RolesModule::updateRole()`/`deleteRole()`, nach dem Commit und nur bei tatsächlicher Änderung (Vorschlag für die Argumente: alter und neuer Slug bzw. der Slug); bisher meldet der Core nur `user_role_changed` je Konto (Quellen `role_renamed`, `role_deleted`). Ein Listener schreibt beim Umbenennen `community_forum_permissions.subject_key` der Zeilen mit `subject_type = 'role'` auf den neuen Slug um und löscht sie beim Löschen der Rolle (Index `idx_community_forum_permissions_lookup`), danach `CommunityAccess::flush()`. Vorschlag: den Listener in den Teil „immer“ von `CommunityHooks` legen (§2a Nr. 2), sonst bleiben die Zeilen bei abgeschaltetem Modul veraltet. Beide Hooks in `DOC/core/HOOKS-REFERENCE.md` dokumentieren; Test: Umbenennen, Löschen, und eine neue Rolle mit dem alten Slug erbt keine Zeilen.

#### 5.3 PR #4: Mitgliederbereich und Frontend (WP-C10, C3, C7)

**Stand (PR #4, Teil 1):** Geliefert sind die Leseseiten `/forum`, `/forum/{forum-slug}`, `/forum/thema/{id}-{slug}[/seite-N]` und `/forum/beitrag/{id}` samt `/en/…` (`Http\PublicController`, Muster aus `Urls::routePatterns()`, Anmeldung in `CommunityHooks` hinter dem Modul-Gate), die Vorlagen `CMS/views/community/{index,forum,topic,not-found}.php`, `CMS/assets/css/community.css`, `PostService::cursorForPage()`/`PostRepository::idAtPosition()` und die Suite `community-frontend` (Mutanten in `catalog-4-frontend.php`). Entscheidungen: Seitenadresse `seite-N` löst über den N-ten Beitrag auf (höchstens 5000 Positionen, danach 404); die Forenliste blättert mit `?nach=<Cursor>` (Keyset) statt `seite-N` (die Adresse `/forum/{slug}/seite-N` wird angenommen, zeigt aber die erste Seite und ist ein Alias, bis ein Seitenzähler für Foren entschieden ist). Die Texte der Vorlagen sind deutsche Literale. **Teil 2 (Schreiben):** `Http\ComposeController` für `/forum/{slug}/neues-thema`, `/forum/thema/{id}/antworten` und `/forum/beitrag/{id}/bearbeiten` (GET Formular, POST über die Dienste, 303; Vorlage `compose.php`; Router-Muster `reply`, `new_topic`, `edit` stehen in `Urls::routePatterns()` vor `topic_page`/`forum_page`, die Schreibseiten werden vor den Leseseiten angemeldet; Hinweis nach dem Speichern über `$_SESSION['community_flash']`). Hochladen von Anhängen, Vorschau, Entwürfe und Umfragen fehlen noch im Formular. **Teil 3 (JSON-API):** `Http\ApiController` (`/forum/api/reaktion|abo|melden|vorschau|moderation|loesung`, `Urls::apiPattern()`/`apiBase()`/`isApiPath()`), dauerhaftes Token über `ApiController::tokenFilter()` am Filter `cms_public_form_persistent_token` (Aktion `community_api`, bis `CSRF_TOKEN_TTL`; bei 403 zeigt das Skript „Seite neu laden“), `assets/js/community/community.js`, Suite `community-api` mit Mutanten. Die API-Moderation (`ApiController::MODERATION_OPS`) ist eine Teilmenge der Sammelaktionen; Umbenennen, Ankündigen, Zusammenführen und Löschen bleiben der Verwaltung. `/forum/api/erwaehnung`, `/umfrage`, `/entwurf`, `/aehnliche-themen`, `/neu` sind P2. **Teil 4 (Mitgliederbereich):** `CommunityMemberSection` (`/member/plugin/community?tab=themen|beitraege|abos|einstellungen|moderation`, Anmeldung in `CommunityHooks` über `member_dashboard_init`), `TopicRepository::listByUser()`/`PostRepository::listRepliesByUser()`, Suite `community-member` mit Mutanten. Die Moderation im Mitgliederbereich zeigt die ersten zehn Freigaben und Meldungen (kein Blättern) und ist die Oberfläche der Rolle `moderator` (§7, offene Entscheidung 2). Ohne Signatur (kein Schlüssel in `PREFERENCE_KEYS`), Lesezeichen, Ignorierte und Verwarnungen (P2). **PR #5 (Datenschutz, Teil 1):** `Community\CommunityRetention` (neun Regeln `community.*`, Fristen und Grenzen siehe Klassenkommentar, Filter `cms_community_retention_days`) und `Community\CommunityPrivacy` (Auskunft, Löschung, Selbstauskunft, Aufräumen der Exportdateien), angemeldet in `CommunityHooks` **vor** dem Modul-Gate (`dsgvo_export_data`, `dsgvo_delete_data`, `cms_user_deleted`, `cms_member_export_data`, `cms_init_admin`/`cms_cron_daily` für die Regeln); Suite `community-privacy`. Entscheidungen zu den offenen Punkten aus §7: **Verwarnungen ohne Ablauf** höchstens 1825 Tage nach Anlage; **Sperren** 365 Tage nach `COALESCE(lifted_at, ends_at)`, dauerhafte nicht aufgehobene bleiben; **Lesemarken der Foren, Abos, Reaktionen, Stimmen, Ignorierliste, Abzeichen** ohne eigene Frist (mit dem Konto gelöscht); `community_stats_daily` ohne personenbezogene Zeilen; **Löschmodus** nur Anonymisieren (die Einstellung „Beiträge löschen“ folgt mit einem Schema-Feld `deleted_at` für Beiträge im Status `deleted`/`rejected`, bis dahin bleiben deren Texte unbegrenzt). **Papierkorb (PR #5, Teil 2):** `CommunityContentProvider` (`TrashProvider`, Typ `community_topic`, Capability `community.manage` zusätzlich zu `manage_trash`, angemeldet in `CommunityHooks` **vor** dem Modul-Gate) über `Service\TopicTrashService` und `Repository\TrashRepository`; Suite `community-trash`, Mutanten `catalog-5-trash.php`. Entscheidungen: Der Papierkorb zeigt alle Themen mit `status = deleted` (der Status `trash` bleibt unbenutzt, keine zweite Stufe ohne Oberfläche; Abweichung von WORKFLOWS); `TopicService::delete()` und die Ablehnung setzen `trashed_at`/`trashed_by`, Themen ohne Zeitstempel bekommen ihn beim ersten Fristlauf; **`TopicService::delete()` bucht jetzt über `CounterBook::topicUnpublished` (S1)**: die bekannte Drift der Antwortautoren (Reparatur-Job) entfällt, das Wiederherstellen bucht `topicPublished` als genaue Umkehrung. Abgelehnte Themen (`deleted` aus `pending`, erster Beitrag `rejected`) sind nicht wiederherstellbar, nur löschbar. Endgültig löschen: je 100 Beiträge eine Transaktion (Abbruch ist fortsetzbar), Reaktionszähler der Mitglieder sinken, Dateien und Suchindex (`CommunitySearch::removePosts`) nach dem Commit, Protokoll `topic.restore`/`topic.delete`, Hook `cms_community_topic_deleted` mit Modus `purged`. Warnung (Bestätigung nötig, Fristlauf überspringt) nur bei offenen Meldungen am Thema oder seinen Beiträgen. Der Core-Papierkorb liest mit Versatz: der Dienst schneidet die Seite in PHP aus höchstens 5000 geordneten IDs (kein SQL-Versatz). Kein Suchindex-Provider für den Core (interne Foren bleiben aus der Suche der Website). **Captcha (PR #188, E7):** Core-Dienst `Security\CaptchaService` (`Security\Captcha\{CaptchaProvider, CaptchaResult, CaptchaStore, CaptchaText, MathChallenge, MathProvider, ProofOfWorkProvider, ImageProvider}`), Filter `cms_captcha_providers`; Verfahren `pow` (Standard, ALTCHA-Prinzip ohne Geheimnis: Aufgabe nur in der Sitzung, SHA-256 im Browser über `assets/js/captcha/captcha.js`), `image` (GD, selbst gezeichnet: `gregwar/captcha` ließ sich beim Bau nicht beziehen, die Schnittstelle erlaubt den Tausch) und `math`; jedes Verfahren bringt die Rechenaufgabe als Alternative mit. Aufgaben: einmalig (auch nach falscher Antwort verbraucht), 2 Stunden gültig, nur im eigenen Kontext, höchstens 8 offene je Sitzung; das Verfahren bestimmt der Server (in der Aufgabe), nie die Anfrage. Community: `Service\CaptchaGate` (Kontext `community.compose`) vor neuem Thema und Antwort in `ComposeController` (nicht beim Bearbeiten, nicht für Gäste, nicht für globale Moderation), Einstellungen `captcha_mode` (`off`, `first_post` = Standard, `trust0`, `always`) und `captcha_provider` (neuer Einstellungstyp `enum` mit `values`), Filter `cms_community_captcha_required` (nur verschärfend), Fehlschlag: Formular mit neuer Aufgabe (422) und `captcha_failed` im Sicherheitsprotokoll (`ContentGuard::LOGGED_CODES`). Suiten `captcha` und `community-captcha`, Mutanten `catalog-5-captcha.php` (der Treiber kennt die Zusatzsuite `captcha` über `CM_EXTRA_SUITES` und die Core-Dateien über `CM_ALLOWED_CORE_FILES`). **Nicht Teil dieses PR:** die Rechenaufgabe der Formulare (`FormSpamGuard`) und die Registrierung nutzen den Dienst noch nicht (eigener Schritt: Formularfeld `cms_captcha` ablösen, Tests der Formulare), und **Fund:** `ContentGuard::screen()/filterText()/content()` (Wortfilter, Core-Antispam, Spam-Dublette, Linkzählung, `ip_hash`) haben im Betrieb keinen Aufrufer — `ComposeController` und die Dienste schreiben ohne sie; das ist als eigener Auftrag vorgeschlagen. Offen für PR #5: Legal-Textbausteine, Admin-Liste der Exportdateien, Einstellung für den Löschmodus, `performance`-/`a11y`-/`lighthouse`-Suiten, Release-Vorbereitung (Version, Manifest, Pakete). **Visueller Editor (Nachtrag):** `assets/js/community/editor.js` (SunEditor lokal, nur auf Formularseiten, `PublicController::render()` setzt `editor` und die Modul-Adresse) und `md-bridge.js`; E4 bleibt: gespeichert wird Markdown, der Server rendert und bereinigt (der Editor ist nur Eingabehilfe im Browser). Statt der geplanten eigenen Werkzeugleiste (≤ 60 KB) lädt das Forum den vorhandenen SunEditor (rund 870 KB, nur Schreibseiten, nicht blockierend). Bearbeiten startet in Markdown; Quellen mit Tabellen/Bildern/Zitatmarkern/HTML öffnen nie im visuellen Editor (Verlustschutz). Offen: Anhänge/Bilder im Editor (Upload-Endpunkt fehlt), Erwähnungen, lokale Entwürfe. Die Browser-Prüfung läuft nur mit `node` und Playwright (in der CI nicht aktiv). **Teil 5 (Listen, Suche, Anhänge):** `PublicController::listing()/search()/unread()`, `Http\AttachmentController`, `TopicService::listGlobal()`/`TopicRepository::listGlobal()`, `Urls::search()`/`listing()`, Vorlagen `listing.php`, `search.php` und `partials/nav.php`; die Suite `community-frontend` deckt Routen, Listen (Keyset, Foren-/Statusfilter) und die Kopfzeilen der Auslieferung ab. Die Listen werden nicht indiziert. **Offen:** `/forum/mitglieder/{id}` (Profilabschnitt), Feeds (P2), JS-Module `Werkzeugleiste` und lokale Entwürfe, `CommunityContentProvider` (PR #5), `TrashProvider`, Browser-/a11y-/Lighthouse-Prüfung (PR #5).

| Bereich | Inhalt |
|---|---|
| Öffentliche Routen | `/forum` (Start), `/forum/{forum-slug}[/seite-{n}]`, `/forum/{forum-slug}/neues-thema`, `/forum/thema/{id}-{slug}[/seite-{n}]` (falscher Slug → 301 über die ID; EN: `/en/forum/topic/{id}-{slug}`), `/forum/thema/{id}/ungelesen`, `/forum/beitrag/{id}`, `/forum/thema/{id}/antworten`, `/forum/beitrag/{id}/bearbeiten`, `/forum/neu`, `/forum/aktiv`, `/forum/unbeantwortet`, `/forum/suche`, `/forum/mitglieder/{id}`, `/forum/anhang/{id}[/vorschau]`; alle auch unter `/en/…` (englische Segmente laut `Slugger::RESERVED`: `new-topic`, `reply`, `edit`, `unread`, `post`, `attachment`, `new`, `active`, `unanswered`, `search`, `members`, `topic`; `Support\Urls` bildet bisher Startseite, Forum und Thema (beide mit Seitenzahl) sowie Beitrag, mit den Segmenten `thema`/`topic`, `beitrag`/`post` und `seite`/`page` (`Urls::SEGMENTS`); die übrigen Adressen kommen mit den Routen). P2: `GET /forum/feed` und `/forum/{forum-slug}/feed` (RSS, COM-PLUS-12, ARCHITECTURE §3) nur für öffentlich lesbare Foren: der Gast muss lesen dürfen, interne Foren erscheinen nie. **Reservierte Segmente: erledigt in #2a** (`Support\Slugger::RESERVED`, deutsch und englisch, dazu `seite-<n>`, `page-<n>` und `en`; auch beim Schreiben von Forum-Slugs geprüft) |
| JSON-API | `POST /forum/api/reaktion`, `/abo`, `/melden`, `/vorschau` (nur angemeldet), `/moderation`, `/loesung` (Vorschlag, P1, COM-PLUS-04, WORKFLOWS W11: Lösung setzen und aufheben, Ziel `post_id` bzw. `topic_id`; Recht über `can('mark_solution')`, also Ersteller eines Q&A-Themas mit Matrixrecht `mark_solution`, Forum-Moderatoren mit Matrixrecht `mark_solution` (auch in fremden Themen und außerhalb von Q&A) oder globale Moderation ohne Matrixrecht (§2a Nr. 5), deshalb nicht unter `/moderation`; setzt bzw. leert `solution_post_id`, `solved_at` und `solved_by` gemeinsam, §2 Nr. 10; Hook `cms_community_solution_marked`, für das Aufheben fehlt noch einer); P2: `/umfrage`, `/entwurf`, `GET /forum/api/erwaehnung`; `GET /forum/api/aehnliche-themen`, `/forum/api/neu` (Polling 60 s, ETag/304). CSRF und Rate-Limit je Aktion: **Der globale `form_guard` des Routers** (`Router.php`) prüft jeden POST/PUT/DELETE/PATCH außerhalb von `/api`, `/admin` und `/member` vor der Routenzuordnung und liest nur `$_POST['csrf_token']` (Aktion `form_guard`), kein Header- oder JSON-Token; `/forum/api/*` und die öffentlichen Formulare unter `/forum/…` laufen also durch ihn (bei fehlendem Feld 404/405, bevor die Route läuft). **`form_guard`-Tokens sind einmalig:** `Security::verifyToken()` entwertet ein Token nach erfolgreicher Prüfung; je Aktion und Sitzung bleiben höchstens 20 Tokens gültig (`Security::CSRF_TOKEN_HISTORY_LIMIT`), jedes höchstens 3600 Sekunden (`CSRF_TOKEN_TTL`). Ein Feld `csrf_token` trägt deshalb nur ein Formular mit einem Absenden (Vollseiten-Formulare unter `/forum/…`); ab dem zweiten Aufruf mit demselben Token antwortet der Router mit 403. Für die JSON-API (mehrere Aufrufe von einer Seite: Reaktion, Abo, Vorschau) entweder ein persistentes Token über den Filter `cms_public_form_persistent_token` (`Router::PUBLIC_FORM_TOKEN_FILTER`, `DOC/core/HOOKS-REFERENCE.md`: der Filter nennt Aktion und übermitteltes Token, der Router prüft mit `Security::verifyPersistentToken()` und entwertet nicht; die Gültigkeitsdauer gilt auch hier) oder je Antwort ein neues `form_guard`-Token mitliefern, das das Skript beim nächsten Aufruf sendet. Die Bypass-Listen des Routers nur mit eigener Prüfung. Pflichttest: zwei aufeinanderfolgende POSTs von derselben Seite gelingen. Ohne Definition im Plan: Upload-Endpunkt, „Alle als gelesen markieren“, signierter Abmeldelink, „Bestätigungsmail erneut senden“, Captcha-Challenge, Formular-Fallbacks ohne JavaScript für Melden und Moderation |
| Klassen (festzulegen) | Vorschlag aus ARCHITECTURE §2, Namen und Zuordnung Route → Klasse in PR #4 festlegen: `Http\PublicController` (Startseite, Forum, Thema, Profil-Abschnitt, Suche), `Http\ComposeController` (neues Thema, antworten, bearbeiten, Vorschau), `Http\ModerationController` (Moderationsmenü, §5.1), `Http\ApiController` (`/forum/api/*`), `Http\AttachmentController` (`/forum/anhang/{id}`); dazu `CommunityContentProvider` im Wurzelordner `Community/` (Papierkorb, Suchindex, Export über `ContentTypeRegistry` und `cms_register_content_type_providers`), spätestens mit dem `TrashProvider` in PR #5. Neue Klassen brauchen Einträge in `CMSFILESTRUCTUR.md` und `SERVICES.md` (§5, Einleitung) |
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
| DSGVO | `dsgvo_export_data` (Themen, Beiträge, Reaktionen, Abos, Stimmen, eigene Meldungen, Verwarnungen, Sperren, Profilfelder) und `dsgvo_delete_data` (Anonymisieren = `user_id` NULL, Anzeige „Gelöschtes Mitglied“, Profil 404, Zitate neutralisieren; oder Löschen, Einstellung mit Hinweis im Datenschutztext); Löschantrag und Kontolöschung wirken gleich (`cms_user_deleted`; Einschränkungen: `cms_user_deleted($userId)` feuert nur beim endgültigen Löschen in `UserService::deleteUser()`, nicht beim Deaktivieren (Soft-Delete); `dsgvo_export_data`/`dsgvo_delete_data` erhalten `($userId, $email)`, im Löschantrag vor dem Löschen, und bei reinen E-Mail-Anträgen ohne Konto ist `$userId` leer, `cms_user_deleted` feuert dann nie: Handler müssen beides behandeln); Papierkorb-Provider (`TrashProvider` über `ContentTypeRegistry`); Legal-Textbausteine |
| Löschfristen | `CommunityRetentionRules` über `DataRetentionService::registerRule()`: `community.ip_hash` 30 Tage (`community_posts.ip_hash`, Index `idx_community_posts_created`) · `community.read_states` 180 Tage (`idx_community_read_states_cleanup`) · `community.orphan_uploads` 24 Stunden (`idx_community_attachments_post`) · `community.drafts` 30 Tage (`idx_community_drafts_updated`) · `community.reports` 365 Tage, erledigte (`idx_community_reports_handled`) · `community.moderation_log` 365 Tage (`idx_community_moderation_log_time`) · `community.warnings` 2 Jahre (`idx_community_warnings_expires`) · `community.revisions` 365 Tage, letzte Fassung bleibt (`idx_community_post_revisions_created`) |
| Tests | `privacy` (Export, Anonymisieren/Löschen, jede Löschfrist-Regel), `performance` (≤ 6 Abfragen je Seite bei warmem Cache, Lasttest 10.000 Themen/500.000 Beiträge, kein `OFFSET`-Scan per `EXPLAIN`), `browser` (Playwright, 360/320 px, Dark Mode, Tastatur), `a11y` (axe-core ohne *serious/critical*), `lighthouse` (mobil ≥ 90); `settings`/`access`/`xss` vervollständigen; Gegenproben dokumentieren; `php TESTS/run-all.php` und mit `CMS_TEST_DB_HOST` auch `schema-upgrade` (in CI nicht aktiv: dafür einen MariaDB-Service-Container in den Workflow aufnehmen und den Lauf zusätzlich mit `CMS_TEST_SQL_MODE` = MySQL-8-Standardmodus starten) |
| Dokumentation | `DOC/admin/community/` (neu), `DOC/member/`, `DOC/core/HOOKS-REFERENCE.md`, `DOC/core/DATABASE-SCHEMA.md`, `DOC/core/SERVICES.md`, `DOC/CMSFILESTRUCTUR.md`, `DOC/FILELIST.md`, `DOC/FILESTRUCTUR.md`, `DOC/INDEX.md`, `DOC/admin/users-groups/RBAC.md` (Rolle `moderator`, Gruppe „Community“), `Changelog.md` (Abschnitt `v4.2.0`), ROADMAP-Fortschritt |
| Release | `Version::CURRENT`, `CMS/update.json` und `CMS/marketplace/core/365cms/update.json` (Version, `schema_version` = `v27`, Prüfsumme), Pakete `RELEASE/365CMS-4.2.0-*` mit `README-4.2.0.md` und SHA-256; `security.yml` (Semgrep/Trivy) ohne neue Befunde; `TESTS/release-3.4.0` prüft Paket, Manifest und Marke gemeinsam |

### 6. Prüfen und Arbeiten (alle Pull Requests)

- Vor jedem Push: `php -l` über alle geänderten PHP-Dateien, `php TESTS/run-all.php` (alle Suiten `TESTS/*/run.php`, die Zahl steht in der letzten Zeile der Ausgabe; `TESTS/release-4.1.0` verlangt dieselbe Zahl im Changelog-Abschnitt `v4.2.0`: „`php TESTS/run-all.php` führt N Suiten aus“). Für Änderungen an Schema oder Rechten zusätzlich `CMS_TEST_DB_HOST=localhost CMS_TEST_DB_USER=root php TESTS/schema-upgrade/run.php` gegen eine MariaDB/MySQL (auch mit `CMS_TEST_SQL_MODE` = MySQL-8-Standard). In der CI läuft nur `run-all.php` unter PHP 8.4 und 8.5, **ohne** Datenbank.
- `TESTS/functions/doc-references.php` verlangt: jede `TESTS/*/run.php`-Suite in `DOC/FILELIST.md` und `DOC/FILESTRUCTUR.md`; neue Service-Ordner und -Klassen in `CMSFILESTRUCTUR.md` und `SERVICES.md`; die Tabellenzahl „The base schema (N tables)“ in `DATABASE-SCHEMA.md`.
- Code im Konstruktor-Pfad von `Database` (`SchemaManager`, `MigrationManager`, Update-Schritte) nutzt nur die übergebene Verbindung (`get_option()`, `OptionStore`, `SettingsService` rufen `Database::instance()` auf und erzeugen die Rekursion aus DB-410-02).
- `Changelog.md` beginnt mit einem BOM; Einträge gehören unter `### v4.2.0` über `v4.1.9`.
- Sicherheits-Checkliste je Paket: [SECURITY-PRIVACY.md](SECURITY-PRIVACY.md) §6; Einstellungen: jeder Schlüssel hat einen Leser im Code und einen Wirkungstest.

### 7. Abweichungen von den Planungsdokumenten und offene Entscheidungen

**Bereits entschieden (PR #1), Planungsdokumente weichen ab:**

1. `RolesModule::CAPABILITY_GROUPS` (PERMISSIONS-MODERATION §2, IMPLEMENTATION-PLAN §6) heißt im Code `CAPABILITIES`.
2. IMPLEMENTATION-PLAN §6 beschreibt `capabilities_v27` mit „Marker in `settings`“: umgesetzt. (Der Schritt `capabilities_v26` hat keinen Marker, siehe DOC-410-03.)
3. ARCHITECTURE §2, ROADMAP und DATA-MODEL (§1, englische Zusammenfassung) führen `Database/Installer.php` als Ort der Tabellen; der Plan §5 und PR #1 legen die DDL nur in `SchemaManager` ab (Abschnitt 2, Punkt 1).
4. DATA-MODEL §3 zeigt `legacy_id`, `legacy_source` und `community_redirects` für Plugin-Adressen; wegen E6 entfallen die Spalten, die Tabelle bleibt für `topic`/`forum`. Die Textspalten sind `NULL`-fähig, die übrigen Spalten haben Defaults (Abschnitt 2, Punkt 4).
5. `user_deleted` gibt es schon als `cms_user_deleted`; neu einzuführen ist nur `user_role_changed` außerhalb des SSO-Pfads.
6. Stand PR #1: IMPLEMENTATION-PLAN §1 nennt `Version::CURRENT = '4.1.0'`, tatsächlich 4.1.8; §9 nannte „aktuell 31 Suiten“, mit PR #1 waren es 36 (35 + `schema-v27`). §9 nennt jetzt keine feste Zahl mehr (§6).
7. IMPLEMENTATION-PLAN §5: „Tabellen mit Präfix `cms_`“ meint `{prefix}community_…`.

**Offene Entscheidungen vor oder in PR #2 (Stand nach PR #2a):**

1. **P1-Minimum für Vertrauensstufen und Erwähnungen.** Vertrauensstufen: entschieden in #2a (§2a Nr. 6, `BasicTrustPolicy`); offen sind nur die Erwähnungen. Beides ist P2 (WP-C14/C15), wird aber in P1 vorausgesetzt (Prüfreihenfolge in `CommunityAccess`, Grenzen je Stufe in WP-C5, Erwähnungs-Limit im Gate von WP-C7). Festlegen, was P1 liefert (Vorschlag: Stufe 0/1/2 aus E-Mail-Bestätigung und Beitragszahl ohne Cron-Neuberechnung; Erwähnungen nur als Link ohne Benachrichtigung).
2. **Oberfläche der Rolle `moderator`.** Der Plan schließt `/admin` für sie aus; die Warteschlange und die Meldungen müssen deshalb im Frontend/Mitgliederbereich (PR #4) bedienbar sein, nicht nur als Hinweis (WORKFLOWS W8). Alternative: Moderationsseiten in `DelegatedAdminAccess::PAGES` aufnehmen.
3. **Einstellungsschlüssel.** Entschieden in #2a (§2a Nr. 1: Gruppe `community`, Option `community.<schlüssel>`, flache snake_case-Schlüssel). Ursprüngliche Frage: Ob Option `community.title` (ein Schlüssel) oder Gruppe `community` + Schlüssel `title`: an der Konvention von `SettingsService` ausrichten und in `Support\Settings` festhalten. Die Zuordnung der Plugin-Schlüssel zu den Core-Schlüsseln steht in MIGRATION-CMS-FORUM §4 (nur als Liste der benötigten Einstellungen; keine Übernahme).
4. **Captcha.** Die Pläne sprechen teils von „Anbietern“ mit Schlüsseln; E7 erlaubt nur lokale Verfahren. Der `CaptchaService` ist eine Core-Änderung auch für Formulare und Registrierung: Umfang und eigener Pull Request festlegen.
5. **E6-Reste in den Planungsdokumenten** (ARCHITECTURE §4 `community-import`, `default_enabled` bei aktivem `cms-forum`, BBCode-Altbeiträge; ROADMAP WP-C4 BBCode-Renderer; PERMISSIONS-MODERATION §2 „Datenübernahme“): nicht umsetzen.
6. **Lücken in den Plänen:** Signaturen der in `Support\Events` benannten, noch nicht ausgelösten Hooks und die fehlenden Hooks (5.1), Endpunkte ohne Definition (5.3), Adressen der übrigen Routen in `Support\Urls` (die englischen Segmente selbst stehen seit #2a in `Slugger::RESERVED`, `Urls::SEGMENTS` kennt bisher `thema`/`topic`, `beitrag`/`post`, `seite`/`page`), Namen und Zeitpläne der Cron-Aufgaben außer `community-digest`, Namen der Admin-Klassen und der Http-Controller (5.3).

**Weitere Festlegungen und Lücken, die PR #2 bis #5 beachten müssen:**

- **Bekannter Fehler im Rollen-Editor (vorbestehend, nicht Teil von PR #1):** `RolesModule::getDefaultPermissions()` kennt für `editor` die Rechte `blocks.reusable`, `manage_translations`, `manage_trash`, `upload_downloads`, `view_editorial_calendar` und `view_form_submissions` sowie für `author` `posts.publish` nicht, obwohl `cms_get_default_role_definitions()` sie vergibt: Das Speichern der unveränderten Matrix schreibt dafür `granted = 0`. Die Standardmatrix sollte aus den Code-Standards abgeleitet werden (mit Abgleichstest). Gilt auch für 4.1.8.
- **Themenliste und Verweis-Themen (`moved`):** `idx_community_topics_forum_list` (`forum_id`, `status`, `is_pinned`, `last_post_at`) liefert die Liste ohne Filesort bei genau einem Status je Abfrage; `status IN ('published','moved')` erzeugt ein Filesort (EXPLAIN geprüft). Verweis-Themen getrennt laden (zweite Abfrage oder `UNION`) oder im Lasttest per `EXPLAIN` prüfen.
- **Tiefe Seiten (PR #4):** Die Forenliste (`listForum()`, auch der zweite Strom von „ungelöste zuerst“) und die globale Liste „Unbeantwortet“ ohne Rechtefilter laufen in der zweistufigen ID-Form und lesen an jeder Position etwa gleich viel (gemessen höchstens 22 Lesezugriffe für die IDs, Abschnitt 2, Punkt 10). „Unbeantwortet“ mit `forum_id IN (…)` nicht: Mit `range` auf `idx_community_topics_unanswered_all` hängen die Lesezugriffe von der Dichte der lesbaren Foren ab (rund 21 × alle Treffer ÷ Treffer der lesbaren Foren), am Listenende und bei wenigen Treffern liest `idx_community_topics_unanswered` mit Filesort über den Rest der Liste, der mit ihr wächst (gemessen bis 885 Lesezugriffe bei 8413 Treffern; die Zahlen in Abschnitt 2, Punkt 10 sind Messwerte, keine Grenze). Das Gate im Lasttest lässt dort beide Indizes zu und setzt die Obergrenze relativ zu Dichte und Listengröße oder begrenzt die Seitentiefe. Für „Aktiv“ (`idx_community_topics_activity`) wählt MariaDB 10.11 bei einem tiefen Keyset-Cursor `ref` statt `range` und liest alle Einträge vor dem Cursor, ohne Filesort (40.060 Themen: Position 20.000 mit 20.022 Lesezugriffen; erste Seiten 21 bis 30). Im Lasttest prüfen, ob die ID-Form dort ebenso hilft; sonst die Seitentiefe begrenzen.
- **Papierkorb:** Themen tragen im Papierkorb `status = 'deleted'` mit `trashed_at`, `trashed_by`, `previous_status` (Vertrag des `TrashProvider`; der Status `trash` ist reserviert und unbenutzt, siehe PR #5 Teil 2); Beiträge kennen nur `deleted` (weich) und `rejected`. PERMISSIONS-MODERATION §5 und WORKFLOWS nennen `trash` auch für Beiträge: bewusst nicht umgesetzt (kein Papierkorb für Einzelbeiträge). „Vom Autor“ oder „vom Moderator gelöscht“ steht in `status_reason` bzw. im Moderationsprotokoll (`community_posts` hat kein `deleted_by`).
- **Löschfristen (PR #5):** `RetentionRule` kennt nur ganze Tage (`days()` liefert `int`, 0 = nie): `community.orphan_uploads` (24 Stunden) wird als 1 Tag geführt, und der Bereinigungs-Callback muss auch die Datei im privaten Speicher entfernen, nicht nur die Zeile. Für `community_bans` (Klartext-Grund, interne Notiz; z. B. n Tage nach `COALESCE(lifted_at, ends_at)`), unbefristete Verwarnungen (`expires_at` NULL fällt nie unter „2 Jahre nach Ablauf“; Höchstdauer festlegen), Beiträge im Status `deleted`/`rejected`, `community_user_badges`, `community_ignores`, `community_poll_votes`, `community_forum_read_marks` und `community_stats_daily` nennt SECURITY-PRIVACY §5 keine Regel: entscheiden (mit dem Konto löschen, Frist oder bewusst unbegrenzt) und dokumentieren.
- **Konto löschen (PR #5):** `community_warnings.user_id` und `community_bans.user_id` sind `NOT NULL` und lassen sich nicht anonymisieren; beim Löschen oder Anonymisieren eines Kontos entfernt `dsgvo_delete_data` diese Zeilen (die Beiträge werden anonymisiert, `user_id` NULL).
- **Spätere Schema-Kandidaten, nicht in v27:** eine Freigabepflicht auf Zeit nach Verwarnungsschwelle (z. B. `community_user_stats.approval_until`), die Verknüpfung einer automatischen Sperre mit ihrer Verwarnung (`community_bans.source_warning_id`), ein Index `(ip_hash, created_at)` für „gleiche Kennung wie …“ (COM-MOD-16), Anhangsgrenzen je Forum (bisher nur global und je Stufe), ein Löschzeitpunkt für Beiträge im Status `deleted`/`rejected` (`community_posts.deleted_at` samt Index; bisher bleiben `body` und `body_html` unbegrenzt, und `community_topics` hat `trashed_at`, Beiträge nicht) sowie `favorites` (Typfeld, Fremdschlüssel auf `posts` aufheben) und eine Quelle in `search_queries` für die Community-Suchstatistik. Dazu Indizes für spätere Abfragen (EXPLAIN der unabhängigen Prüfung mit 30.000 Themen): `community_topics` (`status`, `created_at`) und (`forum_id`, `status`, `created_at`) für die Feeds „neueste Themen“ gesamt und je Forum (COM-PLUS-12, P2; ohne sie ein Filesort über alle veröffentlichten Themen bzw. das ganze Forum); `community_drafts` (`target_type`, `target_id`) für das Aufräumen der Entwürfe eines gelöschten Themas (`DraftService`, P2; sonst Vollscan); `community_bans` (`lifted_at`, `created_at`) für die Liste der aktiven Sperren in `/admin/community-sanctions` (Sperren P1 in PR #3; kleine Tabelle, im Lasttest prüfen); `community_warnings` (`revoked_at`, `created_at`) für die Liste der aktiven Verwarnungen (P2); ein eindeutiger Schlüssel auf `community_categories.slug_en` wie `uq_community_forums_slug_en` (§2a Nr. 10, „Bewusst offen“; `CategoryRepository::findBySlug()` liest für Englisch alle Zeilen). Für die Statistik „gelöst je Tag“ (COM-ADM-05, P2) ist kein eigener Index nötig: `idx_community_topics_solved` beginnt mit `solved_at` (ein Monat mit 145 Lösungen bei 77.360 Themen: `range`, nur Indexzugriff, 145 statt 77.360 Lesezugriffe; die Gruppierung nach Tag sortiert nur die Treffer). Sie lassen sich bis zum Release nachrüsten (Abschnitt 2, Punkt 5 und 6).
- **Bekannte Grenze des Datenbank-Updates (wie v25/v26):** `DatabaseUpdateRunner::missingSchemaElements()` meldet fehlende Tabellen, die Spalten aus `SchemaManager::REQUIRED_COLUMNS` (für die Community bisher nur `community_badges.description_en`), Kommentar und Kollation der Spalten aus `REQUIRED_COLUMN_DEFINITIONS` (nur `notifications.group_key`, `event_count`; §2 Nr. 7), die Indizes aus `REQUIRED_INDEXES` (`notifications.idx_user_group`, `idx_community_topics_unanswered_all`, `idx_community_topics_solved`; Name und Spalten), die Breite von `posts.status` und den Marker `capabilities_v27`. Fehlende übrige Spalten und Indizes in vorhandenen `community_*`-Tabellen erkennt es nicht und meldet trotzdem Erfolg; nachgetragen werden nur die Spalten, für die `ensureFeatureSchema()` einen `ensureColumnExists()`-Schritt hat (§2 Nr. 6 und 7), und die Indizes aus `REQUIRED_INDEXES` (jede Tabelle dort, §2 Nr. 5 und 9). Neue Spalten gehören deshalb immer in `REQUIRED_COLUMNS` und brauchen einen eigenen `ensureColumnExists()`-Schritt; nachträgliche Indizes brauchen nur den Eintrag in `REQUIRED_INDEXES` (§2 Nr. 5, 6 und 9). Ein Abgleich aller Spalten und Indizes gegen `getSchemaQueries()` (Vorbild `v27ParseCreate()` in `TESTS/schema-v27/lib.php`) ist nicht geplant.
- **Indizes ohne heutige Abfrage:** `idx_community_forums_tree` und `idx_community_forums_parent` (`ForumRepository::tree()` lädt alle Foren ohne Bedingung), `idx_community_ranks_sort` (noch keine Abfrage auf `community_ranks`) sowie `idx_community_forum_permissions_lookup` (bisher nur für die Aufräumung der Rollen-Zeilen, §5.2). **Abfrage vorhanden, Plan offen:** `idx_community_categories_sort` dient `CategoryRepository::listVisible()` (`WHERE is_visible = 1 ORDER BY sort_order, id`); bei wenigen Zeilen liest der Optimierer die Tabelle voll (mit Filesort über die paar Zeilen), an echten Datenmengen prüfen. Keine Dubletten; in PR #2b und #3 an den echten Abfragen prüfen und gegebenenfalls entfernen.
- **Benachrichtigungstypen** (`notifications.type VARCHAR(50)`, Vorschlag): `community.reply`, `community.new_topic`, `community.quote`, `community.mention`, `community.reaction`, `community.solution`, `community.moderation`; ein Filter `cms_community_notification_types` erweitert die Liste.
- **Moderationsaktionen** (`community_moderation_log.action`, Vorschlag): `topic.lock`, `topic.unlock`, `topic.pin`, `topic.unpin`, `topic.announce`, `topic.move`, `topic.merge`, `topic.split`, `topic.rename`, `post.hide`, `post.restore`, `post.edit`, `post.move`, `post.approve`, `post.reject`, `post.delete`, `report.resolve`, `user.warn`, `user.ban`, `user.unban`.

### Verwandte Dokumente

[README.md](README.md) · [IMPLEMENTATION-PLAN.md](IMPLEMENTATION-PLAN.md) · [DATA-MODEL.md](DATA-MODEL.md) · [PERMISSIONS-MODERATION.md](PERMISSIONS-MODERATION.md) · [ARCHITECTURE.md](ARCHITECTURE.md) · [SECURITY-PRIVACY.md](SECURITY-PRIVACY.md) · [TEST-PLAN.md](TEST-PLAN.md) · [../../core/DATABASE-SCHEMA.md](../../../core/DATABASE-SCHEMA.md) · [../../audit/RELEASE-AUDIT-4.1.0-2026-11-15.md](../../../audit/RELEASE-AUDIT-4.1.0-2026-11-15.md)
