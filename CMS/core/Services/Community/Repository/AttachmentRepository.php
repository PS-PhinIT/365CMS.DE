<?php
/**
 * Anhänge (`community_attachments`, PR #2b; Vertrag DESIGN-2b §3.7, §3.10, §4.6, Entwurf medien §1.1).
 *
 * - Zustand eines Anhangs = `post_id`: NULL = noch nicht zugeordnet (verwaist), sonst Anhang dieses Beitrags. Die Vorschau liegt unter
 *   demselben Namen mit `.preview` vor der Endung; das Forum ergibt sich aus dem Beitrag. Keine Schemaänderung.
 * - Keine Uhr: Zeiten sind UTC-Texte vom Aufrufer.
 * - Sperren nur über den Primärschlüssel und nur für IDs, die der Dienst vorher im Autocommit gefunden hat (`lockMany()`, Ebene 5 der
 *   Sperrfolge, aufsteigend): keine Lückensperre, Uploads anderer Mitglieder laufen weiter.
 * - `claim()` ordnet nur verwaiste eigene Anhänge zu (zweite Linie hinter `AttachmentService::verify()`), `deletePending()` löscht nur
 *   verwaiste. Beides prüft die Bedingung in derselben Anweisung.
 * - Listen (`pendingForForm()`, `forPosts()`) liefern Anhangszeilen ohne `storage_path`, `sha256` und `upload_token`; die braucht nur
 *   der Dienst (`find()`, `findMany()`, `lockMany()`, `orphansBefore()`).
 * - Ganzzahlige Spalten kommen als `int` (bzw. `null`) zurück.
 *
 * @package CMSv2\Core\Services\Community
 */

declare(strict_types=1);

namespace CMS\Services\Community\Repository;

if (!defined('ABSPATH')) {
    exit;
}

final class AttachmentRepository extends AbstractRepository
{
    public const TABLE = 'community_attachments';
    /** IDs je `IN`-Liste; größere Listen laufen in Stücken. */
    public const MAX_IDS = 100;

    /** Spalten beim Anlegen (Vertrag DESIGN-2b §4.6 Nr. 5). */
    private const INSERTABLE = [
        'post_id', 'user_id', 'storage_path', 'original_name', 'mime_type', 'extension', 'file_size', 'sha256', 'width', 'height',
        'has_preview', 'upload_token', 'created_at',
    ];

    /** Spaltengrenzen laut Schema (`exceeds()`); `width`/`height` sind SMALLINT UNSIGNED und werden gesondert geprüft. */
    private const LIMITS = [
        'storage_path' => ['chars', 255, false],
        'original_name' => ['chars', 255, false],
        'mime_type' => ['chars', 100, false],
        'extension' => ['chars', 10, false],
        'sha256' => ['chars', 64, false],
        'upload_token' => ['chars', 32, true],
        'file_size' => ['uint'],
        'has_preview' => ['flag'],
    ];

    /** Anhangszeile für Listen (ohne Ablagepfad, Prüfsumme und Formular-Kennung). */
    private const LIST_COLUMNS = 'id, post_id, user_id, original_name, mime_type, extension, file_size, width, height, has_preview, download_count, created_at';

    /** Alle Spalten (`find()`). */
    private const ALL_COLUMNS = 'id, post_id, user_id, storage_path, original_name, mime_type, extension, file_size, sha256, width, height, has_preview, download_count, upload_token, created_at';

    /** Spalten für Renderer und Zuordnungsplan (`findMany()`); `storage_path` für das Löschen entfernter Anhänge nach dem Commit. */
    private const FIND_MANY_COLUMNS = 'id, post_id, user_id, storage_path, mime_type, extension, width, height, has_preview';

    /** Spalten der gesperrten Zeilen (`lockMany()`). */
    private const LOCK_COLUMNS = 'id, post_id, user_id, upload_token, storage_path, extension, has_preview';

    /** Ganzzahlige Spalten (Rückgabe als int; `null` bleibt `null`). */
    private const INT_COLUMNS = ['id', 'post_id', 'user_id', 'file_size', 'width', 'height', 'has_preview', 'download_count'];

    /**
     * Alle Spalten eines Anhangs oder `null`.
     *
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $row = $this->fetchOne('SELECT ' . self::ALL_COLUMNS . ' FROM ' . $this->table(self::TABLE) . ' WHERE id = ? LIMIT 1', [$id]);

        return $row === null ? null : self::typed($row);
    }

    /**
     * Anhänge nach ID ohne Sperre (Form für den Renderer und den Zuordnungsplan), Schlüssel = ID, aufsteigend; fehlende IDs fehlen.
     *
     * @param array<array-key, mixed> $ids
     * @return array<int, array{id: int, post_id: ?int, user_id: ?int, storage_path: string, mime_type: string, extension: string, width: ?int, height: ?int, has_preview: int}>
     */
    public function findMany(array $ids): array
    {
        $rows = [];
        foreach ($this->chunks($ids) as $chunk) {
            foreach ($this->fetchAll(
                'SELECT ' . self::FIND_MANY_COLUMNS . ' FROM ' . $this->table(self::TABLE) . ' WHERE id IN (' . self::placeholders(count($chunk)) . ') ORDER BY id ASC',
                $chunk
            ) as $row) {
                $rows[(int) $row['id']] = self::typed($row);
            }
        }
        ksort($rows);

        return $rows;
    }

