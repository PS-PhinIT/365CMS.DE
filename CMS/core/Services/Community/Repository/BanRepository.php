<?php
/**
 * Community-Sperren (`community_bans`): Lesen für Rechteprüfung, Verlauf und Liste, Anlegen und Aufheben.
 *
 * - Anlegen und Aufheben nur über `BanService`; interne Notizen liest nur `find()`/`forUser()` mit `$withNote`. Alle übrigen Abfragen
 *   wählen nur Spalten, die ein Mitglied sehen darf.
 * - Wirksam ist eine Sperre nach der Regel ACTIVE (einzige Stelle): nicht aufgehoben, begonnen, nicht abgelaufen. Der Umfang kommt in
 *   jeder Lesemethode über `scope()`: `write` bleibt, alles andere gilt als `full` (im Zweifel gesperrt).
 * - Schreiben ohne Sperre in `community_bans` (keine Lückensperren): `BanService` serialisiert über die Statistikzeile des Betroffenen
 *   (Vertrag DESIGN-2b §3.10). Ein Verstoß gegen die Form beim Anlegen ist ein Programmfehler (`InvalidArgumentException`).
 *
 * @package CMSv2\Core\Services\Community
 */

declare(strict_types=1);

namespace CMS\Services\Community\Repository;

if (!defined('ABSPATH')) {
    exit;
}

final class BanRepository extends AbstractRepository
{
    /** Gültige Umfänge beim Anlegen (`BanService::SCOPES`). */
    public const SCOPES = ['write', 'full'];
    /** Größte Länge des Grundes in Zeichen (`reason` VARCHAR(255)). */
    public const MAX_REASON_CHARS = 255;

    private const TABLE = 'community_bans';

    /** Wirksamkeitsregel (Parameter: zweimal der Zeitpunkt UTC). */
    private const ACTIVE = 'lifted_at IS NULL AND starts_at <= ? AND (ends_at IS NULL OR ends_at > ?)';

    /** Spalten ohne interne Notiz. */
    private const COLUMNS = 'id, user_id, scope, reason, issued_by, starts_at, ends_at, lifted_at, lifted_by, created_at';

    /** Spalten beim Anlegen (Aufhebung nur über `lift()`). */
    private const INSERTABLE = ['user_id', 'scope', 'reason', 'internal_note', 'issued_by', 'starts_at', 'ends_at', 'created_at'];

    /** Größte ID einer INT-UNSIGNED-Spalte. */
    private const MAX_UINT = 4294967295;

    /** Größte Seite von `forUser()` und `listActive()`. */
    private const MAX_LIMIT = 200;

    /**
     * Wirksame Sperre eines Mitglieds zum Zeitpunkt `$nowUtc`. Bei mehreren gilt die strengste: alles außer `write` zuerst, dann die
     * jüngste. Index `idx_community_bans_user`.
     *
     * @return array{id: int, scope: string, reason: string, ends_at: string|null}|null `scope` ist `write` oder `full`
     */
    public function activeFor(int $userId, string $nowUtc): ?array
    {
        $row = $this->fetchOne(
            'SELECT id, scope, reason, ends_at FROM ' . $this->table(self::TABLE)
            . ' WHERE user_id = ? AND ' . self::ACTIVE
            . " ORDER BY CASE WHEN scope = 'write' THEN 0 ELSE 1 END DESC, id DESC LIMIT 1",
            [$userId, $nowUtc, $nowUtc]
        );

        return $row === null ? null : [
            'id' => (int) $row['id'],
            'scope' => self::scope((string) $row['scope']),
            'reason' => (string) $row['reason'],
            'ends_at' => self::nullableText($row['ends_at']),
        ];
    }

