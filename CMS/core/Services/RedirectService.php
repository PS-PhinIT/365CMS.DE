<?php
/**
 * Redirect Service
 *
 * Verwalten von 404-Logs und Weiterleitungen.
 *
 * @package CMS\Services
 */

declare(strict_types=1);

namespace CMS\Services;

use CMS\Database;
use CMS\Logger;

if (!defined('ABSPATH')) {
    exit;
}

final class RedirectService
{
    /** Spalten, die der CSV-Import auswertet; alle Exporte beginnen mit genau diesen Spalten. */
    public const CSV_IMPORT_COLUMNS = ['redirect_id', 'source_path', 'site_scope', 'target_url', 'redirect_type', 'is_active', 'notes'];
    public const CSV_REDIRECT_EXPORT_COLUMNS = [...self::CSV_IMPORT_COLUMNS, 'hits', 'last_hit_at'];
    /** Obergrenze unterschiedlicher 404-Pfade im Monitor (Schutz vor Scanner-Flut). */
    public const NOT_FOUND_LOG_MAX_ROWS = 20000;
    public const NOT_FOUND_LOG_RETENTION_DAYS = 90;
    public const CSV_NOT_FOUND_EXPORT_COLUMNS = [...self::CSV_IMPORT_COLUMNS, 'request_host', 'hit_count', 'first_seen_at', 'last_seen_at', 'referrer_url'];
    public const CSV_IMPORT_MAX_ROWS = 5000;
    public const CSV_EXPORT_MAX_ROWS = 20000;

    private const CSV_HEADER_ALIASES = [
        'redirect_id' => ['redirect_id', 'id', 'regel_id'],
        'source_path' => ['source_path', 'source', 'quelle', 'quellpfad', 'request_path', 'pfad', '404_pfad'],
        'site_scope' => ['site_scope', 'site', 'scope'],
        'target_url' => ['target_url', 'target', 'ziel', 'ziel_url', 'zielurl', 'ziel_slug', 'weiterleitung'],
        'redirect_type' => ['redirect_type', 'type', 'typ'],
        'is_active' => ['is_active', 'active', 'aktiv'],
        'notes' => ['notes', 'note', 'notiz', 'notizen'],
    ];
    private const CSV_IMPORT_MAX_ERROR_DETAILS = 25;

    private static ?self $instance = null;

    private readonly Database $db;
    private readonly string $prefix;
    private ?bool $hasSiteTableSlugColumn = null;
    /** @var array<int, array<string, string>>|null */
    private ?array $availableSiteScopes = null;

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    private function __construct()
    {
        $this->db = Database::instance();
        $this->prefix = $this->db->getPrefix();
        $this->db->runSchemaOnce('redirect_service', __FILE__, function (): void {
            $this->ensureTables();
        });
    }