    /**
     * Wie `findMany()`, mit Zeilensperre über den Primärschlüssel (nur in einer Transaktion): je Stück eine Anweisung, aufsteigend
     * (Ebene 5 der Sperrfolge, Vertrag DESIGN-2b §3.10). Nur IDs, die der Dienst vorher gefunden hat, damit keine Lückensperre entsteht.
     *
     * @param array<array-key, mixed> $ids
     * @return array<int, array{id: int, post_id: ?int, user_id: ?int, upload_token: ?string, storage_path: string, extension: string, has_preview: int}>
     */
    public function lockMany(array $ids): array
    {
        $rows = [];
        foreach ($this->chunks($ids) as $chunk) {
            foreach ($this->fetchAll(
                'SELECT ' . self::LOCK_COLUMNS . ' FROM ' . $this->table(self::TABLE) . ' WHERE id IN (' . self::placeholders(count($chunk)) . ')'
                . ' ORDER BY id ASC' . self::forUpdate(),
                $chunk
            ) as $row) {
                $rows[(int) $row['id']] = self::typed($row);
            }
        }
        ksort($rows);

        return $rows;
    }

    /**
     * Legt einen Anhang an und liefert die ID. Unbekannte Spalte oder Grenzverstoß ⇒ `InvalidArgumentException`.
     *
     * @param array<string, mixed> $row Spalten aus INSERTABLE
     */
    public function insert(array $row): int
    {
        $row = self::pick($row, self::INSERTABLE);
        foreach (['width', 'height'] as $column) {
            $value = $row[$column] ?? null;
            if ($value !== null && (!is_int($value) || $value < 0 || $value > 65535)) {
                throw new \InvalidArgumentException('Ungültiger Wert für ' . $column . '.');
            }
        }
        foreach (['post_id', 'user_id'] as $column) {
            $value = $row[$column] ?? null;
            if ($value !== null && (!is_int($value) || $value < 1)) {
                throw new \InvalidArgumentException('Ungültiger Wert für ' . $column . '.');
            }
        }
        if (self::exceeds($row, self::LIMITS)) {
            throw new \InvalidArgumentException('Anhang: Wert passt nicht in seine Spalte.');
        }

        return $this->insertRow(self::TABLE, $row, self::INSERTABLE);
    }

    /**
     * Ordnet verwaiste eigene Anhänge dem Beitrag zu und leert die Formular-Kennung. Liefert die Zahl geänderter Zeilen; ein schon
     * zugeordneter oder fremder Anhang zählt nicht.
     *
     * @param array<array-key, mixed> $ids
     */
    public function claim(array $ids, int $postId, int $userId): int
    {
        $changed = 0;
        foreach ($this->chunks($ids) as $chunk) {
            $changed += $this->run(
                'UPDATE ' . $this->table(self::TABLE) . ' SET post_id = ?, upload_token = NULL WHERE id IN (' . self::placeholders(count($chunk)) . ')'
                . ' AND post_id IS NULL AND user_id = ?',
                [$postId, ...$chunk, $userId]
            );
        }

        return $changed;
    }

    /** Löscht einen Anhang dieses Beitrags; liefert die Zahl gelöschter Zeilen (0 oder 1). */
    public function deleteOfPost(int $id, int $postId): int
    {
        return $this->run('DELETE FROM ' . $this->table(self::TABLE) . ' WHERE id = ? AND post_id = ?', [$id, $postId]);
    }

    /**
     * Löscht einen verwaisten Anhang (mit `$userId` nur einen eigenen); liefert 0 oder 1. Ein inzwischen zugeordneter Anhang bleibt.
     */
    public function deletePending(int $id, ?int $userId = null): int
    {
        $sql = 'DELETE FROM ' . $this->table(self::TABLE) . ' WHERE id = ? AND post_id IS NULL';
        $params = [$id];
        if ($userId !== null) {
            $sql .= ' AND user_id = ?';
            $params[] = $userId;
        }

        return $this->run($sql, $params);
    }

    /**
     * Eigene verwaiste Anhänge eines Formulars, aufsteigend (`idx_community_attachments_token`). `$limit` 1 bis MAX_IDS.
     *
     * @return list<array<string, mixed>>
     */
    public function pendingForForm(int $userId, string $token, int $limit = 20): array
    {
        $limit = max(1, min(self::MAX_IDS, $limit));

        return array_map(self::typed(...), $this->fetchAll(
            'SELECT ' . self::LIST_COLUMNS . ' FROM ' . $this->table(self::TABLE)
            . ' WHERE upload_token = ? AND user_id = ? AND post_id IS NULL ORDER BY id ASC LIMIT ?',
            [$token, $userId, $limit]
        ));
    }

