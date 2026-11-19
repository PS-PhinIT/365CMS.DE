<?php
/**
 * Datenzugriff der Community-Foren (`community_forums`) samt Rechte-Zeilen (`community_forum_permissions`) und
 * Forum-Moderatoren (`community_forum_moderators`).
 *
 * - Der Baum steht in `parent_id`, `depth` und `path` (`/3/17/` für Forum 17 unter Forum 3; der Pfad enthält die eigene ID).
 *   `tree()` liest alle Foren einmal je Instanz; Strukturänderungen über diese Klasse verwerfen den Zwischenspeicher.
 *   Zähler im Zwischenspeicher können veralten: für Zähler `find()` benutzen.
 * - Rechte: dieses Repository liefert nur Zeilen (`permissionRows()`, `hasPermissionRows()`); wer was darf, entscheidet
 *   `CommunityAccess`.
 * - Zähler (`applyCounters()`, `bumpCounters()`) ändern sich atomar in einer Anweisung und fallen nie unter 0.
 * - Sperrreihenfolge der Dienste: Forum, Thema, Beitrag, Benutzerstatistik (`lockForUpdate()`).
 * - Slug-Eindeutigkeit über beide Spalten (`slug`, `slug_en`): die zwei UNIQUE-Indizes sehen den Kreuzfall nicht (Slug des einen
 *   gleich `slug_en` des anderen), die Prüfung `slugTaken()` allein ist ohne Sperre ein Wettlauf. `create()` und `update()` (wenn
 *   `slug` oder `slug_en` sich ändert) prüfen und schreiben deshalb unter einer Mutex-Zeile in der Tabelle `{prefix}settings`:
 *   `option_name = 'community_lock_forum_slugs'` (bewusst ohne Punkt, damit sie nicht zur Einstellungsgruppe `community` gehört
 *   und nie als Einstellung gelesen oder ausgegeben wird), `option_value = '0'`, `autoload = 0`. Die Zeile entsteht VOR der
 *   Transaktion im Autocommit (`INSERT IGNORE`); in der Transaktion ist die Zeilensperre dieser Zeile die ERSTE Anweisung, danach
 *   laufen `slugTaken()` und das Schreiben (kein nicht sperrender Zugriff davor: der Schnappschuss von REPEATABLE READ entsteht
 *   erst nach der Sperre und sieht jedes gleichzeitig angelegte Forum). Alle Schreiber der Slugs serialisieren sich an dieser
 *   einen Zeile; Foren werden selten angelegt.
 *
 * @package CMSv2\Core\Services\Community
 */

declare(strict_types=1);

namespace CMS\Services\Community\Repository;

use CMS\Services\Community\Support\Slugger;
use CMS\Services\Community\Support\Transaction;

if (!defined('ABSPATH')) {
    exit;
}

final class ForumRepository extends AbstractRepository
{
    /** Forenstatus (HANDOFF §3). */
    public const STATUSES = ['open', 'readonly', 'archived', 'hidden'];
    /** Forentypen (HANDOFF §3). */
    public const TYPES = ['discussion', 'qa', 'announcements', 'link'];
    /** Rechte-Schlüssel der Matrix (PERMISSIONS-MODERATION §3); alles andere in `permissions_json` wird ignoriert. */
    public const PERMISSION_KEYS = [
        'read', 'create_topic', 'reply', 'edit_own', 'delete_own', 'upload', 'poll', 'vote', 'react', 'mark_solution',
        'bypass_approval', 'bypass_slow_mode',
    ];
    /** Subjekte der Rechte-Zeilen. */
    public const PERMISSION_SUBJECTS = ['guest', 'role', 'group', 'trust'];
    /** Subjekte der Forum-Moderatoren. */
    public const MODERATOR_SUBJECTS = ['user', 'group'];
    /** Tabelle der Mutex-Zeile für die Slug-Eindeutigkeit (Kernstabelle `settings`). */
    private const SETTINGS = 'settings';
    /** `option_name` der Mutex-Zeile: ohne Punkt, damit sie nicht zur Einstellungsgruppe `community` gehört. */
    public const SLUG_LOCK = 'community_lock_forum_slugs';
    private const TABLE = 'community_forums';
    private const PERMISSIONS = 'community_forum_permissions';
    private const MODERATORS = 'community_forum_moderators';