    public function ensureTables(): void
    {
        $this->db->getPdo()->exec(
            "CREATE TABLE IF NOT EXISTS {$this->prefix}redirect_rules (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                source_path VARCHAR(255) NOT NULL,
                site_scope VARCHAR(255) NOT NULL DEFAULT '',
                target_url VARCHAR(500) NOT NULL,
                redirect_type SMALLINT NOT NULL DEFAULT 301,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                notes TEXT DEFAULT NULL,
                hits INT UNSIGNED NOT NULL DEFAULT 0,
                last_hit_at DATETIME DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY idx_source_path_scope (source_path, site_scope),
                INDEX idx_active (is_active)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $this->db->getPdo()->exec(
            "CREATE TABLE IF NOT EXISTS {$this->prefix}not_found_logs (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                request_path VARCHAR(255) NOT NULL,
                request_host VARCHAR(255) NOT NULL DEFAULT '',
                request_method VARCHAR(10) NOT NULL DEFAULT 'GET',
                referrer_url VARCHAR(500) DEFAULT NULL,
                ip_address VARCHAR(64) DEFAULT NULL,
                user_agent VARCHAR(500) DEFAULT NULL,
                hit_count INT UNSIGNED NOT NULL DEFAULT 1,
                first_seen_at DATETIME NOT NULL,
                last_seen_at DATETIME NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY idx_request_path_host (request_path, request_host),
                INDEX idx_last_seen (last_seen_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $this->ensureColumnExists('redirect_rules', 'site_scope', "VARCHAR(255) NOT NULL DEFAULT '' AFTER source_path");
        $this->ensureColumnExists('not_found_logs', 'request_host', "VARCHAR(255) NOT NULL DEFAULT '' AFTER request_path");
        $this->ensureRedirectIndexes();
    }

    public function findRedirect(string $path, string $requestHost = ''): ?array
    {
        $normalized = $this->normalizePath($path);
        if ($this->shouldIgnorePath($normalized)) {
            return null;
        }

        $requestHost = $this->normalizeHost($requestHost);
        $candidates = $this->db->get_results(
            "SELECT * FROM {$this->prefix}redirect_rules WHERE source_path = ? AND is_active = 1",
            [$normalized]
        ) ?: [];

        $bestMatch = null;
        $bestScore = -1;
        foreach ($candidates as $candidate) {
            $score = $this->getRedirectSiteScore((array)$candidate, $normalized, $requestHost);
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestMatch = $candidate;
            }
        }

        if (!$bestMatch || $bestScore < 0) {
            return null;
        }

        $this->db->execute(
            "UPDATE {$this->prefix}redirect_rules SET hits = hits + 1, last_hit_at = NOW() WHERE id = ?",
            [(int)$bestMatch->id]
        );

        return [
            'id' => (int)$bestMatch->id,
            'source_path' => (string)$bestMatch->source_path,
            'site_scope' => (string)($bestMatch->site_scope ?? ''),
            'target_url' => (string)$bestMatch->target_url,
            'redirect_type' => (int)$bestMatch->redirect_type,
        ];
    }

    public function logNotFound(string $path, array $context = []): void
    {
        $normalized = $this->normalizePath($path);
        if ($this->shouldIgnorePath($normalized)) {
            return;
        }

        // Spaltenbreiten (VARCHAR 255) einhalten: Überlange Pfade ließen das INSERT im Strict-Mode
        // scheitern. Host-Header wird zusätzlich auf gültige Zeichen begrenzt.
        $normalized = $this->limitString($normalized, 255);
        $requestHost = $this->normalizeHost((string)($context['request_host'] ?? ''));
        $requestHost = preg_match('/^[a-z0-9.-]{1,255}$/', $requestHost) === 1 ? $requestHost : '';

        $values = [
            'request_method' => $this->limitString((string)($context['request_method'] ?? 'GET'), 10),
            'referrer_url' => $this->limitString((string)($context['referrer_url'] ?? ''), 500),
            'ip_address' => $this->limitString((string)($context['ip_address'] ?? ''), 64),
            'user_agent' => $this->limitString((string)($context['user_agent'] ?? ''), 500),
        ];

        try {
            $this->writeNotFoundLog($normalized, $requestHost, $values);
        } catch (\Throwable $e) {
            Logger::instance()->withChannel('redirects')->warning('404-Monitor: Eintrag konnte nicht gespeichert werden.', [
                'exception' => $e,
            ]);
        }
    }

    /** @param array{request_method:string, referrer_url:string, ip_address:string, user_agent:string} $values */
    private function writeNotFoundLog(string $normalized, string $requestHost, array $values): void
    {
        $updated = $this->db->execute(
            "UPDATE {$this->prefix}not_found_logs
             SET hit_count = hit_count + 1,
                 request_method = ?,
                 referrer_url = ?,
                 ip_address = ?,
                 user_agent = ?,
                 last_seen_at = NOW()
             WHERE request_path = ? AND request_host = ?",
            [
                $values['request_method'],
                $values['referrer_url'],
                $values['ip_address'],
                $values['user_agent'],
                $normalized,
                $requestHost,
            ]
        );
        if ($updated instanceof \PDOStatement && $updated->rowCount() > 0) {
            return;
        }

        // Zufällige 404-Pfade (Scanner/Bots) dürfen die Tabelle nicht unbegrenzt wachsen lassen.
        $rowCount = (int)($this->db->get_var("SELECT COUNT(*) FROM {$this->prefix}not_found_logs") ?? 0);
        if ($rowCount >= self::NOT_FOUND_LOG_MAX_ROWS) {
            return;
        }

        $this->db->execute(
            "INSERT INTO {$this->prefix}not_found_logs
                (request_path, request_host, request_method, referrer_url, ip_address, user_agent, hit_count, first_seen_at, last_seen_at)
             VALUES (?, ?, ?, ?, ?, ?, 1, NOW(), NOW())
             ON DUPLICATE KEY UPDATE hit_count = hit_count + 1, last_seen_at = NOW()",
            [
                $normalized,
                $requestHost,
                $values['request_method'],
                $values['referrer_url'],
                $values['ip_address'],
                $values['user_agent'],
            ]
        );
    }

    /**
     * Entfernt alte 404-Einträge (Aufruf über den täglichen Cron).
     */
    public function pruneNotFoundLogs(int $retentionDays = self::NOT_FOUND_LOG_RETENTION_DAYS): int
    {
        $retentionDays = max(7, $retentionDays);
        $stmt = $this->db->execute(
            "DELETE FROM {$this->prefix}not_found_logs WHERE last_seen_at < (NOW() - INTERVAL ? DAY) LIMIT 5000",
            [$retentionDays]
        );

        return $stmt instanceof \PDOStatement ? $stmt->rowCount() : 0;
    }

    public function getAdminData(): array
    {
        $redirectRows = $this->getRedirectRuleRows();
        $logRows = $this->getNotFoundLogRows($redirectRows);

        return [
            'redirects' => $redirectRows,
            'logs' => $logRows,
            'stats' => $this->buildAdminStats($redirectRows, $logRows),
            'targets' => $this->getAdminTargets(),
            'sites' => $this->getAvailableSiteScopes(),
        ];
    }

    public function getRedirectManagerData(): array
    {
        $redirectRows = $this->getRedirectRuleRows();
        $logRows = $this->getNotFoundLogRows($redirectRows);

        return [
            'redirects' => $redirectRows,
            'stats' => $this->buildAdminStats($redirectRows, $logRows),
            'targets' => $this->getAdminTargets(),
            'sites' => $this->getAvailableSiteScopes(),
        ];
    }

    public function getNotFoundMonitorData(): array
    {
        $redirectRows = $this->getRedirectRuleRows();
        $logRows = $this->getNotFoundLogRows($redirectRows);

        return [
            'logs' => $logRows,
            'stats' => $this->buildAdminStats($redirectRows, $logRows),
            'targets' => $this->getAdminTargets(),
            'sites' => $this->getAvailableSiteScopes(),
        ];
    }

    public function saveRedirect(array $post): array
    {
        $id = (int)($post['redirect_id'] ?? 0);
        $source = $this->normalizePath((string)($post['source_path'] ?? ''));
        $siteScope = $this->normalizeSiteScopeInput((string)($post['site_scope'] ?? ''));
        $target = $this->resolveTargetFromPost($post);
        $type = (int)($post['redirect_type'] ?? 301);
        $notes = trim((string)($post['notes'] ?? ''));
        $isActive = isset($post['is_active']) ? 1 : 0;

        if ($source === '' || $source === '/') {
            return [
                'success' => false,
                'error' => 'Bitte einen gültigen Quellpfad angeben.',
                'details' => ['Die Quelle darf nicht leer sein und nicht nur auf / zeigen.'],
            ];
        }
        if ($target === '') {
            return [
                'success' => false,
                'error' => 'Bitte eine Ziel-URL angeben.',
                'details' => $this->buildRedirectDetails($source, '', $siteScope, $type, $notes),
            ];
        }
        if (!in_array($type, [301, 302], true)) {
            $type = 301;
        }
        if (!$this->isValidTarget($target)) {
            return [
                'success' => false,
                'error' => 'Die Ziel-URL ist ungültig.',
                'details' => $this->buildRedirectDetails($source, $target, $siteScope, $type, $notes),
            ];
        }
        if ($this->isSameTargetAsSource($source, $target)) {
            return [
                'success' => false,
                'error' => 'Quelle und Ziel dürfen nicht identisch sein.',
                'details' => $this->buildRedirectDetails($source, $target, $siteScope, $type, $notes),
            ];
        }

        $duplicateRedirect = $this->findRedirectBySourcePath($source, $siteScope);
        if ($duplicateRedirect !== null && (int)($duplicateRedirect['id'] ?? 0) !== $id) {
            return [
                'success' => false,
                'error' => $this->buildDuplicateSourceError($source, $siteScope),
                'details' => $this->buildDuplicateSourceDetails($duplicateRedirect),
            ];
        }

        $data = [
            'source_path' => $source,
            'site_scope' => $siteScope,
            'target_url' => $target,
            'redirect_type' => $type,
            'is_active' => $isActive,
            'notes' => $notes,
        ];

        try {
            if ($id > 0) {
                if ($this->getRedirectById($id) === null) {
                    return [
                        'success' => false,
                        'error' => 'Die ausgewählte Weiterleitung wurde nicht gefunden. Bitte die Seite neu laden und erneut versuchen.',
                        'details' => $this->buildRedirectDetails($source, $target, $siteScope, $type, $notes),
                    ];
                }

                $updated = $this->db->update('redirect_rules', $data, ['id' => $id]);
                if ($updated !== true) {
                    return [
                        'success' => false,
                        'error' => 'Fehler beim Aktualisieren: ' . $this->getLastDatabaseError(),
                        'details' => $this->buildRedirectDetails($source, $target, $siteScope, $type, $notes),
                    ];
                }

                return [
                    'success' => true,
                    'message' => 'Weiterleitung aktualisiert.',
                    'details' => $this->buildRedirectDetails($source, $target, $siteScope, $type, $notes),
                ];
            }

            $insertId = $this->db->insert('redirect_rules', $data);
            if ($insertId === false) {
                return [
                    'success' => false,
                    'error' => 'Fehler beim Speichern: ' . $this->getLastDatabaseError(),
                    'details' => $this->buildRedirectDetails($source, $target, $siteScope, $type, $notes),
                ];
            }

            return [
                'success' => true,
                'message' => 'Weiterleitung angelegt.',
                'details' => $this->buildRedirectDetails($source, $target, $siteScope, $type, $notes),
            ];
        } catch (\Throwable $e) {
            if ($this->isDuplicateRedirectException($e)) {
                return [
                    'success' => false,
                    'error' => $this->buildDuplicateSourceError($source, $siteScope),
                    'details' => $duplicateRedirect !== null ? $this->buildDuplicateSourceDetails($duplicateRedirect) : $this->buildRedirectDetails($source, $target, $siteScope, $type, $notes),
                ];
            }

            return [
                'success' => false,
                'error' => 'Fehler beim Speichern: ' . $e->getMessage(),
                'details' => $this->buildRedirectDetails($source, $target, $siteScope, $type, $notes),
            ];
        }
    }

    public function deleteRedirect(int $id): array
    {
        if ($id <= 0) {
            return ['success' => false, 'error' => 'Ungültige ID.'];
        }
        $this->db->delete('redirect_rules', ['id' => $id]);
        return ['success' => true, 'message' => 'Weiterleitung gelöscht.'];
    }

    public function deleteRedirectsBySlug(string $slug): array
    {
        $normalizedSlug = $this->normalizeSlugFilter($slug);
        if ($normalizedSlug === '') {
            return ['success' => false, 'error' => 'Bitte einen gültigen Slug angeben.'];
        }

        $likePattern = '%' . $normalizedSlug . '%';
        $candidates = $this->db->get_results(
            "SELECT id, source_path
             FROM {$this->prefix}redirect_rules
             WHERE source_path LIKE ?",
            [$likePattern]
        ) ?: [];

        $matchingIds = [];
        foreach ($candidates as $candidate) {
            $sourcePath = (string)($candidate->source_path ?? '');
            if (!$this->pathContainsSlugSegment($sourcePath, $normalizedSlug)) {
                continue;
            }

            $matchingIds[] = (int)($candidate->id ?? 0);
        }

        $matchingIds = array_values(array_filter($matchingIds, static fn(int $id): bool => $id > 0));
        if ($matchingIds === []) {
            return [
                'success' => false,
                'error' => sprintf('Keine Weiterleitungen mit dem Muster */%s/* gefunden.', $normalizedSlug),
            ];
        }

        $placeholders = implode(',', array_fill(0, count($matchingIds), '?'));
        $this->db->execute(
            "DELETE FROM {$this->prefix}redirect_rules WHERE id IN ({$placeholders})",
            $matchingIds
        );

        return [
            'success' => true,
            'message' => sprintf('%d Weiterleitung(en) mit */%s/* wurden gelöscht.', count($matchingIds), $normalizedSlug),
        ];
    }

    public function toggleRedirect(int $id): array
    {
        $row = $this->db->get_row("SELECT is_active FROM {$this->prefix}redirect_rules WHERE id = ? LIMIT 1", [$id]);
        if (!$row) {
            return ['success' => false, 'error' => 'Weiterleitung nicht gefunden.'];
        }

        $newStatus = (int)$row->is_active === 1 ? 0 : 1;
        $this->db->update('redirect_rules', ['is_active' => $newStatus], ['id' => $id]);

        return ['success' => true, 'message' => $newStatus === 1 ? 'Weiterleitung aktiviert.' : 'Weiterleitung deaktiviert.'];
    }

    public function clearLogs(): array
    {
        try {
            $statement = $this->db->execute("DELETE FROM {$this->prefix}not_found_logs");
            $deleted = $statement->rowCount();

            return [
                'success' => true,
                'message' => $deleted > 0
                    ? $deleted . ' 404-Eintrag/Einträge wurden entfernt.'
                    : 'Das 404-Protokoll war bereits leer.',
                'details' => ['Gelöschte 404-Einträge: ' . $deleted],
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'error' => 'Das 404-Protokoll konnte nicht geleert werden.',
                'details' => [$this->limitString($e->getMessage(), 200)],
            ];
        }
    }

    public function createAutomaticRedirect(string $sourcePath, string $targetUrl, string $notes = 'Automatisch bei Slug-Änderung angelegt'): array
    {
        $source = $this->normalizePath($sourcePath);
        $target = trim($targetUrl);

        if ($source === '' || $source === '/' || $target === '') {
            return ['success' => false, 'error' => 'Ungültige Redirect-Daten.'];
        }

        if (!$this->isValidTarget($target)) {
            return ['success' => false, 'error' => 'Ungültige Ziel-URL.'];
        }

        try {
            $existing = $this->db->get_row(
                "SELECT id FROM {$this->prefix}redirect_rules WHERE source_path = ? AND site_scope = ? LIMIT 1",
                [$source, '']
            );

            $payload = [
                'source_path'   => $source,
                'site_scope'    => '',
                'target_url'    => $target,
                'redirect_type' => 301,
                'is_active'     => 1,
                'notes'         => $notes,
            ];

            if ($existing) {
                $this->db->update('redirect_rules', $payload, ['id' => (int)$existing->id]);
                return ['success' => true, 'message' => 'Automatische Weiterleitung aktualisiert.'];
            }

            $this->db->insert('redirect_rules', $payload);
            return ['success' => true, 'message' => 'Automatische Weiterleitung angelegt.'];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => 'Redirect konnte nicht angelegt werden: ' . $e->getMessage()];
        }
    }

    /**
     * Weiterleitungsregeln im Spaltenformat von CSV_REDIRECT_EXPORT_COLUMNS.
     *
     * @return array<int, array<string, string|int>>
     */
    public function getRedirectExportRows(): array
    {
        $rows = $this->db->get_results(
            "SELECT id, source_path, site_scope, target_url, redirect_type, is_active, notes, hits, last_hit_at
             FROM {$this->prefix}redirect_rules
             ORDER BY source_path ASC, site_scope ASC
             LIMIT " . self::CSV_EXPORT_MAX_ROWS
        ) ?: [];

        return array_map(static fn ($row): array => [
            'redirect_id' => (int)($row->id ?? 0),
            'source_path' => (string)($row->source_path ?? ''),
            'site_scope' => (string)($row->site_scope ?? ''),
            'target_url' => (string)($row->target_url ?? ''),
            'redirect_type' => (int)($row->redirect_type ?? 301),
            'is_active' => (int)($row->is_active ?? 0) === 1 ? 1 : 0,
            'notes' => (string)($row->notes ?? ''),
            'hits' => (int)($row->hits ?? 0),
            'last_hit_at' => (string)($row->last_hit_at ?? ''),
        ], $rows);
    }

    /**
     * 404-Einträge im Spaltenformat von CSV_NOT_FOUND_EXPORT_COLUMNS.
     * Die ersten Spalten entsprechen dem Importformat der Weiterleitungen; IP-Adresse und User-Agent werden nicht exportiert.
     *
     * @return array<int, array<string, string|int>>
     */
    public function getNotFoundExportRows(): array
    {
        $redirectsByPath = [];
        foreach ($this->getRedirectRuleRows() as $redirect) {
            $redirectsByPath[$this->normalizePath((string)($redirect['source_path'] ?? ''))][] = $redirect;
        }

        $logs = $this->db->get_results(
            "SELECT request_path, request_host, referrer_url, hit_count, first_seen_at, last_seen_at
             FROM {$this->prefix}not_found_logs
             ORDER BY hit_count DESC, last_seen_at DESC
             LIMIT " . self::CSV_EXPORT_MAX_ROWS
        ) ?: [];

        // Ein Pfad kann für mehrere Hosts protokolliert sein (www/ohne www, Hub-Domains). Zeilen mit gleicher
        // Quelle + Site werden zusammengefasst, damit der Re-Import keine doppelten Schlüssel enthält.
        $rows = [];
        foreach ($logs as $logRow) {
            $requestPath = $this->normalizePath((string)($logRow->request_path ?? ''));
            $log = $this->enrichLogWithRedirect((array)$logRow, $redirectsByPath[$requestPath] ?? []);
            $hasRedirect = (int)($log['redirect_id'] ?? 0) > 0;
            $siteScope = $hasRedirect ? (string)($log['site_scope_match'] ?? '') : (string)($log['site_scope_suggestion'] ?? '');
            $key = $requestPath . '|' . $siteScope;
            $host = (string)($log['request_host'] ?? '');
            $firstSeen = (string)($log['first_seen_at'] ?? '');
            $lastSeen = (string)($log['last_seen_at'] ?? '');

            if (isset($rows[$key])) {
                $row = &$rows[$key];
                $row['hit_count'] += (int)($log['hit_count'] ?? 0);
                if ($host !== '' && !in_array($host, explode(', ', (string)$row['request_host']), true)) {
                    $row['request_host'] = ltrim($row['request_host'] . ', ' . $host, ', ');
                }
                if ($firstSeen !== '' && ($row['first_seen_at'] === '' || $firstSeen < $row['first_seen_at'])) {
                    $row['first_seen_at'] = $firstSeen;
                }
                if ($lastSeen > $row['last_seen_at']) {
                    $row['last_seen_at'] = $lastSeen;
                }
                if ($row['referrer_url'] === '') {
                    $row['referrer_url'] = (string)($log['referrer_url'] ?? '');
                }
                unset($row);
                continue;
            }

            $rows[$key] = [
                'redirect_id' => $hasRedirect ? (int)$log['redirect_id'] : '',
                'source_path' => $requestPath,
                'site_scope' => $siteScope,
                'target_url' => $hasRedirect ? (string)($log['target_url'] ?? '') : '',
                'redirect_type' => $hasRedirect ? (int)($log['redirect_type'] ?? 301) : 301,
                'is_active' => $hasRedirect ? ((int)($log['redirect_is_active'] ?? 0) === 1 ? 1 : 0) : 1,
                'notes' => $hasRedirect ? (string)($log['redirect_notes'] ?? '') : 'Aus 404-Monitor übernommen',
                'request_host' => $host,
                'hit_count' => (int)($log['hit_count'] ?? 0),
                'first_seen_at' => $firstSeen,
                'last_seen_at' => $lastSeen,
                'referrer_url' => (string)($log['referrer_url'] ?? ''),
            ];
        }

        $rows = array_values($rows);
        usort($rows, static fn (array $a, array $b): int => [$b['hit_count'], $b['last_seen_at']] <=> [$a['hit_count'], $a['last_seen_at']]);

        return $rows;
    }

    /**
     * Importiert Weiterleitungen aus einer CSV-Datei (Export der Weiterleitungen oder des 404-Monitors).
     * Zeilen ohne Ziel werden übersprungen; bestehende Regeln werden über redirect_id oder Quelle + Site-Scope aktualisiert.
     *
     * @return array{success: bool, message?: string, error?: string, details: array<int, string>, summary: array<string, int>}
     */
    public function importRedirectsFromCsv(string $csvContent): array
    {
        $summary = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => 0, 'failed' => 0];

        try {
            $records = CsvExchange::parse(
                $csvContent,
                self::CSV_HEADER_ALIASES,
                ['source_path', 'target_url'],
                self::CSV_IMPORT_MAX_ROWS,
                implode(';', self::CSV_IMPORT_COLUMNS)
            );
        } catch (\InvalidArgumentException $e) {
            return ['success' => false, 'error' => $e->getMessage(), 'details' => [], 'summary' => $summary];
        }

        $errors = [];
        $seenKeys = [];
        $fail = static function (int $line, string $source, string $message) use (&$summary, &$errors): void {
            $summary['failed']++;
            $errors[] = sprintf('Zeile %d%s: %s', $line, $source !== '' ? ' (' . $source . ')' : '', $message);
        };

        // Eine Transaktion statt Autocommit je Zeile beschleunigt große Importe deutlich.
        $pdo = $this->db->getPdo();
        $ownsTransaction = !$pdo->inTransaction() && $pdo->beginTransaction();

        try {
            foreach ($records as $line => $record) {
                $rawSource = trim((string)($record['source_path'] ?? ''));
                $rawTarget = trim((string)($record['target_url'] ?? ''));

                if ($rawTarget === '') {
                    $summary['skipped']++;
                    continue;
                }

                $source = $this->normalizePath($rawSource);
                if ($source === '' || $source === '/') {
                    $fail($line, $rawSource, 'Bitte einen gültigen Quellpfad angeben.');
                    continue;
                }

                $existing = null;
                $rawId = trim((string)($record['redirect_id'] ?? ''));
                if ($rawId !== '') {
                    $id = filter_var($rawId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                    if ($id === false) {
                        $fail($line, $source, 'Ungültige redirect_id „' . $this->limitString($rawId, 40) . '“.');
                        continue;
                    }
                    $existing = $this->getRedirectById((int)$id);
                }

                $siteScope = array_key_exists('site_scope', $record)
                    ? $this->normalizeSiteScopeInput((string)$record['site_scope'])
                    : $this->normalizeSiteScopeInput((string)($existing['site_scope'] ?? ''));

                if ($existing === null) {
                    $match = $this->findRedirectBySourcePath($source, $siteScope);
                    $existing = $match !== null ? $this->getRedirectById((int)($match['id'] ?? 0)) : null;
                }

                $rawType = trim((string)($record['redirect_type'] ?? ''));
                $type = $rawType !== '' ? (int)$rawType : (int)($existing['redirect_type'] ?? 301);
                if (!in_array($type, [301, 302], true)) {
                    $fail($line, $source, 'Typ muss 301 oder 302 sein.');
                    continue;
                }

                $rawActive = trim((string)($record['is_active'] ?? ''));
                $isActive = $rawActive !== '' ? CsvExchange::parseBoolean($rawActive) : ($existing === null || (int)($existing['is_active'] ?? 0) === 1);
                if ($isActive === null) {
                    $fail($line, $source, 'Ungültiger Wert für is_active (erlaubt: 1/0, ja/nein).');
                    continue;
                }

                $notes = array_key_exists('notes', $record) ? trim((string)$record['notes']) : (string)($existing['notes'] ?? '');
                if ($existing === null && $notes === '') {
                    $notes = 'Per CSV-Import angelegt';
                }

                if (!str_starts_with($rawTarget, '/') && preg_match('#^https?://#i', $rawTarget) !== 1 && preg_match('#^[a-z][a-z0-9+.\-]*:#i', $rawTarget) === 1) {
                    $fail($line, $source, 'Das Ziel muss ein Pfad/Slug oder eine http(s)-URL sein.');
                    continue;
                }

                $target = $this->normalizeManualTarget($rawTarget);

                // Doppelte Zeilen mit identischen Werten (z. B. aus dem 404-Export) sind unkritisch, abweichende Werte nicht.
                $key = $source . '|' . $siteScope;
                $signature = $target . '|' . $type . '|' . ($isActive ? '1' : '0') . '|' . $notes;
                if (isset($seenKeys[$key])) {
                    if ($seenKeys[$key]['signature'] === $signature) {
                        $summary['unchanged']++;
                    } else {
                        $fail($line, $source, sprintf('Quelle und Site sind bereits in Zeile %d mit anderen Werten enthalten.', $seenKeys[$key]['line']));
                    }
                    continue;
                }
                $seenKeys[$key] = ['line' => $line, 'signature' => $signature];

                if ($existing !== null
                    && (string)($existing['source_path'] ?? '') === $source
                    && $this->normalizeSiteScopeInput((string)($existing['site_scope'] ?? '')) === $siteScope
                    && (string)($existing['target_url'] ?? '') === $target
                    && (int)($existing['redirect_type'] ?? 301) === $type
                    && ((int)($existing['is_active'] ?? 0) === 1) === $isActive
                    && trim((string)($existing['notes'] ?? '')) === $notes
                ) {
                    $summary['unchanged']++;
                    continue;
                }

                $post = [
                    'redirect_id' => $existing !== null ? (int)($existing['id'] ?? 0) : 0,
                    'source_path' => $source,
                    'site_scope' => $siteScope,
                    'target_kind' => 'manual',
                    'target_url_manual' => $target,
                    'redirect_type' => (string)$type,
                    'notes' => $notes,
                ];
                if ($isActive) {
                    $post['is_active'] = '1';
                }

                $result = $this->saveRedirect($post);
                if (empty($result['success'])) {
                    $fail($line, $source, (string)($result['error'] ?? 'Unbekannter Fehler.'));
                    continue;
                }

                $summary[$existing !== null ? 'updated' : 'created']++;
            }
        } catch (\Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->commit();
        }

        $summaryText = sprintf(
            '%d angelegt, %d aktualisiert, %d unverändert, %d ohne Ziel übersprungen, %d fehlerhaft.',
            $summary['created'],
            $summary['updated'],
            $summary['unchanged'],
            $summary['skipped'],
            $summary['failed']
        );

        $details = array_slice($errors, 0, self::CSV_IMPORT_MAX_ERROR_DETAILS);
        if (count($errors) > self::CSV_IMPORT_MAX_ERROR_DETAILS) {
            $details[] = sprintf('… und %d weitere fehlerhafte Zeile(n).', count($errors) - self::CSV_IMPORT_MAX_ERROR_DETAILS);
        }

        if ($summary['failed'] > 0) {
            return [
                'success' => false,
                'error' => 'CSV-Import mit Fehlern abgeschlossen: ' . $summaryText,
                'type' => ($summary['created'] + $summary['updated']) > 0 ? 'warning' : 'danger',
                'details' => $details,
                'summary' => $summary,
            ];
        }

        return [
            'success' => true,
            'message' => 'CSV-Import abgeschlossen: ' . $summaryText,
            'details' => [],
            'summary' => $summary,
        ];
    }

    private function normalizePath(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '';
        }

        if (($pos = strpos($path, '?')) !== false) {
            $path = substr($path, 0, $pos);
        }

        if (filter_var($path, FILTER_VALIDATE_URL)) {
            $path = (string)parse_url($path, PHP_URL_PATH);
        }

        $path = '/' . ltrim($path, '/');
        if ($path !== '/') {
            $path = rtrim($path, '/');
        }

        return $path;
    }