    /** Eigene verwaiste Anhänge eines Formulars (`idx_community_attachments_token`). */
    public function countPendingToken(int $userId, string $token): int
    {
        return (int) $this->fetchValue(
            'SELECT COUNT(*) FROM ' . $this->table(self::TABLE) . ' WHERE upload_token = ? AND user_id = ? AND post_id IS NULL',
            [$token, $userId]
        );
    }

    /** Anhänge eines Beitrags (`idx_community_attachments_post`). */
    public function countForPost(int $postId): int
    {
        return (int) $this->fetchValue('SELECT COUNT(*) FROM ' . $this->table(self::TABLE) . ' WHERE post_id = ?', [$postId]);
    }

    /**
     * Anhangszeilen je Beitrag (Schlüssel = Beitrags-ID, darin aufsteigend nach ID), eine Abfrage je MAX_IDS Beiträge über
     * `idx_community_attachments_post`. Beiträge ohne Anhang fehlen.
     *
     * @param array<array-key, mixed> $postIds
     * @return array<int, list<array<string, mixed>>>
     */
    public function forPosts(array $postIds): array
    {
        $byPost = [];
        foreach ($this->chunks($postIds) as $chunk) {
            foreach ($this->fetchAll(
                'SELECT ' . self::LIST_COLUMNS . ' FROM ' . $this->table(self::TABLE) . ' WHERE post_id IN (' . self::placeholders(count($chunk)) . ')',
                $chunk
            ) as $row) {
                $row = self::typed($row);
                $byPost[(int) $row['post_id']][(int) $row['id']] = $row;
            }
        }
        ksort($byPost);
        foreach ($byPost as $postId => $rows) {
            ksort($rows);
            $byPost[$postId] = array_values($rows);
        }

        return $byPost;
    }

    /** Anhänge eines Mitglieds seit `$sinceUtc`, zugeordnet oder nicht (Tageskontingent, `idx_community_attachments_user`). */
    public function countUserSince(int $userId, string $sinceUtc): int
    {
        return (int) $this->fetchValue(
            'SELECT COUNT(*) FROM ' . $this->table(self::TABLE) . ' WHERE user_id = ? AND created_at >= ?',
            [$userId, $sinceUtc]
        );
    }

    /** Verwaiste Anhänge eines Mitglieds seit `$sinceUtc` (`idx_community_attachments_user`). */
    public function countPendingSince(int $userId, string $sinceUtc): int
    {
        return (int) $this->fetchValue(
            'SELECT COUNT(*) FROM ' . $this->table(self::TABLE) . ' WHERE user_id = ? AND created_at >= ? AND post_id IS NULL',
            [$userId, $sinceUtc]
        );
    }

    /**
     * Verwaiste Anhänge, älter als `$cutoffUtc`, älteste zuerst (`idx_community_attachments_post`: `post_id IS NULL`, Bereich über
     * `created_at`, Primärschlüssel als Suffix, ohne Filesort). `$limit` 1 bis 1000.
     *
     * @return list<array{id: int, user_id: ?int, storage_path: string, extension: string, has_preview: int}>
     */
    public function orphansBefore(string $cutoffUtc, int $limit): array
    {
        $limit = max(1, min(1000, $limit));

        return array_map(self::typed(...), $this->fetchAll(
            'SELECT id, user_id, storage_path, extension, has_preview FROM ' . $this->table(self::TABLE)
            . ' WHERE post_id IS NULL AND created_at < ? ORDER BY created_at ASC, id ASC LIMIT ?',
            [$cutoffUtc, $limit]
        ));
    }

    /** Zählt einen Abruf; `true` = Zeile gefunden (die Anweisung ändert immer einen Wert). */
    public function bumpDownloads(int $id): bool
    {
        return $this->run('UPDATE ' . $this->table(self::TABLE) . ' SET download_count = download_count + 1 WHERE id = ?', [$id]) === 1;
    }

    /**
     * IDs normalisieren (positiv, eindeutig), aufsteigend sortieren, in Stücke zu höchstens MAX_IDS teilen.
     *
     * @param array<array-key, mixed> $ids
     * @return list<list<int>>
     */
    private function chunks(array $ids): array
    {
        $ids = self::ids($ids);
        sort($ids);

        return array_chunk($ids, self::MAX_IDS);
    }

    /**
     * Ganzzahlige Spalten als int (null bleibt null).
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function typed(array $row): array
    {
        foreach (self::INT_COLUMNS as $column) {
            if (array_key_exists($column, $row) && $row[$column] !== null) {
                $row[$column] = (int) $row[$column];
            }
        }

        return $row;
    }
}
