<?php
/**
 * Zähler, Vertrauensstufe, Einstellungen und Ränge je Mitglied (`community_user_stats`, `community_ranks`). Die Zeile eines
 * Mitglieds kann fehlen (bis zum ersten Beitrag): `get()` liefert dann `null`, Vertrauensstufe und Zähler gelten als 0.
 *
 * Schreiben: `ensure()`/`ensureMany()` legen Zeilen an, `lockForUpdate()`/`lockMany()`/`settingsForUpdate()` sperren sie (Ebene 4 der
 * Sperrfolge, nach Forum, Thema, Beitrag), `bump()` ändert Zähler atomar (nie unter 0), `touchLastPost()` setzt den Zeitpunkt des
 * letzten Beitrags (auch bei wartenden Beiträgen).
 *
 * Regeln der Dienste (PR #2a, Befunde B2 und B3 der Integrationssuite; Vertrag DESIGN-2b §3.10, §3.11):
 * - Zeilen entstehen VOR der Transaktion im Autocommit (`ensure()`, `ensureMany()`). Dann trifft die Sperre in der Transaktion eine
 *   vorhandene Zeile (reine Zeilensperre). Eine Sperre auf eine fehlende Zeile ist unter REPEATABLE READ eine Lückensperre, und zwei
 *   Transaktionen mit solchen Lückensperren blockieren einander, sobald beide anschließend einfügen (Deadlock).
 * - Die Mitgliedersperre kommt vor der ersten nicht sperrenden Leseanweisung der Transaktion (sonst sieht die Rechteprüfung
 *   unter der Sperre einen veralteten Schnappschuss; siehe `Support\Transaction`). Die Sperrmethoden lesen deshalb nie ohne Sperre.
 * - Mehrere Mitglieder sperrt nur `lockMany()`: aufsteigend nach `user_id`, in Stücken zu höchstens CHUNK IDs.
 * - Unter Sperren bucht nur `bumpLocked()` bzw. `bumpReactions()` (kein `INSERT`); `bump()` legt die Zeile vorher an und gehört vor
 *   die Transaktion oder hinter eine Sperre derselben Zeile.
 * - `settings_json` kodiert und dekodiert PHP (`JSON_*`-Funktionen der Datenbank sind verboten). Fremde Schlüssel bleiben erhalten,
 *   weil `saveSettings()` die ganze Zuordnung schreibt, die der Dienst unter `settingsForUpdate()` zusammengeführt hat.
 * - Ränge (`community_ranks`) liegen hier, weil sie nur `post_count` und `rank_id` der Statistik deuten (interaktion E16). Die Marke
 *   der Grundausstattung ist eine Zeile in `settings` (RANK_SEED_MARKER, `INSERT IGNORE` auf UNIQUE `option_name`).
 *
 * @package CMSv2\Core\Services\Community
 */

declare(strict_types=1);

namespace CMS\Services\Community\Repository;

if (!defined('ABSPATH')) {
    exit;
}

final class UserStatsRepository extends AbstractRepository
{
    /** Name der Marke in `settings`: Grundausstattung der Ränge beansprucht (ohne Punkt, also nicht Gruppe `community`). */
    public const RANK_SEED_MARKER = 'community_ranks_seeded';
    /** Größte Länge von `settings_json` in Byte. */
    public const MAX_SETTINGS_BYTES = 65535;
    /** Höchstens so viele Ränge liefert `ranks()`. */
    public const MAX_RANKS = 100;

    private const TABLE = 'community_user_stats';
    private const RANKS = 'community_ranks';
    private const SETTINGS = 'settings';

    /** IDs je Anweisung in Mengenabfragen und Sperren. */
    private const CHUNK = 100;

    /** Spalten, die `get()` liefert (ohne Signatur und Einstellungen). */
    private const COLUMNS = 'user_id, topic_count, post_count, reactions_received, reactions_given, solutions_count, reputation,'
        . ' trust_level, trust_level_locked, topics_read, days_visited, rank_id, custom_title, last_post_at, last_seen_at, updated_at';

