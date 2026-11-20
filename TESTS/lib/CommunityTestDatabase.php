<?php
/**
 * Test-Doubles für die Community-Repositories und -Dienste (PR #2a): ein SQLite-Double ohne Server (`CommunityTestDatabase`)
 * und die gemeinsamen Hilfen für Schema und Testdaten (`CommunityTestSchema`, `CommunityFixtures`).
 *
 * Das Double ist eine echte Unterklasse von `CMS\Database` ohne Singleton. Die Tabellen entstehen aus den echten
 * CREATE-Texten von `SchemaManager::getSchemaQueries()` (die Community-Tabellen aus `FEATURE_TABLES_V27` plus `users`,
 * `notifications`, `user_meta`, `user_groups`, `user_group_members`, `settings`, `mail_queue` und `role_permissions`),
 * umgeschrieben für SQLite (Muster `TESTS/invoices/run.php` plus `INT UNSIGNED AUTO_INCREMENT PRIMARY KEY`, verschachtelte
 * Klammern bei Präfixindizes und `ENUM(…)` als `TEXT CHECK (… IN (…))`).
 *
 * Übersetzt wird an einer Stelle (`translate()`): `INSERT IGNORE` wird zu `INSERT OR IGNORE`, ` FOR UPDATE` entfällt (SQLite
 * hat keine Zeilensperren; die Parallelfälle prüft die MariaDB-Suite). Zwei Eigenschaften machen das Double strenger als
 * die Datenbank:
 *  - Zeitspalten der Community-Tabellen (`DATETIME NOT NULL`) haben hier keinen Default: ein vergessenes `created_at` ist
 *    ein Fehler, statt dass MariaDB still die Ortszeit der Sitzung einsetzt.
 *  - `execute()` weist SQL zurück, das der Entwurfsvertrag verbietet (`portabilityViolations()`): MariaDB-Sonderformen
 *    und Zeitfunktionen der Datenbank.
 *
 * Aufruf in Suiten:
 *   require_once dirname(__DIR__) . '/lib/CommunityTestDatabase.php';
 *   $db = CommunityTestDatabase::create();
 *   $forumId = $db->forum(['title' => 'Allgemein']);
 *
 * @package CMSv2\Core\Services\Community
 */

declare(strict_types=1);

use CMS\Database;
use CMS\SchemaManager;

/**
 * CREATE-Texte der Testtabellen und ihre Umschreibung für SQLite.
 */
final class CommunityTestSchema
{
    /**
     * Core-Tabellen, die Community-Code liest oder schreibt (Reihenfolge: `notifications` und `user_meta` hängen an `users`;
     * `notifications` und `mail_queue` für Hinweise und Mails ab PR #2b).
     */
    public const CORE_TABLES = ['users', 'notifications', 'user_meta', 'user_groups', 'user_group_members', 'settings', 'mail_queue'];

    /** Quelle: `cms_ensure_role_permissions_table()` in `CMS/includes/functions/roles.php` (die Suite vergleicht beide). */
    public const ROLE_PERMISSIONS = "CREATE TABLE IF NOT EXISTS {prefix}role_permissions (
        id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        role       VARCHAR(50) NOT NULL,
        capability VARCHAR(100) NOT NULL,
        granted    TINYINT(1) NOT NULL DEFAULT 0,
        UNIQUE KEY uk_role_cap (role, capability)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    /**
     * CREATE-Texte (MySQL/MariaDB) aller Testtabellen: Tabellenname ohne Präfix => SQL.
     *
     * @return array<string, string>
     */
    public static function mysqlStatements(string $prefix = 'cms_'): array
    {
        $queries = SchemaManager::getSchemaQueries($prefix, 'utf8mb4');
        $statements = [];
        foreach (self::CORE_TABLES as $table) {
            $statements[$table] = $queries[$table];
        }
        $statements['role_permissions'] = str_replace('{prefix}', $prefix, self::ROLE_PERMISSIONS);
        foreach (SchemaManager::FEATURE_TABLES_V27 as $table) {
            $statements[$table] = $queries[$table];
        }

        return $statements;
    }