    private function normalizeHost(string $host): string
    {
        $host = trim(strtolower($host));
        if ($host === '') {
            return '';
        }

        if (str_contains($host, ':')) {
            $host = explode(':', $host, 2)[0];
        }

        return trim($host, '.');
    }

    private function shouldIgnorePath(string $path): bool
    {
        if ($path === '' || $path === '/') {
            return true;
        }

        foreach (['/admin', '/member', '/api', '/assets', '/uploads', '/vendor'] as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                return true;
            }
        }

        if (preg_match('/\.(css|js|map|png|jpe?g|gif|svg|webp|ico|woff2?|ttf|eot|pdf|xml|txt)$/i', $path) === 1) {
            return true;
        }

        return in_array($path, ['/favicon.ico', '/robots.txt', '/sitemap.xml'], true);
    }

    private function resolveTargetFromPost(array $post): string
    {
        $targetKind = strtolower(trim((string)($post['target_kind'] ?? 'manual')));

        return match ($targetKind) {
            'page' => $this->getPageTargetUrl((int)($post['target_page_id'] ?? 0)),
            'post' => $this->getPostTargetUrl((int)($post['target_post_id'] ?? 0)),
            'hub' => $this->getHubTargetUrl((int)($post['target_hub_id'] ?? 0)),
            default => $this->normalizeManualTarget((string)(
                trim((string)($post['target_url_manual'] ?? '')) !== ''
                    ? $post['target_url_manual']
                    : ($post['target_url'] ?? '')
            )),
        };
    }

    private function isValidTarget(string $target): bool
    {
        return str_starts_with($target, '/') || filter_var($target, FILTER_VALIDATE_URL) !== false;
    }

    private function isSameTargetAsSource(string $source, string $target): bool
    {
        if (!str_starts_with($target, '/')) {
            return false;
        }

        return $this->normalizePath($target) === $source;
    }

    private function normalizeManualTarget(string $target): string
    {
        $target = trim($target);
        if ($target === '') {
            return '';
        }

        if (filter_var($target, FILTER_VALIDATE_URL) !== false) {
            return $target;
        }

        return $this->normalizePath($target);
    }

    private function normalizeSiteScopeInput(string $value): string
    {
        $value = trim($value);
        if ($value === '' || $value === 'global') {
            return '';
        }

        if (str_starts_with($value, 'host:')) {
            $host = $this->normalizeHost(substr($value, 5));
            return $host !== '' ? 'host:' . $host : '';
        }

        if (str_starts_with($value, 'path:')) {
            $path = $this->normalizePath(substr($value, 5));
            return $path !== '' && $path !== '/' ? 'path:' . $path : '';
        }

        if (preg_match('#^https?://#i', $value) === 1 || str_contains($value, '.')) {
            $host = $this->normalizeHost((string)(parse_url(preg_match('#^https?://#i', $value) === 1 ? $value : 'https://' . $value, PHP_URL_HOST) ?? $value));
            return $host !== '' ? 'host:' . $host : '';
        }

        $path = $this->normalizePath($value);
        return $path !== '' && $path !== '/' ? 'path:' . $path : '';
    }

    private function findRedirectBySourcePath(string $sourcePath, string $siteScope = ''): ?array
    {
        if ($sourcePath === '') {
            return null;
        }

        $siteScope = $this->normalizeSiteScopeInput($siteScope);

        $row = $this->db->get_row(
            "SELECT id, source_path, site_scope, target_url, is_active
             FROM {$this->prefix}redirect_rules
             WHERE source_path = ?
               AND site_scope = ?
             LIMIT 1",
            [$sourcePath, $siteScope]
        );

        return $row ? (array)$row : null;
    }

    private function getRedirectById(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        $row = $this->db->get_row(
            "SELECT id, source_path, site_scope, target_url, redirect_type, is_active, notes
             FROM {$this->prefix}redirect_rules
             WHERE id = ?
             LIMIT 1",
            [$id]
        );

        return $row ? (array)$row : null;
    }

    /**
     * @return array<int, string>
     */
    private function buildRedirectDetails(string $source, string $target, string $siteScope, int $type, string $notes = ''): array
    {
        $details = [];

        if ($source !== '') {
            $details[] = 'Quelle: ' . $source;
        }

        if ($target !== '') {
            $details[] = 'Ziel: ' . $target;
        }

        $details[] = 'Site: ' . $this->describeSiteScope($siteScope);
        $details[] = 'Typ: ' . (in_array($type, [301, 302], true) ? (string)$type : '301');

        $notes = trim($notes);
        if ($notes !== '') {
            $details[] = 'Notiz: ' . $this->limitString($notes, 160);
        }

        return $details;
    }

    /**
     * @param array<string, mixed> $redirect
     * @return array<int, string>
     */
    private function buildDuplicateSourceDetails(array $redirect): array
    {
        return $this->buildRedirectDetails(
            (string)($redirect['source_path'] ?? ''),
            (string)($redirect['target_url'] ?? ''),
            (string)($redirect['site_scope'] ?? ''),
            (int)($redirect['redirect_type'] ?? 301),
            (string)($redirect['notes'] ?? '')
        );
    }

    private function getRedirectSiteScore(array $redirect, string $requestPath, string $requestHost): int
    {
        $siteScope = $this->normalizeSiteScopeInput((string)($redirect['site_scope'] ?? ''));
        if ($siteScope === '') {
            return 10;
        }

        if (str_starts_with($siteScope, 'host:')) {
            return $requestHost !== '' && $this->normalizeHost(substr($siteScope, 5)) === $requestHost ? 100 : -1;
        }

        if (str_starts_with($siteScope, 'path:')) {
            $scopePath = $this->normalizePath(substr($siteScope, 5));
            if ($scopePath === '' || $scopePath === '/') {
                return -1;
            }

            if ($requestPath === $scopePath || str_starts_with($requestPath, $scopePath . '/')) {
                return 80 + strlen($scopePath);
            }
        }

        return -1;
    }

    private function enrichLogWithRedirect(array $log, array $redirects): array
    {
        $requestPath = $this->normalizePath((string)($log['request_path'] ?? ''));
        $requestHost = $this->normalizeHost((string)($log['request_host'] ?? ''));
        $bestMatch = null;
        $bestScore = -1;

        foreach ($redirects as $redirect) {
            if ($this->normalizePath((string)($redirect['source_path'] ?? '')) !== $requestPath) {
                continue;
            }

            $score = $this->getRedirectSiteScore($redirect, $requestPath, $requestHost);
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestMatch = $redirect;
            }
        }

        $log['redirect_id'] = (int)($bestMatch['id'] ?? 0);
        $log['target_url'] = (string)($bestMatch['target_url'] ?? '');
        $log['redirect_type'] = (int)($bestMatch['redirect_type'] ?? 0);
        $log['redirect_notes'] = (string)($bestMatch['notes'] ?? '');
        $log['redirect_is_active'] = (int)($bestMatch['is_active'] ?? 0);
        $log['site_scope_match'] = (string)($bestMatch['site_scope'] ?? '');
        $log['request_host'] = $requestHost;
        $log['request_host_label'] = $requestHost !== '' ? $requestHost : 'Hauptsite / unbekannter Host';
        $log['site_scope_suggestion'] = $this->suggestSiteScopeForRequest($requestPath, $requestHost);

        return $log;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getRedirectRuleRows(): array
    {
        $redirects = $this->db->get_results(
            "SELECT * FROM {$this->prefix}redirect_rules ORDER BY is_active DESC, updated_at DESC, source_path ASC"
        ) ?: [];

        return array_map(function ($row): array {
            $mappedRow = (array) $row;
            $mappedRow['site_scope'] = (string) ($mappedRow['site_scope'] ?? '');
            $mappedRow['site_scope_label'] = $this->describeSiteScope((string) ($mappedRow['site_scope'] ?? ''));

            return $mappedRow;
        }, $redirects);
    }

    /**
     * @param array<int, array<string, mixed>> $redirectRows
     * @return array<int, array<string, mixed>>
     */
    private function getNotFoundLogRows(array $redirectRows): array
    {
        $logs = $this->db->get_results(
            "SELECT *
             FROM {$this->prefix}not_found_logs
             ORDER BY last_seen_at DESC
             LIMIT 200"
        ) ?: [];

        return array_map(fn($row): array => $this->enrichLogWithRedirect((array) $row, $redirectRows), $logs);
    }

    /**
     * @param array<int, array<string, mixed>> $redirectRows
     * @param array<int, array<string, mixed>> $logRows
     * @return array<string, int>
     */
    private function buildAdminStats(array $redirectRows, array $logRows): array
    {
        $redirectsTotal = count($redirectRows);
        $redirectsActive = count(array_filter($redirectRows, static fn(array $row): bool => (int) ($row['is_active'] ?? 0) === 1));
        $notFoundTotal = count($logRows);
        $notFoundHits = array_sum(array_map(static fn(array $row): int => (int) ($row['hit_count'] ?? 0), $logRows));

        try {
            $redirectsTotal = (int)$this->db->get_var("SELECT COUNT(*) FROM {$this->prefix}redirect_rules");
            $redirectsActive = (int)$this->db->get_var("SELECT COUNT(*) FROM {$this->prefix}redirect_rules WHERE is_active = 1");
            $notFoundTotal = (int)$this->db->get_var("SELECT COUNT(*) FROM {$this->prefix}not_found_logs");
            $notFoundHits = (int)$this->db->get_var("SELECT COALESCE(SUM(hit_count), 0) FROM {$this->prefix}not_found_logs");
        } catch (\Throwable) {
            // Fallback auf die bereits geladenen Listen, damit die Admin-Ansicht fail-soft bleibt.
        }

        return [
            'redirects_total' => $redirectsTotal,
            'redirects_active' => $redirectsActive,
            'not_found_total' => $notFoundTotal,
            'not_found_hits' => $notFoundHits,
        ];
    }

    /**
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function getAdminTargets(): array
    {
        return [
            'pages' => $this->getAvailablePageTargets(),
            'posts' => $this->getAvailablePostTargets(),
            'hubs' => $this->getAvailableHubTargets(),
        ];
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function getAvailableSiteScopes(): array
    {
        if ($this->availableSiteScopes !== null) {
            return $this->availableSiteScopes;
        }

        $options = [
            [
                'value' => '',
                'label' => 'Global / alle Sites',
                'description' => 'Regel gilt host- und siteübergreifend.',
            ],
        ];

        $mainHost = $this->normalizeHost((string)(parse_url((string)SITE_URL, PHP_URL_HOST) ?? ''));
        if ($mainHost !== '') {
            $options[] = [
                'value' => 'host:' . $mainHost,
                'label' => 'Hauptsite (' . $mainHost . ')',
                'description' => 'Nur für die primäre Domain.',
            ];
        }

        $seen = array_fill_keys(array_map(static fn(array $option): string => $option['value'], $options), true);
        foreach ($this->loadHubSiteScopeOptions() as $option) {
            $value = (string)($option['value'] ?? '');
            if ($value === '' || isset($seen[$value])) {
                continue;
            }

            $seen[$value] = true;
            $options[] = $option;
        }

        return $this->availableSiteScopes = $options;
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function loadHubSiteScopeOptions(): array
    {
        $selectSlug = $this->hasSiteTableSlugColumn() ? 'table_slug,' : "'' AS table_slug,";
        $rows = $this->db->get_results(
            "SELECT table_name, {$selectSlug} settings_json,
                    JSON_UNQUOTE(JSON_EXTRACT(settings_json, '$.hub_slug')) AS hub_slug
             FROM {$this->prefix}site_tables
             WHERE COALESCE(JSON_UNQUOTE(JSON_EXTRACT(settings_json, '$.content_mode')), 'table') = 'hub'
             ORDER BY table_name ASC"
        ) ?: [];

        $options = [];
        foreach ($rows as $row) {
            $label = trim((string)($row->table_name ?? 'Hub-Site'));
            $slug = trim((string)((($row->hub_slug ?? '') !== '') ? $row->hub_slug : ($row->table_slug ?? '')));
            if ($slug !== '') {
                $options[] = [
                    'value' => 'path:/' . ltrim($slug, '/'),
                    'label' => $label . ' (Pfad /' . ltrim($slug, '/') . ')',
                    'description' => 'Greift für Pfade unterhalb dieser Hub-Site.',
                ];
            }

            $settings = json_decode((string)($row->settings_json ?? ''), true);
            $domains = is_array($settings['hub_domains'] ?? null) ? $settings['hub_domains'] : [];
            foreach ($domains as $domain) {
                $host = $this->normalizeHost((string)$domain);
                if ($host === '') {
                    continue;
                }

                $options[] = [
                    'value' => 'host:' . $host,
                    'label' => $label . ' (' . $host . ')',
                    'description' => 'Nur für die zugewiesene Hub-Domain.',
                ];
            }
        }

        return $options;
    }

    private function suggestSiteScopeForRequest(string $requestPath, string $requestHost): string
    {
        foreach ($this->getAvailableSiteScopes() as $option) {
            $value = (string)($option['value'] ?? '');
            if ($value === '') {
                continue;
            }

            if ($this->getRedirectSiteScore(['site_scope' => $value], $requestPath, $requestHost) >= 0) {
                return $value;
            }
        }

        return $requestHost !== '' ? 'host:' . $requestHost : '';
    }

    private function describeSiteScope(string $siteScope): string
    {
        $siteScope = $this->normalizeSiteScopeInput($siteScope);
        if ($siteScope === '') {
            return 'Global / alle Sites';
        }

        if (str_starts_with($siteScope, 'host:')) {
            return 'Host: ' . substr($siteScope, 5);
        }

        if (str_starts_with($siteScope, 'path:')) {
            return 'Pfadbereich: ' . substr($siteScope, 5);
        }

        return $siteScope;
    }

    private function ensureColumnExists(string $table, string $column, string $definition): void
    {
        $allowedDefinitions = [
            'redirect_rules.site_scope' => "VARCHAR(255) NOT NULL DEFAULT '' AFTER source_path",
            'not_found_logs.request_host' => "VARCHAR(255) NOT NULL DEFAULT '' AFTER request_path",
        ];
        $schemaKey = $table . '.' . $column;
        if (!isset($allowedDefinitions[$schemaKey]) || $allowedDefinitions[$schemaKey] !== $definition) {
            return;
        }

        try {
            if (!$this->db->columnExists($this->prefix . $table, $column)) {
                $this->db->getPdo()->exec("ALTER TABLE {$this->prefix}{$table} ADD COLUMN {$column} {$definition}");
            }
        } catch (\Throwable) {
            // Schema-Upgrade darf bestehende Instanzen nicht blockieren.
        }
    }

    private function ensureRedirectIndexes(): void
    {
        try {
            $indexes = $this->db->get_results("SHOW INDEX FROM {$this->prefix}redirect_rules") ?: [];
            $hasComposite = false;
            $hasLegacy = false;

            foreach ($indexes as $index) {
                $keyName = (string)($index->Key_name ?? '');
                if ($keyName === 'idx_source_path_scope') {
                    $hasComposite = true;
                }
                if ($keyName === 'idx_source_path') {
                    $hasLegacy = true;
                }
            }

            if ($hasLegacy) {
                $this->db->getPdo()->exec("ALTER TABLE {$this->prefix}redirect_rules DROP INDEX idx_source_path");
            }

            if (!$hasComposite) {
                $this->db->getPdo()->exec("ALTER TABLE {$this->prefix}redirect_rules ADD UNIQUE INDEX idx_source_path_scope (source_path, site_scope)");
            }
        } catch (\Throwable) {
            // Bestehende Installationen dürfen auch dann weiterlaufen, wenn ein Index-Upgrade scheitert.
        }
    }

    private function buildDuplicateSourceError(string $sourcePath, string $siteScope = ''): string
    {
        $siteScope = $this->normalizeSiteScopeInput($siteScope);
        $duplicateRedirect = $this->findRedirectBySourcePath($sourcePath, $siteScope);
        if ($duplicateRedirect === null) {
            return sprintf(
                'Für die Quelle %s existiert bereits eine Weiterleitung%s. Bitte vorhandene Regel bearbeiten statt doppelt anzulegen.',
                $sourcePath,
                $siteScope !== '' ? ' für ' . $this->describeSiteScope($siteScope) : ''
            );
        }

        $duplicateTarget = trim((string)($duplicateRedirect['target_url'] ?? ''));
        $duplicateStatus = (int)($duplicateRedirect['is_active'] ?? 0) === 1 ? 'aktive' : 'inaktive';
        $duplicateScope = $this->describeSiteScope((string)($duplicateRedirect['site_scope'] ?? $siteScope));

        if ($duplicateTarget !== '') {
            return sprintf(
                'Für die Quelle %s existiert bereits eine %s Weiterleitung für %s nach %s. Bitte vorhandene Regel bearbeiten statt doppelt anzulegen.',
                $sourcePath,
                $duplicateStatus,
                $duplicateScope,
                $duplicateTarget
            );
        }

        return sprintf(
            'Für die Quelle %s existiert bereits eine %s Weiterleitung für %s. Bitte vorhandene Regel bearbeiten statt doppelt anzulegen.',
            $sourcePath,
            $duplicateStatus,
            $duplicateScope
        );
    }

    private function isDuplicateRedirectException(\Throwable $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'duplicate entry')
            || str_contains($message, 'idx_source_path')
            || str_contains($message, 'sqlstate[23000]');
    }

    private function getLastDatabaseError(): string
    {
        $message = trim((string)($this->db->last_error ?? ''));
        return $message !== '' ? $message : 'Unbekannter Datenbankfehler.';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getAvailablePageTargets(): array
    {
        $rows = $this->db->get_results(
            "SELECT id, title, slug
             FROM {$this->prefix}pages
             WHERE status = 'published' AND slug IS NOT NULL AND slug != ''
             ORDER BY title ASC
             LIMIT 500"
        ) ?: [];

        return array_map(function ($row): array {
            $title = trim((string)($row->title ?? ''));
            $slug = trim((string)($row->slug ?? ''));

            return [
                'id' => (int)($row->id ?? 0),
                'label' => $title !== '' ? $title : $slug,
                'slug' => $slug,
                'url' => '/' . ltrim($slug, '/'),
            ];
        }, $rows);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getAvailablePostTargets(): array
    {
        $rows = $this->db->get_results(
            "SELECT id, title, slug, published_at, created_at
             FROM {$this->prefix}posts
             WHERE " . \cms_post_publication_where() . " AND slug IS NOT NULL AND slug != ''
             ORDER BY title ASC
             LIMIT 500"
        ) ?: [];

        return array_map(function ($row): array {
            $title = trim((string)($row->title ?? ''));
            $slug = trim((string)($row->slug ?? ''));

            return [
                'id' => (int)($row->id ?? 0),
                'label' => $title !== '' ? $title : $slug,
                'slug' => $slug,
                'url' => class_exists('CMS\\Services\\PermalinkService')
                    ? PermalinkService::getInstance()->buildPostPathFromValues(
                        $slug,
                        (string)($row->published_at ?? ''),
                        (string)($row->created_at ?? '')
                    )
                    : '/blog/' . ltrim($slug, '/'),
            ];
        }, $rows);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getAvailableHubTargets(): array
    {
        $selectSlug = $this->hasSiteTableSlugColumn() ? 'table_slug,' : "'' AS table_slug,";

        $rows = $this->db->get_results(
            "SELECT id, table_name, {$selectSlug}
                    JSON_UNQUOTE(JSON_EXTRACT(settings_json, '$.hub_slug')) AS hub_slug
             FROM {$this->prefix}site_tables
             WHERE COALESCE(JSON_UNQUOTE(JSON_EXTRACT(settings_json, '$.content_mode')), 'table') = 'hub'
             ORDER BY table_name ASC
             LIMIT 300"
        ) ?: [];

        return array_map(function ($row): array {
            $slug = trim((string)(($row->hub_slug ?? '') !== '' ? $row->hub_slug : ($row->table_slug ?? '')));
            $label = trim((string)($row->table_name ?? ''));

            return [
                'id' => (int)($row->id ?? 0),
                'label' => $label !== '' ? $label : $slug,
                'slug' => $slug,
                'url' => '/' . ltrim($slug, '/'),
            ];
        }, array_filter($rows, static function ($row): bool {
            $hubSlug = trim((string)(($row->hub_slug ?? '') !== '' ? $row->hub_slug : ($row->table_slug ?? '')));
            return $hubSlug !== '';
        }));
    }

    private function getPageTargetUrl(int $id): string
    {
        if ($id <= 0) {
            return '';
        }

        $slug = (string)$this->db->get_var(
            "SELECT slug FROM {$this->prefix}pages WHERE id = ? AND status = 'published' LIMIT 1",
            [$id]
        );

        return $slug !== '' ? '/' . ltrim($slug, '/') : '';
    }

    private function getPostTargetUrl(int $id): string
    {
        if ($id <= 0) {
            return '';
        }

        $post = $this->db->get_row(
            "SELECT slug, published_at, created_at FROM {$this->prefix}posts WHERE id = ? AND " . \cms_post_publication_where() . " LIMIT 1",
            [$id]
        );

        $slug = trim((string)($post->slug ?? ''));

        if ($slug === '') {
            return '';
        }

        return class_exists('CMS\\Services\\PermalinkService')
            ? PermalinkService::getInstance()->buildPostPathFromValues(
                $slug,
                (string)($post->published_at ?? ''),
                (string)($post->created_at ?? '')
            )
            : '/blog/' . ltrim($slug, '/');
    }

    private function getHubTargetUrl(int $id): string
    {
        if ($id <= 0) {
            return '';
        }

        $selectSlug = $this->hasSiteTableSlugColumn() ? 'table_slug,' : "'' AS table_slug,";
        $row = $this->db->get_row(
            "SELECT {$selectSlug}
                    JSON_UNQUOTE(JSON_EXTRACT(settings_json, '$.hub_slug')) AS hub_slug
             FROM {$this->prefix}site_tables
             WHERE id = ?
               AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(settings_json, '$.content_mode')), 'table') = 'hub'
             LIMIT 1",
            [$id]
        );

        if (!$row) {
            return '';
        }

        $slug = trim((string)((($row->hub_slug ?? '') !== '') ? $row->hub_slug : ($row->table_slug ?? '')));

        return $slug !== '' ? '/' . ltrim($slug, '/') : '';
    }

    private function hasSiteTableSlugColumn(): bool
    {
        if ($this->hasSiteTableSlugColumn !== null) {
            return $this->hasSiteTableSlugColumn;
        }

        try {
            $column = $this->db->get_var("SHOW COLUMNS FROM {$this->prefix}site_tables LIKE 'table_slug'");
            $this->hasSiteTableSlugColumn = $column !== null;
        } catch (\Throwable) {
            $this->hasSiteTableSlugColumn = false;
        }

        return $this->hasSiteTableSlugColumn;
    }

    private function limitString(string $value, int $length): string
    {
        return mb_substr(trim($value), 0, $length);
    }

    private function normalizeSlugFilter(string $slug): string
    {
        $slug = trim($slug);
        $slug = trim($slug, " \t\n\r\0\x0B/");
        $slug = strtolower($slug);
        $slug = preg_replace('/[^a-z0-9\-_]+/i', '-', $slug) ?? '';
        $slug = trim($slug, '-');

        return $slug;
    }

    private function pathContainsSlugSegment(string $path, string $slug): bool
    {
        $normalizedPath = trim($this->normalizePath($path), '/');
        if ($normalizedPath === '') {
            return false;
        }

        $segments = array_values(array_filter(explode('/', strtolower($normalizedPath)), static fn(string $segment): bool => $segment !== ''));
        return in_array(strtolower($slug), $segments, true);
    }
}