    /** Spalten eines Rangs in Lesereihenfolge. */
    private const RANK_COLUMNS = 'id, title, title_en, min_posts, color, icon, sort_order';

    /** Schreibbare Spalten eines Rangs und ihre Grenzen (`min_posts` prüft `rankValues()` gesondert: NULL oder INT UNSIGNED). */
    private const RANK_LIMITS = [
        'title' => ['chars', 80, false],
        'title_en' => ['chars', 80, true],
        'color' => ['chars', 9, true],
        'icon' => ['chars', 50, true],
        'sort_order' => ['uint'],
    ];

    /**
     * Zeile des Mitglieds oder `null`.
     *
     * @return array<string, mixed>|null
     */
    public function get(int $userId): ?array
    {
        return $this->fetchOne('SELECT ' . self::COLUMNS . ' FROM ' . $this->table(self::TABLE) . ' WHERE user_id = ? LIMIT 1', [$userId]);
    }

    /**
     * Legt die Zeile an, falls sie fehlt (ohne Wirkung, wenn sie existiert). Die Dienste rufen das VOR `Transaction::run()` im
     * Autocommit auf (kein Lückensperrkonflikt, keine offene Transaktion); innerhalb einer Transaktion nur nach der Sperre.
     */
    public function ensure(int $userId, string $nowUtc): void
    {
        $this->run('INSERT IGNORE INTO ' . $this->table(self::TABLE) . ' (user_id, updated_at) VALUES (?, ?)', [$userId, $nowUtc]);
    }

    /**
     * Legt fehlende Zeilen mehrerer Mitglieder an (vorhandene bleiben unverändert): IDs eindeutig und aufsteigend, je CHUNK Zeilen ein
     * mehrzeiliges `INSERT IGNORE`. Aufruf im Autocommit vor der Transaktion, die die Zeilen mit `lockMany()` sperrt.
     *
     * @param array<array-key, mixed> $userIds
     */
    public function ensureMany(array $userIds, string $nowUtc): void
    {
        foreach (self::batches($userIds) as $batch) {
            $params = [];
            foreach ($batch as $userId) {
                array_push($params, $userId, $nowUtc);
            }
            $this->run(
                'INSERT IGNORE INTO ' . $this->table(self::TABLE) . ' (user_id, updated_at) VALUES ' . implode(', ', array_fill(0, count($batch), '(?, ?)')),
                $params
            );
        }
    }

    /**
     * Zeile mit Sperre lesen (nur in einer Transaktion sinnvoll). Normalfall: die Zeile existiert, weil der Dienst `ensure()` vor
     * der Transaktion aufgerufen hat; die Sperre ist dann eine reine Zeilensperre ohne Schreibzugriff (kein Konflikt zwischen
     * parallelen Aufrufern desselben Mitglieds). Nur als Absicherung für den Ausnahmefall einer fehlenden Zeile (etwa gelöscht
     * zwischen `ensure()` und Sperre) legt die Methode sie an und sperrt erneut; ein dabei möglicher Sperrkonflikt wird von
     * `Transaction::run()` wiederholt. Die Methode liest nie ohne Sperre: sie darf als erste Anweisung nach den Forum- und
     * Themensperren stehen, ohne den Schnappschuss der Transaktion anzulegen.
     *
     * @return array<string, mixed>
     */
    public function lockForUpdate(int $userId, string $nowUtc): array
    {
        $sql = 'SELECT ' . self::COLUMNS . ' FROM ' . $this->table(self::TABLE) . ' WHERE user_id = ? LIMIT 1' . self::forUpdate();
        $row = $this->fetchOne($sql, [$userId]);
        if ($row === null) {
            // Ausnahmefall: die Zeile fehlt (Normalfall: ensure() lief vor der Transaktion).
            $this->ensure($userId, $nowUtc);
            $row = $this->fetchOne($sql, [$userId]);
        }
        if ($row === null) {
            throw new \RuntimeException('community_user_stats: Zeile konnte nicht angelegt werden.');
        }

        return $row;
    }