    /**
     * Schreibt einen MySQL-CREATE-Text für SQLite um und liefert die Anweisungen (Tabelle zuerst, dann Indizes).
     * Community-Tabellen verlieren `DEFAULT CURRENT_TIMESTAMP` bei `DATETIME NOT NULL` (siehe Kopfkommentar). `<spalte> ENUM('a', …)`
     * wird `<spalte> TEXT CHECK (<spalte> IN ('a', …))`: SQLite kennt kein ENUM, der CHECK weist fremde Werte ab wie MariaDB im
     * strikten Modus (sonst scheitert schon das Anlegen von `mail_queue`).
     *
     * @return list<string>
     */
    public static function sqlite(string $mysql): array
    {
        $sql = (string) preg_replace('/\)\s*ENGINE=.*$/s', ')', $mysql);
        $sql = (string) preg_replace('/\b(\w+)\s+(?:BIG)?INT(?:\s+UNSIGNED)?\s+AUTO_INCREMENT\s+PRIMARY\s+KEY\b/i', '$1 INTEGER PRIMARY KEY AUTOINCREMENT', $sql);
        $sql = (string) preg_replace("/\\s+COMMENT\\s+'[^']*'/i", '', $sql);
        $sql = (string) preg_replace('/\s+ON\s+UPDATE\s+CURRENT_TIMESTAMP/i', '', $sql);
        $sql = (string) preg_replace("/\\b(\\w+)\\s+ENUM\\s*\\(((?:\\s*'[^']*'\\s*,?)+)\\)/i", '$1 TEXT CHECK ($1 IN ($2))', $sql);
        $sql = (string) preg_replace('/,\s*FOREIGN\s+KEY\s*\([^)]*\)\s*REFERENCES\s+\w+\s*\([^)]*\)(?:\s+ON\s+(?:DELETE|UPDATE)\s+(?:CASCADE|SET\s+NULL|RESTRICT|NO\s+ACTION))*/i', '', $sql);
        preg_match('/CREATE TABLE IF NOT EXISTS (\w+)/', $sql, $table);
        $tableName = (string) ($table[1] ?? '');

        $indexes = [];
        $pattern = '/,\s*(UNIQUE\s+)?(?:KEY|INDEX)\s+(\w+)\s*\(((?:[^()]|\([^()]*\))*)\)/i';
        if (preg_match_all($pattern, $sql, $found, PREG_SET_ORDER) > 0) {
            foreach ($found as $match) {
                $columns = (string) preg_replace('/\(\d+\)/', '', $match[3]);
                $indexes[] = 'CREATE ' . (trim($match[1]) !== '' ? 'UNIQUE ' : '') . 'INDEX IF NOT EXISTS ' . $match[2] . ' ON ' . $tableName . ' (' . $columns . ')';
            }
            $sql = (string) preg_replace($pattern, '', $sql);
        }
        if (str_contains($tableName, 'community_')) {
            $sql = (string) preg_replace('/\bDATETIME\s+NOT\s+NULL\s+DEFAULT\s+CURRENT_TIMESTAMP\b/i', 'DATETIME NOT NULL', $sql);
        }

        return [$sql, ...$indexes];
    }
}

/**
 * Fabrikhilfen für Testdaten und kleine Lesehelfer; benutzt nur `execute()`/`lastInsertId()`/`getPrefix()` und passt
 * deshalb zu beiden Doubles. Die Fixtures schreiben unabhängig von den Repositories (die Tests prüfen diese ja).
 * Alle Zeiten sind UTC-Texte; ohne Angabe gilt `FIXTURE_TIME`.
 */
trait CommunityFixtures
{
    /** Standardzeit der Fixtures (UTC). */
    public const FIXTURE_TIME = '2026-11-15 10:00:00';

    private int $fixtureSeq = 0;

    /**
     * Konto in `users`. Optionen: `username`, `email`, `display_name`, `role` (Standard `member`), `status` (Standard `active`).
     *
     * @param array<string, mixed> $o
     */
    public function user(array $o = []): int
    {
        $n = ++$this->fixtureSeq;

        return $this->fixtureInsert('users', [
            'username' => $o['username'] ?? 'user' . $n,
            'email' => $o['email'] ?? 'user' . $n . '@example.test',
            'password' => 'x',
            'display_name' => $o['display_name'] ?? 'User ' . $n,
            'role' => $o['role'] ?? 'member',
            'status' => $o['status'] ?? 'active',
        ]);
    }

    /** Gruppe in `user_groups` (`$active` false = `is_active` 0). */
    public function group(string $name = '', bool $active = true): int
    {
        $n = ++$this->fixtureSeq;
        $name = $name !== '' ? $name : 'Gruppe ' . $n;

        return $this->fixtureInsert('user_groups', ['name' => $name, 'slug' => 'gruppe-' . $n, 'is_active' => $active ? 1 : 0]);
    }