    /** Spalten, die `create()` aus den Eingabedaten übernimmt (`depth` und `path` setzt die Methode selbst). */
    private const INSERTABLE = [
        'category_id', 'parent_id', 'title', 'title_en', 'slug', 'slug_en', 'description', 'description_en', 'icon', 'color',
        'type', 'link_url', 'status', 'require_approval', 'slow_mode_seconds', 'auto_close_days', 'inherit_permissions',
        'sort_order',
    ];

    /** Spalten, die `update()` ändern darf (Umhängen im Baum und Zähler gehören nicht dazu). */
    private const UPDATABLE = [
        'category_id', 'title', 'title_en', 'slug', 'slug_en', 'description', 'description_en', 'icon', 'color', 'type',
        'link_url', 'status', 'require_approval', 'slow_mode_seconds', 'auto_close_days', 'inherit_permissions', 'sort_order',
    ];

    /** Schalter-Spalten, die als 0/1 gespeichert werden. */
    private const FLAGS = ['require_approval', 'inherit_permissions'];

    /**
     * Grenzen der Spalten laut Schema (`TESTS/schema-v27/golden.json`): `title`/`title_en` VARCHAR(150), `icon` VARCHAR(50),
     * `color` VARCHAR(9), `link_url` VARCHAR(500), Beschreibungen TEXT (65.535 Bytes), Zahlen INT UNSIGNED. Slugs und Auswahlwerte
     * (`status`, `type`) prüft `invalid()` gesondert. Format: siehe `AbstractRepository::exceeds()`.
     */
    private const LIMITS = [
        'title' => ['chars', 150, false], 'title_en' => ['chars', 150, true],
        'description' => ['bytes', 65535, true], 'description_en' => ['bytes', 65535, true],
        'icon' => ['chars', 50, true], 'color' => ['chars', 9, true], 'link_url' => ['chars', 500, true],
        'category_id' => ['uint'], 'slow_mode_seconds' => ['uint'], 'auto_close_days' => ['uint'], 'sort_order' => ['uint'],
        'require_approval' => ['flag'], 'inherit_permissions' => ['flag'],
    ];