    /**
     * Alle wirksamen Sperren eines Mitglieds, aufsteigend nach ID (Deckungsprüfung beim Sperren). Index `idx_community_bans_user`.
     *
     * @return list<array{id: int, scope: string, ends_at: string|null, issued_by: int|null, created_at: string}>
     */
    public function activeList(int $userId, string $nowUtc): array
    {
        $rows = $this->fetchAll(
            'SELECT id, scope, ends_at, issued_by, created_at FROM ' . $this->table(self::TABLE) . ' WHERE user_id = ? AND ' . self::ACTIVE . ' ORDER BY id ASC',
            [$userId, $nowUtc, $nowUtc]
        );

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'scope' => self::scope((string) $row['scope']),
            'ends_at' => self::nullableText($row['ends_at']),
            'issued_by' => self::nullableInt($row['issued_by']),
            'created_at' => (string) $row['created_at'],
        ], $rows);
    }

    /**
     * Eine Sperre (alle Zustände) oder `null`; die interne Notiz nur mit `$withNote`.
     *
     * @return array<string, mixed>|null
     */
    public function find(int $id, bool $withNote = false): ?array
    {
        $row = $this->fetchOne('SELECT ' . self::selectColumns($withNote) . ' FROM ' . $this->table(self::TABLE) . ' WHERE id = ? LIMIT 1', [$id]);

        return $row === null ? null : self::full($row, $withNote);
    }

    /**
     * Legt eine Sperre an und liefert die ID. Form: `user_id`, `scope` (SCOPES), `reason` (1 bis 255 Zeichen), `internal_note` (NULL,
     * `''` wird NULL), `issued_by` (NULL = System bzw. gelöschtes Konto), `starts_at`, `ends_at` (NULL = unbefristet, sonst nach
     * `starts_at`), `created_at`; Zeiten UTC `Y-m-d H:i:s`. Verstoß: `InvalidArgumentException`, nichts geschrieben.
     *
     * @param array<string, mixed> $data
     */
    public function insert(array $data): int
    {
        $data = self::pick($data, self::INSERTABLE);
        foreach (['user_id', 'scope', 'reason', 'starts_at', 'created_at'] as $key) {
            if (!array_key_exists($key, $data)) {
                throw new \InvalidArgumentException('Sperre ohne ' . $key . '.');
            }
        }
        if (!in_array($data['scope'], self::SCOPES, true)) {
            throw new \InvalidArgumentException('Unbekannter Sperrumfang.');
        }
        if (!is_string($data['reason']) || trim($data['reason']) === '' || self::exceeds($data, ['reason' => ['chars', self::MAX_REASON_CHARS, false]])) {
            throw new \InvalidArgumentException('Grund fehlt oder ist länger als ' . self::MAX_REASON_CHARS . ' Zeichen.');
        }
        $note = $data['internal_note'] ?? null;
        if ($note === '') {
            $note = null;
        }
        if (self::exceeds(['internal_note' => $note], ['internal_note' => ['bytes', 65535, true]])) {
            throw new \InvalidArgumentException('Interne Notiz ungültig oder länger als 65535 Byte.');
        }
        $starts = self::utcText($data['starts_at'], 'starts_at');
        $ends = ($data['ends_at'] ?? null) === null ? null : self::utcText($data['ends_at'], 'ends_at');
        if ($ends !== null && $ends <= $starts) {
            throw new \InvalidArgumentException('Ende der Sperre liegt nicht nach dem Beginn.');
        }

        return $this->insertRow(self::TABLE, [
            'user_id' => self::uint($data['user_id'], 'user_id'),
            'scope' => $data['scope'],
            'reason' => $data['reason'],
            'internal_note' => $note,
            'issued_by' => ($data['issued_by'] ?? null) === null ? null : self::uint($data['issued_by'], 'issued_by'),
            'starts_at' => $starts,
            'ends_at' => $ends,
            'created_at' => self::utcText($data['created_at'], 'created_at'),
        ], self::INSERTABLE);
    }

    /**
     * Hebt eine offene Sperre auf (`lifted_at`, `lifted_by`). `true` nur, wenn sie bis eben offen war; eine schon aufgehobene bleibt
     * unverändert (einzige Prüfung auf „schon aufgehoben“, sanktionen §2.6).
     */
    public function lift(int $id, int $liftedBy, string $nowUtc): bool
    {
        return $this->run(
            'UPDATE ' . $this->table(self::TABLE) . ' SET lifted_at = ?, lifted_by = ? WHERE id = ? AND lifted_at IS NULL',
            [$nowUtc, $liftedBy, $id]
        ) > 0;
    }

    /**
     * Verlauf eines Mitglieds (alle Zustände), jüngste zuerst, höchstens `$limit` (1 bis 200); die interne Notiz nur mit `$withNote`.
     *
     * @return list<array<string, mixed>>
     */
    public function forUser(int $userId, int $limit = 50, bool $withNote = false): array
    {
        $rows = $this->fetchAll(
            'SELECT ' . self::selectColumns($withNote) . ' FROM ' . $this->table(self::TABLE) . ' WHERE user_id = ? ORDER BY id DESC LIMIT ?',
            [$userId, max(1, min(self::MAX_LIMIT, $limit))]
        );

        return array_map(static fn (array $row): array => self::full($row, $withNote), $rows);
    }

    /**
     * Wirksame Sperren aller Mitglieder, ID absteigend, Keyset `id < $beforeId` (`$beforeId` < 1 = erste Seite), höchstens `$limit`
     * (1 bis 200). Ohne Notiz und ohne Aufhebung (wirksame Sperren sind nicht aufgehoben).
     *
     * @return list<array{id: int, user_id: int, scope: string, reason: string, issued_by: int|null, starts_at: string, ends_at: string|null, created_at: string}>
     */
    public function listActive(string $nowUtc, int $beforeId, int $limit): array
    {
        $rows = $this->fetchAll(
            'SELECT id, user_id, scope, reason, issued_by, starts_at, ends_at, created_at FROM ' . $this->table(self::TABLE)
            . ' WHERE ' . self::ACTIVE . ' AND id < ? ORDER BY id DESC LIMIT ?',
            [$nowUtc, $nowUtc, $beforeId < 1 ? self::MAX_UINT + 1 : $beforeId, max(1, min(self::MAX_LIMIT, $limit))]
        );

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'user_id' => (int) $row['user_id'],
            'scope' => self::scope((string) $row['scope']),
            'reason' => (string) $row['reason'],
            'issued_by' => self::nullableInt($row['issued_by']),
            'starts_at' => (string) $row['starts_at'],
            'ends_at' => self::nullableText($row['ends_at']),
            'created_at' => (string) $row['created_at'],
        ], $rows);
    }

    /** Umfang einer gespeicherten Sperre: `write` bleibt, alles andere gilt als `full` (fail closed; einzige Stelle der Regel). */
    private static function scope(string $raw): string
    {
        return $raw === 'write' ? 'write' : 'full';
    }

    private static function selectColumns(bool $withNote): string
    {
        return self::COLUMNS . ($withNote ? ', internal_note' : '');
    }

    /**
     * Zeile aller Spalten (COLUMNS) mit Typen, die Notiz nur mit `$withNote`.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function full(array $row, bool $withNote): array
    {
        $ban = [
            'id' => (int) $row['id'],
            'user_id' => (int) $row['user_id'],
            'scope' => self::scope((string) $row['scope']),
            'reason' => (string) $row['reason'],
            'issued_by' => self::nullableInt($row['issued_by']),
            'starts_at' => (string) $row['starts_at'],
            'ends_at' => self::nullableText($row['ends_at']),
            'lifted_at' => self::nullableText($row['lifted_at']),
            'lifted_by' => self::nullableInt($row['lifted_by']),
            'created_at' => (string) $row['created_at'],
        ];
        if ($withNote) {
            $ban['internal_note'] = self::nullableText($row['internal_note'] ?? null);
        }

        return $ban;
    }

    private static function nullableInt(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }

    private static function nullableText(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }

    /** Ganzzahl von 1 bis MAX_UINT. */
    private static function uint(mixed $value, string $field): int
    {
        if (!is_int($value) || $value < 1 || $value > self::MAX_UINT) {
            throw new \InvalidArgumentException('Ungültige ID in ' . $field . '.');
        }

        return $value;
    }

    /** UTC-Zeit `Y-m-d H:i:s`. */
    private static function utcText(mixed $value, string $field): string
    {
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value) !== 1) {
            throw new \InvalidArgumentException('Ungültige Zeit in ' . $field . ' (erwartet UTC Y-m-d H:i:s).');
        }

        return $value;
    }
}