    /** Mitgliedschaft in `user_group_members`. */
    public function join(int $userId, int $groupId): void
    {
        $this->fixtureInsert('user_group_members', ['user_id' => $userId, 'group_id' => $groupId]);
    }

    /**
     * Forum mit `path` und `depth` aus dem Elternforum. Optionen: `title`, `slug`, `slug_en`, `parent` (Forum-ID), `category`,
     * `status` (Standard `open`), `type`, `inherit` (Standard true), `require_approval`, `slow_mode`, `sort`, `topic_count`,
     * `post_count`, `created_at`.
     *
     * @param array<string, mixed> $o
     */
    public function forum(array $o = []): int
    {
        $n = ++$this->fixtureSeq;
        $parent = isset($o['parent']) ? $this->row('community_forums', ['id' => (int) $o['parent']]) : null;
        if (isset($o['parent']) && $parent === null) {
            throw new \InvalidArgumentException('Fixture forum(): Elternforum ' . (int) $o['parent'] . ' fehlt.');
        }
        $at = (string) ($o['created_at'] ?? self::FIXTURE_TIME);
        $id = $this->fixtureInsert('community_forums', [
            'category_id' => (int) ($o['category'] ?? ($parent['category_id'] ?? 0)),
            'parent_id' => $parent === null ? null : (int) $parent['id'],
            'depth' => $parent === null ? 0 : (int) $parent['depth'] + 1,
            'path' => '',
            'title' => $o['title'] ?? 'Forum ' . $n,
            'slug' => $o['slug'] ?? 'forum-' . $n,
            'slug_en' => $o['slug_en'] ?? null,
            'type' => $o['type'] ?? 'discussion',
            'status' => $o['status'] ?? 'open',
            'require_approval' => !empty($o['require_approval']) ? 1 : 0,
            'slow_mode_seconds' => (int) ($o['slow_mode'] ?? 0),
            'inherit_permissions' => ($o['inherit'] ?? true) ? 1 : 0,
            'sort_order' => (int) ($o['sort'] ?? 0),
            'topic_count' => (int) ($o['topic_count'] ?? 0),
            'post_count' => (int) ($o['post_count'] ?? 0),
            'created_at' => $at,
            'updated_at' => $at,
        ]);
        $this->execute(
            'UPDATE ' . $this->getPrefix() . 'community_forums SET path = ? WHERE id = ?',
            [($parent === null ? '/' : (string) $parent['path']) . $id . '/', $id]
        );

        return $id;
    }