    /**
     * Sperrt die Zeilen mehrerer Mitglieder (Ebene 4): IDs eindeutig und aufsteigend, je Stück von höchstens CHUNK IDs eine Anweisung
     * `IN (…) ORDER BY user_id` mit Zeilensperre. Gleiche Mengen in derselben Reihenfolge schließen Deadlocks zwischen den Diensten aus
     * (moderation §2.0, Probe `lockorder.php`). Fehlt eine Zeile (Ausnahmefall, Normalfall: `ensureMany()` vor der Transaktion), legt die
     * Methode sie an und sperrt das Stück erneut, wie `lockForUpdate()`. Liest nie ohne Sperre.
     *
     * @param array<array-key, mixed> $userIds
     */
    public function lockMany(array $userIds, string $nowUtc): void
    {
        $ids = self::ids($userIds);
        sort($ids);
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $sql = 'SELECT user_id FROM ' . $this->table(self::TABLE) . ' WHERE user_id IN (' . self::placeholders(count($chunk)) . ')'
                . ' ORDER BY user_id ASC' . self::forUpdate();
            $missing = array_values(array_diff($chunk, $this->userIds($sql, $chunk)));
            if ($missing === []) {
                continue;
            }
            // Ausnahmefall: Zeilen fehlen. Anlegen und das Stück erneut sperren (die vorhandenen Sperren bleiben gehalten).
            $this->ensureMany($missing, $nowUtc);
            if (array_diff($chunk, $this->userIds($sql, $chunk)) !== []) {
                throw new \RuntimeException('community_user_stats: Zeilen konnten nicht angelegt werden.');
            }
        }
    }

    /**
     * Themen-, Beitrags- und Lösungszähler atomar ändern (positiv oder negativ, nie unter 0). Legt die Zeile bei Bedarf an; in einer
     * Transaktion vorher `lockForUpdate()` aufrufen. Unter Sperren mehrerer Zeilen gilt `bumpLocked()`.
     */
    public function bump(int $userId, int $topics, int $posts, string $nowUtc, int $solutions = 0): void
    {
        $this->ensure($userId, $nowUtc);
        $this->bumpLocked($userId, $topics, $posts, $nowUtc, $solutions);
    }

    /**
     * Wie `bump()`, aber ohne `ensure()`: eine einzige `UPDATE`-Anweisung über PRIMARY, nie ein `INSERT` (keine Lückensperre unter
     * Sperren). Nur nach `lockForUpdate()`/`lockMany()` derselben Zeile; eine fehlende Zeile bleibt unverändert fehlend.
     */
    public function bumpLocked(int $userId, int $topics, int $posts, string $nowUtc, int $solutions = 0): void
    {
        $this->run(
            'UPDATE ' . $this->table(self::TABLE) . ' SET ' . self::counter('topic_count') . ', ' . self::counter('post_count')
            . ', ' . self::counter('solutions_count') . ', updated_at = ? WHERE user_id = ?',
            [...self::counterParams($topics), ...self::counterParams($posts), ...self::counterParams($solutions), $nowUtc, $userId]
        );
    }

    /** Zeitpunkt des letzten Beitrags setzen (auch für wartende Beiträge); legt die Zeile bei Bedarf an. */
    public function touchLastPost(int $userId, string $nowUtc): void
    {
        $this->ensure($userId, $nowUtc);
        $this->run('UPDATE ' . $this->table(self::TABLE) . ' SET last_post_at = ?, updated_at = ? WHERE user_id = ?', [$nowUtc, $nowUtc, $userId]);
    }

    /**
     * Erhaltene und gegebene Reaktionen atomar ändern (nie unter 0), ohne `ensure()`: die Zeilen hat `lockMany()` gesperrt.
     */
    public function bumpReactions(int $userId, int $received, int $given, string $nowUtc): void
    {
        $this->run(
            'UPDATE ' . $this->table(self::TABLE) . ' SET ' . self::counter('reactions_received') . ', ' . self::counter('reactions_given')
            . ', updated_at = ? WHERE user_id = ?',
            [...self::counterParams($received), ...self::counterParams($given), $nowUtc, $userId]
        );
    }

    /** Manueller Rang (`null` = automatisch aus `post_count`). Die Zeile legt der Dienst vorher mit `ensure()` an. */
    public function setRank(int $userId, ?int $rankId, string $nowUtc): void
    {
        if ($rankId !== null && ($rankId < 1 || $rankId > 4294967295)) {
            throw new \InvalidArgumentException('Ungültige Rang-ID.');
        }
        $this->run('UPDATE ' . $this->table(self::TABLE) . ' SET rank_id = ?, updated_at = ? WHERE user_id = ?', [$rankId, $nowUtc, $userId]);
    }

    /**
     * `post_count` und `rank_id` mehrerer Mitglieder (Ränge zur Anzeigezeit). Mitglieder ohne Zeile fehlen; aufsteigend.
     *
     * @param array<array-key, mixed> $userIds
     * @return array<int, array{post_count: int, rank_id: int|null}>
     */
    public function statsMany(array $userIds): array
    {
        $map = [];
        foreach (self::batches($userIds) as $batch) {
            $rows = $this->fetchAll(
                'SELECT user_id, post_count, rank_id FROM ' . $this->table(self::TABLE) . ' WHERE user_id IN (' . self::placeholders(count($batch)) . ') ORDER BY user_id ASC',
                $batch
            );
            foreach ($rows as $row) {
                $map[(int) $row['user_id']] = ['post_count' => (int) $row['post_count'], 'rank_id' => $row['rank_id'] === null ? null : (int) $row['rank_id']];
            }
        }
        ksort($map);

        return $map;
    }

    /**
     * Einstellungen und letzter Besuch mehrerer Mitglieder (Empfänger eines Stapels). Mitglieder ohne Zeile fehlen; NULL, beschädigtes
     * JSON oder eine Liste statt einer Zuordnung ergeben `[]`. Aufsteigend.
     *
     * @param array<array-key, mixed> $userIds
     * @return array<int, array{settings: array<string, mixed>, last_seen_at: string|null}>
     */
    public function settingsMany(array $userIds): array
    {
        $map = [];
        foreach (self::batches($userIds) as $batch) {
            $rows = $this->fetchAll(
                'SELECT user_id, settings_json, last_seen_at FROM ' . $this->table(self::TABLE) . ' WHERE user_id IN (' . self::placeholders(count($batch)) . ') ORDER BY user_id ASC',
                $batch
            );
            foreach ($rows as $row) {
                $map[(int) $row['user_id']] = ['settings' => self::decodeSettings($row['settings_json']), 'last_seen_at' => $row['last_seen_at'] === null ? null : (string) $row['last_seen_at']];
            }
        }
        ksort($map);

        return $map;
    }

    /**
     * Einstellungen eines Mitglieds mit Zeilensperre (Mutex je Empfänger, Ebene 4; nur in einer Transaktion sinnvoll). Die Zeile muss
     * existieren (`ensure()`/`ensureMany()` vor der Transaktion): eine fehlende Zeile ergibt eine `RuntimeException` statt einer Sperre
     * auf eine Lücke. Liest nie ohne Sperre.
     *
     * @return array<string, mixed>
     */
    public function settingsForUpdate(int $userId): array
    {
        $row = $this->fetchOne('SELECT settings_json FROM ' . $this->table(self::TABLE) . ' WHERE user_id = ? LIMIT 1' . self::forUpdate(), [$userId]);
        if ($row === null) {
            throw new \RuntimeException('community_user_stats: Zeile für die Einstellungen fehlt (ensure() vor der Transaktion).');
        }

        return self::decodeSettings($row['settings_json']);
    }

    /**
     * Schreibt die Einstellungen eines Mitglieds (die ganze Zuordnung; leer = NULL). Keine Zuordnung, nicht kodierbares JSON oder mehr
     * als MAX_SETTINGS_BYTES: `InvalidArgumentException`, nichts geschrieben.
     *
     * @param array<string, mixed> $settings
     */
    public function saveSettings(int $userId, array $settings, string $nowUtc): void
    {
        $json = null;
        if ($settings !== []) {
            if (array_is_list($settings)) {
                throw new \InvalidArgumentException('Einstellungen sind keine Zuordnung von Schlüsseln.');
            }
            try {
                $json = json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                throw new \InvalidArgumentException('Einstellungen lassen sich nicht als JSON kodieren: ' . $e->getMessage(), 0, $e);
            }
            if (strlen($json) > self::MAX_SETTINGS_BYTES) {
                throw new \InvalidArgumentException('Einstellungen größer als ' . self::MAX_SETTINGS_BYTES . ' Byte.');
            }
        }
        $this->run('UPDATE ' . $this->table(self::TABLE) . ' SET settings_json = ?, updated_at = ? WHERE user_id = ?', [$json, $nowUtc, $userId]);
    }

    /**
     * Setzt `last_seen_at` (Tor der Forum-Abos), aber nur, wenn der bisherige Wert fehlt oder älter als `$staleBeforeUtc` ist: höchstens
     * ein Schreibzugriff je Fenster. Ohne Zeile geschieht nichts. Liefert `true`, wenn geschrieben wurde.
     */
    public function touchSeen(int $userId, string $nowUtc, string $staleBeforeUtc): bool
    {
        return $this->run(
            'UPDATE ' . $this->table(self::TABLE) . ' SET last_seen_at = ? WHERE user_id = ? AND (last_seen_at IS NULL OR last_seen_at < ?)',
            [$nowUtc, $userId, $staleBeforeUtc]
        ) > 0;
    }

    /**
     * Alle Ränge, `sort_order` aufsteigend, bei Gleichstand nach ID; höchstens MAX_RANKS.
     *
     * @return list<array{id: int, title: string, title_en: string|null, min_posts: int|null, color: string|null, icon: string|null, sort_order: int}>
     */
    public function ranks(): array
    {
        $rows = $this->fetchAll('SELECT ' . self::RANK_COLUMNS . ' FROM ' . $this->table(self::RANKS) . ' ORDER BY sort_order ASC, id ASC LIMIT ?', [self::MAX_RANKS]);

        return array_map(self::rankRow(...), $rows);
    }

    /**
     * Ein Rang oder `null`.
     *
     * @return array{id: int, title: string, title_en: string|null, min_posts: int|null, color: string|null, icon: string|null, sort_order: int}|null
     */
    public function rank(int $rankId): ?array
    {
        $row = $this->fetchOne('SELECT ' . self::RANK_COLUMNS . ' FROM ' . $this->table(self::RANKS) . ' WHERE id = ? LIMIT 1', [$rankId]);

        return $row === null ? null : self::rankRow($row);
    }

    /**
     * Legt einen Rang an (`title` Pflicht) und liefert die ID. Unbekannte Spalte oder Wert außerhalb der Spalte: `InvalidArgumentException`.
     *
     * @param array<string, mixed> $data
     */
    public function insertRank(array $data): int
    {
        if (!array_key_exists('title', $data)) {
            throw new \InvalidArgumentException('Rang ohne Titel.');
        }

        return $this->insertRow(self::RANKS, self::rankValues($data), [...array_keys(self::RANK_LIMITS), 'min_posts']);
    }

    /**
     * Ändert die genannten Spalten eines Rangs. Liefert `true`, wenn der Rang existiert (auch ohne geänderten Wert), sonst `false`.
     *
     * @param array<string, mixed> $data
     */
    public function updateRank(int $rankId, array $data): bool
    {
        $values = self::rankValues($data);
        if ($values === []) {
            throw new \InvalidArgumentException('Keine Spalten zum Ändern.');
        }
        $assign = implode(', ', array_map(static fn (string $column): string => $column . ' = ?', array_keys($values)));
        if ($this->run('UPDATE ' . $this->table(self::RANKS) . ' SET ' . $assign . ' WHERE id = ?', [...array_values($values), $rankId]) > 0) {
            return true;
        }

        // MariaDB zählt nur geänderte Zeilen: ohne geänderten Wert entscheidet das Vorhandensein.
        return $this->fetchValue('SELECT id FROM ' . $this->table(self::RANKS) . ' WHERE id = ? LIMIT 1', [$rankId]) !== null;
    }

    /** Löscht einen Rang; verwaiste `rank_id` gelten als automatisch. `true` = gelöscht. */
    public function deleteRank(int $rankId): bool
    {
        return $this->run('DELETE FROM ' . $this->table(self::RANKS) . ' WHERE id = ?', [$rankId]) > 0;
    }

    /** Zahl der Ränge. */
    public function countRanks(): int
    {
        return (int) $this->fetchValue('SELECT COUNT(*) FROM ' . $this->table(self::RANKS));
    }

    /**
     * Beansprucht die Grundausstattung der Ränge: legt die Marke RANK_SEED_MARKER in `settings` an (`INSERT IGNORE`, UNIQUE
     * `option_name`, Wert = Zeitpunkt, ohne autoload). `true` nur für den ersten Aufruf; danach bleibt die Marke unverändert.
     * Läuft in der eigenen Transaktion des Säens (Rücknahme nimmt die Marke mit).
     */
    public function claimRankSeed(string $nowUtc): bool
    {
        return $this->run(
            'INSERT IGNORE INTO ' . $this->table(self::SETTINGS) . ' (option_name, option_value, autoload) VALUES (?, ?, 0)',
            [self::RANK_SEED_MARKER, $nowUtc]
        ) > 0;
    }

    /**
     * IDs eindeutig, aufsteigend, in Stücken zu höchstens CHUNK (Mengenabfragen ohne Sperre).
     *
     * @param array<array-key, mixed> $userIds
     * @return list<list<int>>
     */
    private static function batches(array $userIds): array
    {
        $ids = self::ids($userIds);
        sort($ids, SORT_NUMERIC);

        return array_chunk($ids, self::CHUNK);
    }

    /**
     * Gesperrte `user_id` einer Sperranweisung.
     *
     * @param list<int> $params
     * @return list<int>
     */
    private function userIds(string $sql, array $params): array
    {
        return array_map(static fn (array $row): int => (int) $row['user_id'], $this->fetchAll($sql, $params));
    }

    /**
     * `settings_json` als Zuordnung; NULL, leer, beschädigt oder keine Zuordnung ergibt `[]`.
     *
     * @return array<string, mixed>
     */
    private static function decodeSettings(mixed $json): array
    {
        if (!is_string($json) || $json === '') {
            return [];
        }
        $decoded = json_decode($json, true);

        return is_array($decoded) && ($decoded === [] || !array_is_list($decoded)) ? $decoded : [];
    }

    /**
     * Prüft die Spalten eines Rangs und liefert sie unverändert.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function rankValues(array $data): array
    {
        $data = self::pick($data, [...array_keys(self::RANK_LIMITS), 'min_posts']);
        if (array_key_exists('title', $data) && (!is_string($data['title']) || trim($data['title']) === '')) {
            throw new \InvalidArgumentException('Rang ohne Titel.');
        }
        if (self::exceeds($data, self::RANK_LIMITS)) {
            throw new \InvalidArgumentException('Wert eines Rangs außerhalb der Spalte.');
        }
        if (array_key_exists('min_posts', $data) && $data['min_posts'] !== null && self::exceeds($data, ['min_posts' => ['uint']])) {
            throw new \InvalidArgumentException('min_posts ist weder NULL noch eine Zahl von 0 bis 4294967295.');
        }

        return $data;
    }

    /**
     * Rang mit Typen.
     *
     * @param array<string, mixed> $row
     * @return array{id: int, title: string, title_en: string|null, min_posts: int|null, color: string|null, icon: string|null, sort_order: int}
     */
    private static function rankRow(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'title' => (string) $row['title'],
            'title_en' => $row['title_en'] === null ? null : (string) $row['title_en'],
            'min_posts' => $row['min_posts'] === null ? null : (int) $row['min_posts'],
            'color' => $row['color'] === null ? null : (string) $row['color'],
            'icon' => $row['icon'] === null ? null : (string) $row['icon'],
            'sort_order' => (int) $row['sort_order'],
        ];
    }
}
