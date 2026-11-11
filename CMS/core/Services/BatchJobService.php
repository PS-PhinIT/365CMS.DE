<?php
/**
 * Gemeinsame Infrastruktur für lange Admin-Aufgaben (Suchindex-Neuaufbau, Thumbnails, Import …).
 *
 * - Ein Job je Typ und Scope ist gleichzeitig aktiv (Scope z. B. Kennung einer Importdatei, sonst '').
 * - Jeder Schritt läuft unter einer Datenbank-Sperre mit Ablaufzeit (keine parallelen Schritte,
 *   abgebrochene Browser-Sitzungen blockieren höchstens LOCK_TTL Sekunden).
 * - Zustand (Cursor, Zähler, Fehler) liegt in der Tabelle `batch_jobs` – Jobs lassen sich nach
 *   Neuladen der Seite oder per Cron fortsetzen.
 *
 * Gegenstück im Browser: `CMS/assets/js/admin-batch-runner.js`.
 *
 * @package CMSv2\Core\Services
 */

declare(strict_types=1);

namespace CMS\Services;

use CMS\Database;
use CMS\Json;
use CMS\Logger;
use CMS\SchemaManager;

if (!defined('ABSPATH')) {
    exit;
}

final class BatchJobService
{
    public const TABLE = 'batch_jobs';
    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_DONE = 'done';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    private const ACTIVE_STATUSES = [self::STATUS_PENDING, self::STATUS_RUNNING];
    private const FINISHED_STATUSES = [self::STATUS_DONE, self::STATUS_FAILED, self::STATUS_CANCELLED];
    /** Spalten, die persistStep() schreiben darf (Spaltennamen nie aus Eingaben). */
    private const WRITABLE_COLUMNS = [
        'status', 'total', 'processed', 'failed', 'state_json', 'errors_json', 'message',
        'lock_token', 'locked_until', 'updated_at', 'finished_at',
    ];
    private const LOCK_TTL = 120;
    private const MAX_ERRORS = 200;
    private const KEEP_FINISHED_DAYS = 30;

    private static ?self $instance = null;

    private readonly Database $db;
    private readonly string $table;

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    private function __construct()
    {
        $this->db = Database::instance();
        $this->table = $this->db->getPrefix() . self::TABLE;
        $this->db->runSchemaOnce(self::TABLE, __FILE__, fn (): bool => self::ensureSchema($this->db));
    }

    /**
     * Legt die Tabelle an, falls die Migration auf Schema v25 noch aussteht. Die Definition stammt
     * aus SchemaManager (Neuinstallation) und wird auch von MigrationManager (Update) verwendet.
     */
    public static function ensureSchema(Database $db): bool
    {
        $charset = defined('DB_CHARSET') ? (string) DB_CHARSET : 'utf8mb4';
        $sql = SchemaManager::getSchemaQueries($db->getPrefix(), $charset)[self::TABLE] ?? '';
        if ($sql === '') {
            return false;
        }
        $db->query($sql);

        return true;
    }