    /**
     * Thema. Optionen: `forum` (Pflicht), `user`, `title`, `slug`, `status` (Standard `published`), `created_at`,
     * `last_post_at` (Standard `created_at`), `last_post_id`, `last_poster_id`, `first_post_id`, `reply_count`,
     * `pinned` (bool), `pinned_until`, `locked` (bool), `locked_until`.
     *
     * @param array<string, mixed> $o
     */
    public function topic(array $o): int
    {
        $n = ++$this->fixtureSeq;
        $at = (string) ($o['created_at'] ?? self::FIXTURE_TIME);

        return $this->fixtureInsert('community_topics', [
            'forum_id' => (int) ($o['forum'] ?? throw new \InvalidArgumentException('Fixture topic(): `forum` fehlt.')),
            'user_id' => $o['user'] ?? null,
            'title' => $o['title'] ?? 'Thema ' . $n,
            'slug' => $o['slug'] ?? 'thema-' . $n,
            'status' => $o['status'] ?? 'published',
            'is_pinned' => !empty($o['pinned']) ? 1 : 0,
            'pinned_until' => $o['pinned_until'] ?? null,
            'is_locked' => !empty($o['locked']) ? 1 : 0,
            'locked_until' => $o['locked_until'] ?? null,
            'first_post_id' => $o['first_post_id'] ?? null,
            'reply_count' => (int) ($o['reply_count'] ?? 0),
            'last_post_id' => $o['last_post_id'] ?? null,
            'last_post_at' => $o['last_post_at'] ?? $at,
            'last_poster_id' => $o['last_poster_id'] ?? null,
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }

    /**
     * Beitrag. Optionen: `topic` (Pflicht), `forum` (Standard: Forum des Themas), `user`, `status` (Standard `published`),
     * `body`, `first` (bool), `status_reason`, `created_at`.
     *
     * @param array<string, mixed> $o
     */
    public function post(array $o): int
    {
        $n = ++$this->fixtureSeq;
        $topicId = (int) ($o['topic'] ?? throw new \InvalidArgumentException('Fixture post(): `topic` fehlt.'));
        $forumId = $o['forum'] ?? ($this->row('community_topics', ['id' => $topicId])['forum_id'] ?? throw new \InvalidArgumentException('Fixture post(): Thema ' . $topicId . ' fehlt.'));
        $at = (string) ($o['created_at'] ?? self::FIXTURE_TIME);

        return $this->fixtureInsert('community_posts', [
            'topic_id' => $topicId,
            'forum_id' => (int) $forumId,
            'user_id' => $o['user'] ?? null,
            'is_first_post' => !empty($o['first']) ? 1 : 0,
            'status' => $o['status'] ?? 'published',
            'status_reason' => $o['status_reason'] ?? null,
            'body' => $o['body'] ?? 'Beitrag ' . $n,
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }

    /**
     * Sperre. Optionen: `user` (Pflicht), `scope` (Standard `write`), `reason`, `internal_note`, `issued_by`, `starts_at`
     * (Standard 2026-01-01 00:00:00), `ends_at`, `lifted_at`, `lifted_by`, `created_at` (Standard `starts_at`).
     *
     * @param array<string, mixed> $o
     */
    public function ban(array $o): int
    {
        $starts = $o['starts_at'] ?? '2026-01-01 00:00:00';

        return $this->fixtureInsert('community_bans', [
            'user_id' => (int) ($o['user'] ?? throw new \InvalidArgumentException('Fixture ban(): `user` fehlt.')),
            'scope' => $o['scope'] ?? 'write',
            'reason' => $o['reason'] ?? 'Testsperre',
            'internal_note' => $o['internal_note'] ?? null,
            'issued_by' => $o['issued_by'] ?? null,
            'starts_at' => $starts,
            'ends_at' => $o['ends_at'] ?? null,
            'lifted_at' => $o['lifted_at'] ?? null,
            'lifted_by' => $o['lifted_by'] ?? null,
            'created_at' => $o['created_at'] ?? $starts,
        ]);
    }

    /**
     * Kategorie. Optionen: `title`, `slug`, `title_en`, `slug_en`, `visible` (Standard true), `sort`, `created_at`.
     *
     * @param array<string, mixed> $o
     */
    public function category(array $o = []): int
    {
        $n = ++$this->fixtureSeq;
        $at = (string) ($o['created_at'] ?? self::FIXTURE_TIME);

        return $this->fixtureInsert('community_categories', [
            'title' => $o['title'] ?? 'Kategorie ' . $n,
            'title_en' => $o['title_en'] ?? null,
            'slug' => $o['slug'] ?? 'kategorie-' . $n,
            'slug_en' => $o['slug_en'] ?? null,
            'sort_order' => (int) ($o['sort'] ?? 0),
            'is_visible' => ($o['visible'] ?? true) ? 1 : 0,
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }

    /**
     * Meldung. Optionen: `target` (Pflicht), `target_type` (Standard `post`), `forum`, `reporter`, `reason` (Standard `spam`),
     * `message`, `status` (Standard `open`), `notify` (Standard 1), `resolution_note`, `handled_by`, `handled_at`, `created_at`.
     *
     * @param array<string, mixed> $o
     */
    public function report(array $o): int
    {
        return $this->fixtureInsert('community_reports', [
            'target_type' => $o['target_type'] ?? 'post',
            'target_id' => (int) ($o['target'] ?? throw new \InvalidArgumentException('Fixture report(): `target` fehlt.')),
            'forum_id' => $o['forum'] ?? null,
            'reporter_id' => $o['reporter'] ?? null,
            'reason' => $o['reason'] ?? 'spam',
            'message' => $o['message'] ?? null,
            'status' => $o['status'] ?? 'open',
            'resolution_note' => $o['resolution_note'] ?? null,
            'notify_reporter' => (int) ($o['notify'] ?? 1),
            'handled_by' => $o['handled_by'] ?? null,
            'handled_at' => $o['handled_at'] ?? null,
            'created_at' => $o['created_at'] ?? self::FIXTURE_TIME,
        ]);
    }

    /**
     * Abo (Schlüssel aus Mitglied, Zieltyp und Ziel). Optionen: `user` und `target` (Pflicht), `target_type` (Standard `topic`),
     * `mode` (Standard `instant`), `notified_at`, `created_at`.
     *
     * @param array<string, mixed> $o
     */
    public function subscription(array $o): void
    {
        $this->fixtureInsert('community_subscriptions', [
            'user_id' => (int) ($o['user'] ?? throw new \InvalidArgumentException('Fixture subscription(): `user` fehlt.')),
            'target_type' => $o['target_type'] ?? 'topic',
            'target_id' => (int) ($o['target'] ?? throw new \InvalidArgumentException('Fixture subscription(): `target` fehlt.')),
            'mode' => $o['mode'] ?? 'instant',
            'notified_at' => $o['notified_at'] ?? null,
            'created_at' => $o['created_at'] ?? self::FIXTURE_TIME,
        ]);
    }

    /**
     * Reaktion (Schlüssel aus Beitrag und Mitglied). Optionen: `post` und `user` (Pflicht), `reaction` (Standard `like`), `created_at`.
     * Zähler (`reaction_count`, `reactions_*`) setzt die Fixture nicht.
     *
     * @param array<string, mixed> $o
     */
    public function reaction(array $o): void
    {
        $this->fixtureInsert('community_reactions', [
            'post_id' => (int) ($o['post'] ?? throw new \InvalidArgumentException('Fixture reaction(): `post` fehlt.')),
            'user_id' => (int) ($o['user'] ?? throw new \InvalidArgumentException('Fixture reaction(): `user` fehlt.')),
            'reaction' => $o['reaction'] ?? 'like',
            'created_at' => $o['created_at'] ?? self::FIXTURE_TIME,
        ]);
    }

    /**
     * Rang. Optionen: `title`, `title_en`, `min_posts` (Standard 0, `null` = nur manuell), `color`, `icon`, `sort`.
     *
     * @param array<string, mixed> $o
     */
    public function rank(array $o = []): int
    {
        $n = ++$this->fixtureSeq;

        return $this->fixtureInsert('community_ranks', [
            'title' => $o['title'] ?? 'Rang ' . $n,
            'title_en' => $o['title_en'] ?? null,
            'min_posts' => array_key_exists('min_posts', $o) ? $o['min_posts'] : 0,
            'color' => $o['color'] ?? null,
            'icon' => $o['icon'] ?? null,
            'sort_order' => (int) ($o['sort'] ?? 0),
        ]);
    }

    /**
     * Zustellauftrag in `community_notify_queue` (Tabelle ab PR #2b, Einheit N). Optionen: `kind` (Standard `reply`), `topic`,
     * `post`, `actor`, `detail`, `phase`, `cursor`, `attempts`, `steps`, `lease_token`, `locked_until`, `created_at`.
     *
     * @param array<string, mixed> $o
     */
    public function queueJob(array $o = []): int
    {
        $at = (string) ($o['created_at'] ?? self::FIXTURE_TIME);

        return $this->fixtureInsert('community_notify_queue', [
            'kind' => $o['kind'] ?? 'reply',
            'topic_id' => (int) ($o['topic'] ?? 0),
            'post_id' => (int) ($o['post'] ?? 0),
            'actor_id' => (int) ($o['actor'] ?? 0),
            'detail' => $o['detail'] ?? null,
            'phase' => (int) ($o['phase'] ?? 0),
            'cursor_user_id' => (int) ($o['cursor'] ?? 0),
            'attempts' => (int) ($o['attempts'] ?? 0),
            'steps' => (int) ($o['steps'] ?? 0),
            'lease_token' => $o['lease_token'] ?? null,
            'locked_until' => $o['locked_until'] ?? null,
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }

    /**
     * Anhang. Optionen: `post` (fehlt = noch nicht zugeordnet), `user`, `token` (`upload_token`), `storage_path`, `original_name`,
     * `mime_type`, `extension` (Standard `txt`), `file_size`, `sha256`, `width`, `height`, `preview` (bool), `downloads`, `created_at`.
     *
     * @param array<string, mixed> $o
     */
    public function attachment(array $o = []): int
    {
        $n = ++$this->fixtureSeq;
        $extension = (string) ($o['extension'] ?? 'txt');

        return $this->fixtureInsert('community_attachments', [
            'post_id' => $o['post'] ?? null,
            'user_id' => $o['user'] ?? null,
            'storage_path' => $o['storage_path'] ?? 'community/test/' . $n . '.' . $extension,
            'original_name' => $o['original_name'] ?? 'datei-' . $n . '.' . $extension,
            'mime_type' => $o['mime_type'] ?? 'text/plain',
            'extension' => $extension,
            'file_size' => (int) ($o['file_size'] ?? 100),
            'sha256' => $o['sha256'] ?? hash('sha256', 'anhang-' . $n),
            'width' => $o['width'] ?? null,
            'height' => $o['height'] ?? null,
            'has_preview' => !empty($o['preview']) ? 1 : 0,
            'download_count' => (int) ($o['downloads'] ?? 0),
            'upload_token' => $o['token'] ?? null,
            'created_at' => $o['created_at'] ?? self::FIXTURE_TIME,
        ]);
    }

    /**
     * Eintrag im Moderationsprotokoll. Optionen: `action` und `target` (Pflicht), `actor` (`null` = System), `target_type`
     * (Standard `post`), `forum`, `reason`, `details` (Array → JSON wie `ModerationLogRepository`, Text unverändert), `created_at`.
     *
     * @param array<string, mixed> $o
     */
    public function moderationLog(array $o): int
    {
        $details = $o['details'] ?? null;

        return $this->fixtureInsert('community_moderation_log', [
            'actor_id' => $o['actor'] ?? null,
            'action' => (string) ($o['action'] ?? throw new \InvalidArgumentException('Fixture moderationLog(): `action` fehlt.')),
            'target_type' => $o['target_type'] ?? 'post',
            'target_id' => (int) ($o['target'] ?? throw new \InvalidArgumentException('Fixture moderationLog(): `target` fehlt.')),
            'forum_id' => $o['forum'] ?? null,
            'reason' => $o['reason'] ?? null,
            'details_json' => is_array($details) ? json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) : $details,
            'created_at' => $o['created_at'] ?? self::FIXTURE_TIME,
        ]);
    }

    /**
     * Zeile in `community_user_stats`. Optionen: `topic_count`, `post_count`, `trust_level`, `trust_level_locked`, `last_post_at`.
     *
     * @param array<string, mixed> $o
     */
    public function stats(int $userId, array $o = []): void
    {
        $this->fixtureInsert('community_user_stats', [
            'user_id' => $userId,
            'topic_count' => (int) ($o['topic_count'] ?? 0),
            'post_count' => (int) ($o['post_count'] ?? 0),
            'trust_level' => (int) ($o['trust_level'] ?? 0),
            'trust_level_locked' => !empty($o['trust_level_locked']) ? 1 : 0,
            'last_post_at' => $o['last_post_at'] ?? null,
            'updated_at' => self::FIXTURE_TIME,
        ]);
    }

    /**
     * Erste Zeile einer Tabelle (Name ohne Präfix) mit Gleichheitsbedingungen (`null` = IS NULL).
     *
     * @param array<string, mixed> $where
     * @return array<string, mixed>|null
     */
    public function row(string $table, array $where): ?array
    {
        $rows = $this->rows($table, $where, null, 1);

        return $rows[0] ?? null;
    }

    /**
     * Zeilen einer Tabelle (Name ohne Präfix) mit Gleichheitsbedingungen, aufsteigend nach `$orderBy` (nur Spaltenname;
     * ohne Angabe ungeordnet: Tabellen mit zusammengesetztem Schlüssel haben keine Spalte `id`).
     *
     * @param array<string, mixed> $where
     * @return list<array<string, mixed>>
     */
    public function rows(string $table, array $where = [], ?string $orderBy = null, int $limit = 1000): array
    {
        [$clause, $params] = $this->fixtureWhere($where);
        if ($orderBy !== null && preg_match('/^[a-z_]+$/', $orderBy) !== 1) {
            throw new \InvalidArgumentException('Ungültige Sortierspalte.');
        }
        $stmt = $this->execute(
            'SELECT * FROM ' . $this->fixtureTable($table) . $clause . ($orderBy !== null ? ' ORDER BY ' . $orderBy . ' ASC' : '') . ' LIMIT ?',
            [...$params, $limit]
        );

        return array_values($stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * Zahl der Zeilen einer Tabelle (Name ohne Präfix) mit Gleichheitsbedingungen.
     *
     * @param array<string, mixed> $where
     */
    public function count(string $table, array $where = []): int
    {
        [$clause, $params] = $this->fixtureWhere($where);

        return (int) $this->execute('SELECT COUNT(*) FROM ' . $this->fixtureTable($table) . $clause, $params)->fetchColumn();
    }

    private function fixtureTable(string $table): string
    {
        if (preg_match('/^[a-z_]+$/', $table) !== 1) {
            throw new \InvalidArgumentException('Ungültiger Tabellenname.');
        }

        return $this->getPrefix() . $table;
    }

    /**
     * @param array<string, mixed> $where
     * @return array{0: string, 1: list<mixed>}
     */
    private function fixtureWhere(array $where): array
    {
        $parts = [];
        $params = [];
        foreach ($where as $column => $value) {
            if (preg_match('/^[a-z_]+$/', (string) $column) !== 1) {
                throw new \InvalidArgumentException('Ungültiger Spaltenname.');
            }
            if ($value === null) {
                $parts[] = $column . ' IS NULL';
            } else {
                $parts[] = $column . ' = ?';
                $params[] = $value;
            }
        }

        return [$parts === [] ? '' : ' WHERE ' . implode(' AND ', $parts), $params];
    }

    /** @param array<string, mixed> $row */
    private function fixtureInsert(string $table, array $row): int
    {
        $this->execute(
            'INSERT INTO ' . $this->fixtureTable($table) . ' (' . implode(', ', array_keys($row)) . ') VALUES (' . implode(', ', array_fill(0, count($row), '?')) . ')',
            array_values($row)
        );

        return $this->lastInsertId();
    }
}

/**
 * SQLite-Double von `CMS\Database` für die DB-freien Community-Suiten (läuft in der CI ohne Datenbankserver).
 */
final class CommunityTestDatabase extends Database
{
    use CommunityFixtures;

    public \PDO $sqlite;
    /** @var list<string> Reguläre Ausdrücke: passt einer auf das SQL (vor der Übersetzung), wirft `execute()` eine `PDOException`. */
    public array $failOn = [];
    /** @var array<string, \Throwable> Regulärer Ausdruck => Ausnahme: passt der Ausdruck auf das SQL, wirft `execute()` genau diese (z. B. eine PDOException mit SQLSTATE 23000). */
    public array $throwOn = [];
    /**
     * @var array<string, \Closure(): void> Regulärer Ausdruck => Aufruf: passt der Ausdruck auf das SQL, läuft der Aufruf einmal vor
     *      der Anweisung (der Eintrag wird vorher entfernt), z. B. um eine Zeile zwischen zwei Abfragen eines Repositorys zu ändern.
     */
    public array $beforeExecute = [];
    /** @var list<string> Alle ausgeführten SQL-Texte (vor der Übersetzung, ohne Parameter). */
    public array $log = [];
    /**
     * @var array<int, bool> Zum Eintrag `$log[$i]`: lief die Anweisung innerhalb einer Transaktion (`PDO::inTransaction()` im
     *                       Augenblick des Aufrufs)? `BEGIN`/`COMMIT` selbst stehen nicht im Protokoll (sie laufen über die
     *                       PDO-Verbindung); dieses Feld macht sichtbar, welche Anweisungen vor dem `BEGIN` liegen. Nach
     *                       `$log = []` zählt der Index neu, ältere Einträge werden überschrieben: nur `txAt()` benutzen.
     */
    public array $logTx = [];
    /**
     * @var array<int, list<mixed>> Zum Eintrag `$log[$i]`: die gebundenen Parameter (z. B. IDs einer Sperrfolge in aufsteigender
     *                              Reihenfolge prüfen). Gleiche Indexregel wie `$logTx`: nur über `paramsAt()` lesen.
     */
    public array $logParams = [];

    /** @param string|null $file SQLite-Datei statt Arbeitsspeicher (zwei Verbindungen auf dieselben Daten) */
    public function __construct(?string $file = null)
    {
        $this->prefix = 'cms_';
        $this->sqlite = new \PDO($file !== null ? 'sqlite:' . $file : 'sqlite::memory:', null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_OBJ,
        ]);
        foreach (CommunityTestSchema::mysqlStatements($this->prefix) as $mysql) {
            foreach (CommunityTestSchema::sqlite($mysql) as $statement) {
                $this->sqlite->exec($statement);
            }
        }
    }

    public static function create(?string $file = null): self
    {
        return new self($file);
    }

    /**
     * MariaDB-Form → SQLite: `INSERT IGNORE` wird `INSERT OR IGNORE`, ` FOR UPDATE` entfällt.
     */
    public static function translate(string $sql): string
    {
        $sql = (string) preg_replace('/\bINSERT\s+IGNORE\b/i', 'INSERT OR IGNORE', $sql);

        return str_replace(' FOR UPDATE', '', $sql);
    }

    /**
     * Konstrukte, die der Entwurfsvertrag für Community-SQL verbietet (nicht portabel oder Zeit aus der Datenbank).
     *
     * @return list<string> gefundene Konstrukte, leer = in Ordnung
     */
    public static function portabilityViolations(string $sql): array
    {
        $rules = [
            'NOW()' => '/\bNOW\s*\(/i',
            'CURRENT_TIMESTAMP' => '/\bCURRENT_TIMESTAMP\b/i',
            'CURDATE()' => '/\bCURDATE\s*\(/i',
            'UTC_TIMESTAMP' => '/\bUTC_TIMESTAMP\b/i',
            'OFFSET' => '/\bOFFSET\b/i',
            'ON DUPLICATE KEY' => '/\bON\s+DUPLICATE\s+KEY\b/i',
            'GREATEST()' => '/\bGREATEST\s*\(/i',
            'LEAST()' => '/\bLEAST\s*\(/i',
            'FIELD()' => '/\bFIELD\s*\(/i',
            'JSON_*()' => '/\bJSON_[A-Z_]+\s*\(/i',
            'Zeilenkonstruktor' => '/\(\s*[\w.]+\s*,\s*[\w.]+\s*\)\s*(?:<=|>=|<>|<|>|=)\s*\(/',
        ];
        $found = [];
        foreach ($rules as $label => $pattern) {
            if (preg_match($pattern, $sql) === 1) {
                $found[] = $label;
            }
        }

        return $found;
    }

    /** Lief die Anweisung `$log[$index]` innerhalb einer Transaktion? (`false` für unbekannte Indizes.) */
    public function txAt(int $index): bool
    {
        return isset($this->log[$index]) && ($this->logTx[$index] ?? false);
    }

    /**
     * Gebundene Parameter der Anweisung `$log[$index]` (`[]` für unbekannte Indizes).
     *
     * @return list<mixed>
     */
    public function paramsAt(int $index): array
    {
        return isset($this->log[$index]) ? ($this->logParams[$index] ?? []) : [];
    }

    public function execute(string $sql, array $params = []): \PDOStatement
    {
        foreach ($this->beforeExecute as $pattern => $hook) {
            if (preg_match($pattern, $sql) === 1) {
                unset($this->beforeExecute[$pattern]);
                $hook();
            }
        }
        $this->logTx[count($this->log)] = $this->sqlite->inTransaction();
        $this->logParams[count($this->log)] = array_values($params);
        $this->log[] = $sql;
        foreach ($this->failOn as $pattern) {
            if (preg_match($pattern, $sql) === 1) {
                throw new \PDOException('Testfehler (Injektion): ' . $pattern);
            }
        }
        foreach ($this->throwOn as $pattern => $exception) {
            if (preg_match($pattern, $sql) === 1) {
                throw $exception;
            }
        }
        $violations = self::portabilityViolations($sql);
        if ($violations !== []) {
            throw new \LogicException('Nicht portables SQL (' . implode(', ', $violations) . '): ' . $sql);
        }

        $stmt = $this->sqlite->prepare(self::translate($sql));
        foreach (array_values($params) as $i => $value) {
            if (is_int($value)) {
                $stmt->bindValue($i + 1, $value, \PDO::PARAM_INT);
            } elseif (is_bool($value)) {
                $stmt->bindValue($i + 1, $value, \PDO::PARAM_BOOL);
            } elseif ($value === null) {
                $stmt->bindValue($i + 1, null, \PDO::PARAM_NULL);
            } else {
                $stmt->bindValue($i + 1, (string) $value, \PDO::PARAM_STR);
            }
        }
        $stmt->execute();

        return $stmt;
    }

    public function get_var(string $query, array $params = []): mixed
    {
        $value = $this->execute($query, $params)->fetchColumn();

        return $value === false ? null : $value;
    }

    public function get_results(string $query, array $params = []): array
    {
        return $this->execute($query, $params)->fetchAll(\PDO::FETCH_OBJ);
    }

    public function get_row(string $query, array $params = []): ?object
    {
        return $this->execute($query, $params)->fetch(\PDO::FETCH_OBJ) ?: null;
    }

    public function get_col(string $query, array $params = []): array
    {
        return $this->execute($query, $params)->fetchAll(\PDO::FETCH_COLUMN);
    }

    public function lastInsertId(): int
    {
        return (int) $this->sqlite->lastInsertId();
    }

    public function getPdo(): \PDO
    {
        return $this->sqlite;
    }

    public function getConnection(): \PDO
    {
        return $this->sqlite;
    }

    public function getPrefix(): string
    {
        return $this->prefix;
    }

    public function tableExists(string $table): bool
    {
        return (int) $this->sqlite->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = " . $this->sqlite->quote($table))->fetchColumn() > 0;
    }
}