    /** @var array<int, array<string, mixed>>|null */
    private ?array $tree = null;

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->fetchOne('SELECT * FROM ' . $this->table(self::TABLE) . ' WHERE id = ? LIMIT 1', [$id]);
    }

    /**
     * Auflösen über den Slug. `$locale`: `de` über `slug`, `en` über `slug_en` (fehlt er, über `slug`), `null` in beiden
     * Spalten (Treffer in `slug` zuerst).
     *
     * @return array<string, mixed>|null
     */
    public function findBySlug(string $slug, ?string $locale = null): ?array
    {
        $table = $this->table(self::TABLE);
        if ($locale === 'de') {
            return $this->fetchOne("SELECT * FROM {$table} WHERE slug = ? LIMIT 1", [$slug]);
        }
        if ($locale === 'en') {
            return $this->fetchOne(
                "SELECT * FROM {$table} WHERE slug_en = ? OR (slug_en IS NULL AND slug = ?) ORDER BY id ASC LIMIT 1",
                [$slug, $slug]
            );
        }

        return $this->fetchOne(
            "SELECT * FROM {$table} WHERE slug = ? OR slug_en = ? ORDER BY CASE WHEN slug = ? THEN 0 ELSE 1 END ASC, id ASC LIMIT 1",
            [$slug, $slug, $slug]
        );
    }

    /**
     * Alle Foren, Schlüssel = Forum-ID, Reihenfolge: Kategorie, Tiefe, Sortierung, ID. Je Instanz einmal gelesen.
     *
     * @return array<int, array<string, mixed>>
     */
    public function tree(): array
    {
        if ($this->tree === null) {
            $tree = [];
            foreach ($this->fetchAll('SELECT * FROM ' . $this->table(self::TABLE) . ' ORDER BY category_id ASC, depth ASC, sort_order ASC, id ASC') as $row) {
                $tree[(int) $row['id']] = $row;
            }
            $this->tree = $tree;
        }

        return $this->tree;
    }

    /** Verwirft den Zwischenspeicher von `tree()` (nötig, wenn ein anderer Schreiber den Baum geändert hat). */
    public function forgetTree(): void
    {
        $this->tree = null;
    }

    /**
     * Forum und alle Vorfahren, das Forum zuerst, dann das Elternforum usw. (für Moderatoren: Vererbung über `path`,
     * unabhängig von `inherit_permissions`). Unbekannte ID: leere Liste.
     *
     * @return list<int>
     */
    public function ancestorIds(int $forumId): array
    {
        $row = $this->tree()[$forumId] ?? null;

        return $row === null ? [] : self::pathIds($row, $forumId);
    }

    /**
     * Forum und die Vorfahren, deren Rechte es erbt: das Forum zuerst; die Kette endet am ersten Forum mit
     * `inherit_permissions = 0` (dieses ist das letzte Glied) oder an der Wurzel. Unbekannte ID: leere Liste.
     *
     * @return list<int>
     */
    public function chainIds(int $forumId): array
    {
        $tree = $this->tree();
        if (!isset($tree[$forumId])) {
            return [];
        }
        $chain = [];
        foreach (self::pathIds($tree[$forumId], $forumId) as $id) {
            $row = $tree[$id] ?? null;
            if ($row === null) {
                break;
            }
            $chain[] = $id;
            if ((int) ($row['inherit_permissions'] ?? 1) !== 1) {
                break;
            }
        }

        return $chain;
    }

    /**
     * Alle Unterforen (ohne das Forum selbst): jedes Forum, dessen Pfad mit dem Pfad dieses Forums beginnt
     * (`path LIKE '<Pfad des Forums>%'`, Index `idx_community_forums_path`), aufsteigend nach ID. Unbekannte ID: leere Liste.
     *
     * @return list<int>
     */
    public function descendantIds(int $forumId): array
    {
        $path = $this->fetchValue('SELECT path FROM ' . $this->table(self::TABLE) . ' WHERE id = ?', [$forumId]);
        if (!is_string($path) || preg_match('#^/(?:\d+/)+$#', $path) !== 1) {
            return [];
        }
        $rows = $this->fetchAll(
            'SELECT id FROM ' . $this->table(self::TABLE) . ' WHERE path LIKE ? AND id <> ? ORDER BY id ASC',
            [$path . '%', $forumId]
        );

        return array_map(static fn (array $row): int => (int) $row['id'], $rows);
    }

    /**
     * Legt ein Forum an und setzt `depth` und `path` nach dem Einfügen (die eigene ID gehört in den Pfad).
     * `$maxDepth` ist die Zahl der Ebenen (die Einstellung dazu folgt mit der Forenverwaltung in PR #3, dort liest sie der Aufrufer): ein Forum der Tiefe `$maxDepth` oder mehr wird abgelehnt.
     * Alles in einer Transaktion; Slugs müssen über beide Spalten (`slug`, `slug_en`) eindeutig sein und dürfen kein reserviertes
     * Pfadsegment sein (`Slugger::isReserved()`, sonst `slug_taken`). Längen und Bereiche der Spalten prüft die Methode vor dem
     * Schreiben (`invalid_input` statt `PDOException`).
     * Fachfehler: `invalid_input`, `forum_not_found` (Elternforum), `forum_depth`, `slug_taken`.
     *
     * @param array<string, mixed> $data Spalten aus INSERTABLE; `parent_id` leer, 0 oder NULL = oberste Ebene
     * @return array{success: bool, id?: int, depth?: int, path?: string, error?: string}
     */
    public function create(array $data, int $maxDepth, string $nowUtc): array
    {
        $data = self::pick($data, self::INSERTABLE);
        $invalid = $this->invalid($data, true);
        if ($invalid !== null) {
            return ['success' => false, 'error' => $invalid];
        }
        $parent = null;
        $parentId = (int) ($data['parent_id'] ?? 0);
        if ($parentId > 0) {
            $parent = $this->find($parentId);
            if ($parent === null) {
                return ['success' => false, 'error' => 'forum_not_found'];
            }
            if ((int) ($data['category_id'] ?? 0) === 0) {
                $data['category_id'] = (int) $parent['category_id'];
            }
        }
        $depth = $parent === null ? 0 : (int) $parent['depth'] + 1;
        if ($depth >= max(1, $maxDepth)) {
            return ['success' => false, 'error' => 'forum_depth'];
        }
        $data['parent_id'] = $parent === null ? null : $parentId;
        $data = $this->normalize($data);
        $data['depth'] = $depth;
        $data['path'] = '';
        $data['created_at'] = $nowUtc;
        $data['updated_at'] = $nowUtc;
        $pathPrefix = '/';
        if ($parent !== null) {
            $pathPrefix = preg_match('#^/(?:\d+/)+$#', (string) $parent['path']) === 1 ? (string) $parent['path'] : '/' . $parentId . '/';
        }

        try {
            // Mutex-Zeile vor der Transaktion (Autocommit); in der Transaktion ist ihre Sperre die erste Anweisung.
            $this->ensureSlugLock();
            $result = Transaction::run($this->db, function () use ($data, $depth, $pathPrefix): array {
                $this->lockSlugs();
                if ($this->slugTaken((string) $data['slug'], $data['slug_en'] ?? null, null)) {
                    return ['success' => false, 'error' => 'slug_taken'];
                }
                $id = $this->insertRow(self::TABLE, $data, [...self::INSERTABLE, 'depth', 'path', 'created_at', 'updated_at']);
                $path = $pathPrefix . $id . '/';
                $this->run('UPDATE ' . $this->table(self::TABLE) . ' SET path = ? WHERE id = ?', [$path, $id]);

                return ['success' => true, 'id' => $id, 'depth' => $depth, 'path' => $path];
            });
        } catch (\PDOException $e) {
            if (self::isDuplicateKey($e)) {
                return ['success' => false, 'error' => 'slug_taken'];
            }
            throw $e;
        } finally {
            $this->forgetTree();
        }

        return $result;
    }

    /**
     * Ändert Spalten aus UPDATABLE, erhöht `version` und setzt `updated_at`. Ein leerer Änderungssatz ändert nichts.
     * Slugs und Spaltengrenzen wie bei `create()` (reserviertes Segment → `slug_taken`, zu lang oder außerhalb → `invalid_input`).
     * Fachfehler: `forum_not_found`, `invalid_input`, `slug_taken`.
     *
     * @param array<string, mixed> $data
     * @return array{success: bool, error?: string}
     */
    public function update(int $id, array $data, string $nowUtc): array
    {
        $data = self::pick($data, self::UPDATABLE);
        $row = $this->find($id);
        if ($row === null) {
            return ['success' => false, 'error' => 'forum_not_found'];
        }
        if ($data === []) {
            return ['success' => true];
        }
        $invalid = $this->invalid($data, false);
        if ($invalid !== null) {
            return ['success' => false, 'error' => $invalid];
        }
        $data = $this->normalize($data);
        $changed = [];
        foreach (['slug', 'slug_en'] as $column) {
            if (array_key_exists($column, $data) && $data[$column] !== null && $data[$column] !== $row[$column]) {
                $changed[] = (string) $data[$column];
            }
        }
        $set = implode(', ', array_map(static fn (string $column): string => $column . ' = ?', array_keys($data)));
        $sql = 'UPDATE ' . $this->table(self::TABLE) . " SET {$set}, version = version + 1, updated_at = ? WHERE id = ?";
        $params = [...array_values($data), $nowUtc, $id];
        try {
            if ($changed === []) {
                $this->run($sql, $params);
            } else {
                // Ändert sich ein Slug: Prüfung und Schreiben unter der Mutex-Zeile (Anlage vor der Transaktion, Sperre zuerst).
                $this->ensureSlugLock();
                $free = Transaction::run($this->db, function () use ($changed, $id, $sql, $params): bool {
                    $this->lockSlugs();
                    if ($this->slugConflict($changed, $id)) {
                        return false;
                    }
                    $this->run($sql, $params);

                    return true;
                });
                if (!$free) {
                    return ['success' => false, 'error' => 'slug_taken'];
                }
            }
        } catch (\PDOException $e) {
            if (self::isDuplicateKey($e)) {
                return ['success' => false, 'error' => 'slug_taken'];
            }
            throw $e;
        } finally {
            $this->forgetTree();
        }

        return ['success' => true];
    }

    /**
     * Zeile mit Sperre lesen (nur in einer Transaktion sinnvoll). Serialisiert alle Schreiber desselben Forums.
     *
     * @return array<string, mixed>|null
     */
    public function lockForUpdate(int $id): ?array
    {
        return $this->fetchOne('SELECT * FROM ' . $this->table(self::TABLE) . ' WHERE id = ? LIMIT 1' . self::forUpdate(), [$id]);
    }

    /**
     * Zähler atomar um Beträge ändern (positiv oder negativ, nie unter 0), `version` erhöhen, `updated_at` setzen.
     * `$last`: `null` lässt die Felder „letzter Beitrag“ unverändert; ein Array setzt alle vier (auch auf `null`, etwa nach
     * dem Löschen des letzten Beitrags). Liefert `true`, wenn das Forum existiert.
     *
     * @param array{post_id: int|null, post_at: string|null, topic_id: int|null, poster_id: int|null}|null $last
     */
    public function applyCounters(int $id, int $topics, int $posts, string $nowUtc, ?array $last = null): bool
    {
        $set = [self::counter('topic_count'), self::counter('post_count')];
        $params = [...self::counterParams($topics), ...self::counterParams($posts)];
        if ($last !== null) {
            $set[] = 'last_post_id = ?, last_post_at = ?, last_topic_id = ?, last_poster_id = ?';
            array_push($params, $last['post_id'] ?? null, $last['post_at'] ?? null, $last['topic_id'] ?? null, $last['poster_id'] ?? null);
        }
        $params[] = $nowUtc;
        $params[] = $id;

        return $this->run(
            'UPDATE ' . $this->table(self::TABLE) . ' SET ' . implode(', ', $set) . ', version = version + 1, updated_at = ? WHERE id = ?',
            $params
        ) === 1;
    }

    /** Neuer Beitrag: Zähler erhöhen und „letzter Beitrag“ setzen (eine Anweisung). */
    public function bumpCounters(int $id, int $topics, int $posts, string $lastPostAt, int $lastPostId, int $lastTopicId, int $lastPosterId, string $nowUtc): bool
    {
        return $this->applyCounters($id, $topics, $posts, $nowUtc, [
            'post_id' => $lastPostId,
            'post_at' => $lastPostAt,
            'topic_id' => $lastTopicId,
            'poster_id' => $lastPosterId,
        ]);
    }

    /**
     * Rechte-Zeilen für die Subjekte eines Mitglieds über die Vererbungskette. Je Subjekt (Typ + Schlüssel) kommt die Zeile
     * des ersten Forums der Kette zurück (eigenes Forum vor dem Elternforum; eine eigene Zeile ersetzt die geerbte).
     * Subjekte: immer `guest`; `role` mit `$role` (leer = keine Rollenzeilen); `group` mit `$groupIds`; `trust`-Zeilen mit
     * Schlüssel kleiner oder gleich `$level` (negativ = keine Stufenzeilen, für Gäste). Ob die Kette überhaupt Zeilen
     * hat, sagt `hasPermissionRows()`; die Entscheidung trifft `CommunityAccess`.
     *
     * @param list<int> $chain Ergebnis von `chainIds()` (das Forum zuerst)
     * @param list<int> $groupIds
     * @return list<array{forum_id: int, subject_type: string, subject_key: string, permissions_json: string|null}>
     */
    public function permissionRows(array $chain, string $role, array $groupIds, int $level): array
    {
        $chain = self::ids($chain);
        if ($chain === []) {
            return [];
        }
        $where = ["subject_type = 'guest'"];
        $params = $chain;
        if ($level >= 0) {
            $where[] = "subject_type = 'trust'";
        }
        if ($role !== '') {
            $where[] = "(subject_type = 'role' AND subject_key = ?)";
            $params[] = $role;
        }
        $groupKeys = array_map('strval', self::ids($groupIds));
        if ($groupKeys !== []) {
            $where[] = "(subject_type = 'group' AND subject_key IN (" . self::placeholders(count($groupKeys)) . '))';
            array_push($params, ...$groupKeys);
        }
        $rows = $this->fetchAll(
            'SELECT forum_id, subject_type, subject_key, permissions_json FROM ' . $this->table(self::PERMISSIONS)
            . ' WHERE forum_id IN (' . self::placeholders(count($chain)) . ') AND (' . implode(' OR ', $where) . ') ORDER BY subject_type ASC, subject_key ASC',
            $params
        );

        $rank = array_flip($chain);
        $best = [];
        foreach ($rows as $row) {
            $type = (string) $row['subject_type'];
            $key = (string) $row['subject_key'];
            if ($type === 'trust' && (preg_match('/^\d+$/', $key) !== 1 || (int) $key > $level)) {
                continue;
            }
            $slot = $type . "\0" . $key;
            $forumId = (int) $row['forum_id'];
            if (!isset($best[$slot]) || $rank[$forumId] < $rank[(int) $best[$slot]['forum_id']]) {
                $best[$slot] = [
                    'forum_id' => $forumId,
                    'subject_type' => $type,
                    'subject_key' => $key,
                    'permissions_json' => $row['permissions_json'] === null ? null : (string) $row['permissions_json'],
                ];
            }
        }

        return array_values($best);
    }

    /**
     * Hat mindestens ein Forum der Kette Rechte-Zeilen (gleich für welches Subjekt)? Ohne Zeilen gelten die Standard-Rechte.
     *
     * @param list<int> $chain
     */
    public function hasPermissionRows(array $chain): bool
    {
        $chain = self::ids($chain);

        return $chain !== [] && $this->fetchOne(
            'SELECT id FROM ' . $this->table(self::PERMISSIONS) . ' WHERE forum_id IN (' . self::placeholders(count($chain)) . ') LIMIT 1',
            $chain
        ) !== null;
    }

    /**
     * `permissions_json` als Matrix: nur bekannte Schlüssel, Werte 0/1; NULL, ungültiges JSON und `{}` ergeben eine leere Matrix.
     *
     * @return array<string, int>
     */
    public static function decodePermissions(?string $json): array
    {
        $decoded = $json === null || $json === '' ? null : json_decode($json, true);
        if (!is_array($decoded)) {
            return [];
        }
        $matrix = [];
        foreach (self::PERMISSION_KEYS as $key) {
            if (array_key_exists($key, $decoded)) {
                $matrix[$key] = !empty($decoded[$key]) ? 1 : 0;
            }
        }

        return $matrix;
    }

    /**
     * Moderator-Zeilen, die für ein Forum gelten: Einträge auf dem Forum selbst und auf Vorfahren mit `include_children = 1`,
     * für das Mitglied (`user`) oder eine seiner Gruppen (`group`). Leere Liste = kein Moderator.
     *
     * @param list<int> $ancestorIds Ergebnis von `ancestorIds($forumId)` (Forum und alle Vorfahren)
     * @param list<int> $groupIds
     * @return list<array{forum_id: int, subject_type: string, subject_id: int, include_children: int}>
     */
    public function moderatorRows(array $ancestorIds, int $userId, array $groupIds, int $forumId): array
    {
        $ancestorIds = self::ids($ancestorIds);
        $groupIds = self::ids($groupIds);
        $subjects = [];
        $params = [...$ancestorIds, $forumId];
        if ($userId > 0) {
            $subjects[] = "(subject_type = 'user' AND subject_id = ?)";
            $params[] = $userId;
        }
        if ($groupIds !== []) {
            $subjects[] = "(subject_type = 'group' AND subject_id IN (" . self::placeholders(count($groupIds)) . '))';
            array_push($params, ...$groupIds);
        }
        if ($ancestorIds === [] || $subjects === []) {
            return [];
        }
        $rows = $this->fetchAll(
            'SELECT forum_id, subject_type, subject_id, include_children FROM ' . $this->table(self::MODERATORS)
            . ' WHERE forum_id IN (' . self::placeholders(count($ancestorIds)) . ') AND (forum_id = ? OR include_children = 1) AND ('
            . implode(' OR ', $subjects) . ') ORDER BY forum_id ASC, subject_type ASC, subject_id ASC',
            $params
        );

        return array_map(static fn (array $row): array => [
            'forum_id' => (int) $row['forum_id'],
            'subject_type' => (string) $row['subject_type'],
            'subject_id' => (int) $row['subject_id'],
            'include_children' => (int) $row['include_children'],
        ], $rows);
    }

    /**
     * Ersetzt alle Rechte-Zeilen eines Forums (Verwaltung und Testdaten). Zeilenform:
     * `['subject_type' => 'role', 'subject_key' => 'member', 'permissions' => ['read' => 1, 'reply' => 0]]`;
     * `permissions` NULL speichert NULL (keine Rechte). `guest` hat den Schlüssel '' (auch bei Weglassen), `trust` 0 bis 4,
     * `group` eine Gruppen-ID. Ungültige Eingaben werfen `InvalidArgumentException`, bevor etwas geschrieben wird.
     *
     * @param list<array{subject_type: string, subject_key?: string|int, permissions?: array<string, mixed>|null}> $rows
     */
    public function savePermissions(int $forumId, array $rows, string $nowUtc): void
    {
        $prepared = [];
        foreach ($rows as $row) {
            $type = (string) ($row['subject_type'] ?? '');
            $key = (string) ($row['subject_key'] ?? '');
            $valid = match ($type) {
                'guest' => $key === '',
                'role' => preg_match('/^[a-z0-9_-]{1,50}$/', $key) === 1,
                'group' => preg_match('/^[1-9]\d{0,9}$/', $key) === 1,
                'trust' => preg_match('/^[0-4]$/', $key) === 1,
                default => false,
            };
            if (!$valid) {
                throw new \InvalidArgumentException('Ungültiges Rechte-Subjekt: ' . $type . ':' . $key);
            }
            if (isset($prepared[$type . "\0" . $key])) {
                throw new \InvalidArgumentException('Doppeltes Rechte-Subjekt: ' . $type . ':' . $key);
            }
            $json = null;
            if (($row['permissions'] ?? null) !== null) {
                $unknown = array_diff(array_map('strval', array_keys((array) $row['permissions'])), self::PERMISSION_KEYS);
                if ($unknown !== []) {
                    throw new \InvalidArgumentException('Unbekannter Rechte-Schlüssel: ' . implode(', ', $unknown));
                }
                $matrix = [];
                foreach (self::PERMISSION_KEYS as $permission) {
                    if (array_key_exists($permission, (array) $row['permissions'])) {
                        $matrix[$permission] = !empty($row['permissions'][$permission]) ? 1 : 0;
                    }
                }
                $json = json_encode((object) $matrix, JSON_THROW_ON_ERROR);
            }
            $prepared[$type . "\0" . $key] = [$forumId, $type, $key, $json, $nowUtc];
        }

        Transaction::run($this->db, function () use ($forumId, $prepared): void {
            $table = $this->table(self::PERMISSIONS);
            $this->run("DELETE FROM {$table} WHERE forum_id = ?", [$forumId]);
            foreach ($prepared as $params) {
                $this->run("INSERT INTO {$table} (forum_id, subject_type, subject_key, permissions_json, updated_at) VALUES (?, ?, ?, ?, ?)", $params);
            }
        });
    }

    /**
     * Ersetzt alle Moderatoren eines Forums. Zeilenform: `['subject_type' => 'user'|'group', 'subject_id' => 7, 'include_children' => 1]`
     * (`include_children` Standard 1). Ungültige Eingaben werfen `InvalidArgumentException`, bevor etwas geschrieben wird.
     *
     * @param list<array{subject_type: string, subject_id: int, include_children?: int|bool}> $rows
     */
    public function saveModerators(int $forumId, array $rows): void
    {
        $prepared = [];
        foreach ($rows as $row) {
            $type = (string) ($row['subject_type'] ?? '');
            $subjectId = (int) ($row['subject_id'] ?? 0);
            if (!in_array($type, self::MODERATOR_SUBJECTS, true) || $subjectId < 1) {
                throw new \InvalidArgumentException('Ungültiger Moderator: ' . $type . ':' . $subjectId);
            }
            if (isset($prepared[$type . ':' . $subjectId])) {
                throw new \InvalidArgumentException('Doppelter Moderator: ' . $type . ':' . $subjectId);
            }
            $prepared[$type . ':' . $subjectId] = [$forumId, $type, $subjectId, !empty($row['include_children'] ?? 1) ? 1 : 0];
        }

        Transaction::run($this->db, function () use ($forumId, $prepared): void {
            $table = $this->table(self::MODERATORS);
            $this->run("DELETE FROM {$table} WHERE forum_id = ?", [$forumId]);
            foreach ($prepared as $params) {
                $this->run("INSERT INTO {$table} (forum_id, subject_type, subject_id, include_children) VALUES (?, ?, ?, ?)", $params);
            }
        });
    }

    /**
     * Pfad `/3/17/` als Liste, das Forum zuerst. Passt der Pfad nicht zur ID (leer oder beschädigt), gilt nur das Forum selbst.
     *
     * @param array<string, mixed> $row
     * @return list<int>
     */
    private static function pathIds(array $row, int $forumId): array
    {
        $ids = [];
        foreach (explode('/', trim((string) ($row['path'] ?? ''), '/')) as $segment) {
            if (preg_match('/^[1-9]\d*$/', $segment) === 1) {
                $ids[] = (int) $segment;
            }
        }
        if ($ids === [] || $ids[count($ids) - 1] !== $forumId) {
            return [$forumId];
        }

        return array_reverse($ids);
    }

    /**
     * Prüft Eingaben von `create()`/`update()`; liefert einen Fehlercode oder null.
     *
     * @param array<string, mixed> $data
     */
    private function invalid(array $data, bool $creating): ?string
    {
        // Typen und Spaltengrenzen zuerst: die Prüfungen danach rechnen mit Texten und Zahlen.
        foreach (['slug', 'slug_en'] as $column) {
            if (isset($data[$column]) && !is_string($data[$column])) {
                return 'invalid_input';
            }
        }
        if (self::exceeds($data, self::LIMITS)) {
            return 'invalid_input';
        }
        if ($creating && (trim((string) ($data['title'] ?? '')) === '' || !self::validSlug((string) ($data['slug'] ?? '')))) {
            return 'invalid_input';
        }
        if (!$creating && ((array_key_exists('title', $data) && trim((string) $data['title']) === '')
            || (array_key_exists('slug', $data) && !self::validSlug((string) $data['slug'])))) {
            return 'invalid_input';
        }
        $parentId = $data['parent_id'] ?? null;
        if ($creating && $parentId !== null && $parentId !== '' && self::exceeds(['parent_id' => $parentId], ['parent_id' => ['uint']])) {
            return 'invalid_input';
        }
        $slugEn = $data['slug_en'] ?? null;
        if ($slugEn !== null && $slugEn !== '' && !self::validSlug((string) $slugEn)) {
            return 'invalid_input';
        }
        if (isset($data['status']) && !in_array($data['status'], self::STATUSES, true)) {
            return 'invalid_input';
        }
        if (isset($data['type']) && !in_array($data['type'], self::TYPES, true)) {
            return 'invalid_input';
        }
        // Reservierte Pfadsegmente (neu, thema, seite-2, en, …) würden feste Adressen unterhalb des Community-Pfads verdecken.
        foreach (['slug', 'slug_en'] as $column) {
            if (isset($data[$column]) && is_string($data[$column]) && $data[$column] !== '' && Slugger::isReserved($data[$column])) {
                return 'slug_taken';
            }
        }

        return null;
    }

    /**
     * Leeren englischen Slug zu NULL (der UNIQUE-Index erlaubt viele NULL, aber nur einmal ''), Schalter zu 0/1.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function normalize(array $data): array
    {
        if (array_key_exists('slug_en', $data) && $data['slug_en'] === '') {
            $data['slug_en'] = null;
        }
        foreach (self::FLAGS as $flag) {
            if (array_key_exists($flag, $data)) {
                $data[$flag] = !empty($data[$flag]) ? 1 : 0;
            }
        }

        return $data;
    }

    /** Legt die Mutex-Zeile der Slug-Prüfung an (Autocommit, vor der Transaktion; ohne Wirkung, wenn sie existiert). */
    private function ensureSlugLock(): void
    {
        $this->run(
            'INSERT IGNORE INTO ' . $this->table(self::SETTINGS) . ' (option_name, option_value, autoload) VALUES (?, ?, ?)',
            [self::SLUG_LOCK, '0', 0]
        );
    }

    /**
     * Sperrt die Mutex-Zeile der Slug-Prüfung: als ERSTE Anweisung der Transaktion, vor jeder nicht sperrenden Abfrage. Fehlt die
     * Zeile (Ausnahmefall: gelöscht nach `ensureSlugLock()`), legt die Methode sie an und sperrt erneut.
     */
    private function lockSlugs(): void
    {
        $sql = 'SELECT option_value FROM ' . $this->table(self::SETTINGS) . ' WHERE option_name = ? LIMIT 1' . self::forUpdate();
        if ($this->fetchOne($sql, [self::SLUG_LOCK]) === null) {
            $this->ensureSlugLock();
            if ($this->fetchOne($sql, [self::SLUG_LOCK]) === null) {
                throw new \RuntimeException('Mutex-Zeile der Forum-Slugs konnte nicht angelegt werden.');
            }
        }
    }

    /** Ist einer der Slugs schon in `slug` oder `slug_en` eines anderen Forums vergeben? */
    private function slugTaken(string $slug, mixed $slugEn, ?int $exceptId): bool
    {
        $slugs = [$slug];
        if ($slugEn !== null && $slugEn !== '') {
            $slugs[] = (string) $slugEn;
        }

        return $this->slugConflict($slugs, $exceptId);
    }

    /** @param list<string> $slugs */
    private function slugConflict(array $slugs, ?int $exceptId): bool
    {
        $slugs = array_values(array_unique($slugs));
        $marks = self::placeholders(count($slugs));
        $sql = 'SELECT id FROM ' . $this->table(self::TABLE) . " WHERE (slug IN ({$marks}) OR slug_en IN ({$marks}))";
        $params = [...$slugs, ...$slugs];
        if ($exceptId !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $exceptId;
        }

        return $this->fetchOne($sql . ' LIMIT 1', $params) !== null;
    }
}