    /**
     * Startet einen Job. Läuft bereits einer desselben Typs/Scopes, wird dieser zurückgegeben
     * (`resumed` = true) – nie zwei parallel.
     *
     * @param array<string, mixed> $state Startzustand (Cursor, Optionen)
     * @return array{job: array<string, mixed>, resumed: bool}
     */
    public function start(string $type, string $scope, int $total, array $state = [], ?int $userId = null): array
    {
        $type = $this->normalizeKey($type, 60);
        $scope = $this->normalizeKey($scope, 120);
        $this->pruneFinished();

        $active = $this->findActive($type, $scope);
        if ($active !== null) {
            return ['job' => $active, 'resumed' => true];
        }

        $now = $this->now();
        $id = $this->db->insert(self::TABLE, [
            'job_type' => $type,
            'scope_key' => $scope,
            'status' => self::STATUS_PENDING,
            'total' => max(0, $total),
            'processed' => 0,
            'failed' => 0,
            'state_json' => self::encode($state),
            'errors_json' => '[]',
            'message' => '',
            'created_by' => $userId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        if (!is_int($id) || $id <= 0) {
            throw new \RuntimeException('Der Hintergrundauftrag konnte nicht angelegt werden.');
        }

        return ['job' => (array) $this->find($id), 'resumed' => false];
    }

    /**
     * Führt genau einen Schritt unter Sperre aus.
     *
     * Der Worker erhält den Job (inkl. `state`) und liefert:
     * `['processed' => int, 'failed' => int, 'state' => array, 'errors' => list<string>,
     *   'done' => bool, 'total' => ?int, 'message' => string]` (alle Schlüssel optional).
     *
     * @param callable(array<string, mixed>): array<string, mixed> $worker
     * @return array<string, mixed> aktualisierter Job; `busy` = true, wenn ein anderer Schritt läuft
     */
    public function step(int $jobId, callable $worker): array
    {
        $job = $this->find($jobId);
        if ($job === null) {
            throw new \RuntimeException('Der Hintergrundauftrag wurde nicht gefunden.');
        }

        if (!in_array($job['status'], self::ACTIVE_STATUSES, true)) {
            return $job;
        }

        $token = bin2hex(random_bytes(16));
        $now = $this->now();
        $claimed = $this->db->execute(
            "UPDATE {$this->table}
                SET lock_token = ?, locked_until = ?, status = ?, updated_at = ?
              WHERE id = ? AND status IN (?, ?)
                AND (locked_until IS NULL OR locked_until < ?)",
            [$token, $this->now(self::LOCK_TTL), self::STATUS_RUNNING, $now, $jobId, ...self::ACTIVE_STATUSES, $now]
        )->rowCount() === 1;

        if (!$claimed) {
            $job['busy'] = true;

            return $job;
        }

        $job['status'] = self::STATUS_RUNNING;
        $errors = $job['errors'];

        try {
            $result = $worker($job);
        } catch (\Throwable $e) {
            Logger::instance()->withChannel('batch.jobs')->error('Schritt eines Hintergrundauftrags fehlgeschlagen.', [
                'job_id' => $jobId,
                'type' => $job['type'],
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
            $errors[] = 'Interner Fehler im Verarbeitungsschritt – Details im Systemprotokoll.';
            $this->persistStep($jobId, $token, [
                'status' => self::STATUS_FAILED,
                'errors_json' => self::encode(array_slice($errors, -self::MAX_ERRORS)),
                'message' => 'Abgebrochen nach einem Fehler.',
                'finished_at' => $this->now(),
            ]);

            return (array) $this->find($jobId);
        }

        foreach ((array) ($result['errors'] ?? []) as $error) {
            $errors[] = mb_substr((string) $error, 0, 500);
        }

        $done = !empty($result['done']);
        $update = [
            'processed' => max(0, (int) $job['processed'] + (int) ($result['processed'] ?? 0)),
            'failed' => max(0, (int) $job['failed'] + (int) ($result['failed'] ?? 0)),
            'errors_json' => self::encode(array_slice($errors, -self::MAX_ERRORS)),
            'message' => mb_substr((string) ($result['message'] ?? ''), 0, 255),
            'status' => $done ? self::STATUS_DONE : self::STATUS_RUNNING,
        ];
        if (array_key_exists('state', $result) && is_array($result['state'])) {
            $update['state_json'] = self::encode($result['state']);
        }
        if (isset($result['total']) && is_int($result['total'])) {
            $update['total'] = max(0, $result['total']);
        }
        if ($done) {
            $update['finished_at'] = $this->now();
        }

        $this->persistStep($jobId, $token, $update);

        return (array) $this->find($jobId);
    }

    public function cancel(int $jobId): bool
    {
        $now = $this->now();

        return $this->db->execute(
            "UPDATE {$this->table} SET status = ?, finished_at = ?, updated_at = ?, lock_token = NULL, locked_until = NULL
              WHERE id = ? AND status IN (?, ?)",
            [self::STATUS_CANCELLED, $now, $now, $jobId, ...self::ACTIVE_STATUSES]
        )->rowCount() === 1;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $jobId): ?array
    {
        $row = $this->db->get_row("SELECT * FROM {$this->table} WHERE id = ?", [$jobId]);

        return $row !== null ? $this->hydrate($row) : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findActive(string $type, string $scope = ''): ?array
    {
        $row = $this->db->get_row(
            "SELECT * FROM {$this->table} WHERE job_type = ? AND scope_key = ? AND status IN (?, ?) ORDER BY id DESC LIMIT 1",
            [$this->normalizeKey($type, 60), $this->normalizeKey($scope, 120), ...self::ACTIVE_STATUSES]
        );

        return $row !== null ? $this->hydrate($row) : null;
    }

    /**
     * @return array<string, mixed>|null letzter Job (auch abgeschlossen)
     */
    public function latest(string $type, string $scope = ''): ?array
    {
        $row = $this->db->get_row(
            "SELECT * FROM {$this->table} WHERE job_type = ? AND scope_key = ? ORDER BY id DESC LIMIT 1",
            [$this->normalizeKey($type, 60), $this->normalizeKey($scope, 120)]
        );

        return $row !== null ? $this->hydrate($row) : null;
    }

    /**
     * Öffentliche Sicht für JSON-Antworten (ohne internen Zustand und Sperrdaten).
     *
     * @param array<string, mixed> $job
     * @return array<string, mixed>
     */
    public function toPublic(array $job): array
    {
        $total = (int) ($job['total'] ?? 0);
        $processed = (int) ($job['processed'] ?? 0);
        $done = ($job['status'] ?? '') === self::STATUS_DONE;

        return [
            'id' => (int) ($job['id'] ?? 0),
            'type' => (string) ($job['type'] ?? ''),
            'status' => (string) ($job['status'] ?? ''),
            'total' => $total,
            'processed' => $processed,
            'failed' => (int) ($job['failed'] ?? 0),
            'percent' => $done ? 100 : ($total > 0 ? min(99, (int) floor($processed * 100 / $total)) : 0),
            'message' => (string) ($job['message'] ?? ''),
            'errors' => array_slice((array) ($job['errors'] ?? []), -50),
            'busy' => !empty($job['busy']),
            'created_at' => (string) ($job['created_at'] ?? ''),
            'finished_at' => (string) ($job['finished_at'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function persistStep(int $jobId, string $token, array $data): void
    {
        $data['updated_at'] = $this->now();
        $data['lock_token'] = null;
        $data['locked_until'] = null;

        $assignments = [];
        $params = [];
        foreach ($data as $column => $value) {
            if (!in_array($column, self::WRITABLE_COLUMNS, true)) {
                continue;
            }
            $assignments[] = $column . ' = ?';
            $params[] = $value;
        }
        $params[] = $jobId;
        $params[] = $token;

        $this->db->execute(
            "UPDATE {$this->table} SET " . implode(', ', $assignments) . ' WHERE id = ? AND lock_token = ?',
            $params
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function hydrate(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'type' => (string) $row->job_type,
            'scope' => (string) $row->scope_key,
            'status' => (string) $row->status,
            'total' => (int) $row->total,
            'processed' => (int) $row->processed,
            'failed' => (int) $row->failed,
            'state' => Json::decodeArray((string) ($row->state_json ?? ''), []),
            'errors' => array_values(array_map('strval', Json::decodeArray((string) ($row->errors_json ?? ''), []))),
            'message' => (string) $row->message,
            'created_by' => $row->created_by !== null ? (int) $row->created_by : null,
            'created_at' => (string) $row->created_at,
            'updated_at' => (string) $row->updated_at,
            'finished_at' => (string) ($row->finished_at ?? ''),
        ];
    }

    private function pruneFinished(): void
    {
        try {
            $this->db->execute(
                "DELETE FROM {$this->table} WHERE status IN (?, ?, ?) AND updated_at < ?",
                [...self::FINISHED_STATUSES, $this->now(-self::KEEP_FINISHED_DAYS * 86400)]
            );
        } catch (\Throwable) {
            // Aufräumen ist optional.
        }
    }

    private static function encode(mixed $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }

    private function normalizeKey(string $value, int $maxLength): string
    {
        $value = strtolower(trim($value));
        $value = (string) preg_replace('/[^a-z0-9_.:\-]/', '', $value);

        return substr($value, 0, $maxLength);
    }

    private function now(int $offsetSeconds = 0): string
    {
        return date('Y-m-d H:i:s', time() + $offsetSeconds);
    }
}
