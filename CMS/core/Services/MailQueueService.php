<?php
/**
 * Mail-Queue-Service für asynchronen Versand, Cron-Verarbeitung und Retry-Backoff.
 *
 * @package CMSv2\Core\Services
 */

declare(strict_types=1);

namespace CMS\Services;

use CMS\Database;
use CMS\Logger;

if (!defined('ABSPATH')) {
    exit;
}

class MailQueueService
{
    public const CRON_HOOK = 'cms_cron_mail_queue';

    private const GROUP = 'mail';
    private const SETTINGS_LAST_RUN = 'queue_last_run';
    private const SETTINGS_RATE_WINDOW = 'queue_rate_window';
    private const DEFAULT_RATE_LIMIT_PER_MINUTE = 8;
    private const MAX_SUBJECT_LENGTH = 255;

    /**
     * Release-Prüfung 4.1.0 (PERF, batch:1): Quellen für Massenversand (Newsletter, Feed-Digest). Ihre Jobs werden
     * erst nach allen anderen fälligen Jobs abgearbeitet, damit Passwort-Reset, Registrierung, E-Mail-Änderung
     * und MFA-Hinweise nicht hinter einer laufenden Kampagne warten. Die Werte entsprechen
     * NewsletterMailer::MAIL_SOURCE, DigestMailer::MAIL_SOURCE und (ab 4.2.0) NotificationDispatcher::MAIL_SOURCE
     * der Community.
     */
    public const BULK_SOURCES = ['newsletter', 'cms-feed-digest', 'community'];

    /**
     * Release-Prüfung 4.1.0 (PERF, batch:0): Standardfrist für erledigte Jobs (versendet oder endgültig
     * fehlgeschlagen). Offene und laufende Jobs werden nie gelöscht.
     */
    public const RETENTION_DAYS = 30;

    /**
     * Release-Prüfung 4.1.0 (PERF, batch:0): Bedingung für löschbare Jobs: nur versendete oder endgültig
     * fehlgeschlagene, älter als die Frist (über den Index idx_created_at).
     */
    private const PRUNABLE_WHERE = "status IN ('sent', 'failed') AND created_at < (NOW() - INTERVAL ? DAY)";

    private static ?self $instance = null;

    private Database $db;
    private SettingsService $settings;
    private Logger $logger;
    private string $prefix;

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    private function __construct()
    {
        $this->db = Database::instance();
        $this->settings = SettingsService::getInstance();
        $this->logger = Logger::instance()->withChannel('mail.queue');
        $this->prefix = $this->db->getPrefix();
    }

    /**
     * @return array<string, bool|int|string|array<string, mixed>|null>
     */
    public function getConfiguration(): array
    {
        $token = $this->settings->getString(self::GROUP, 'queue_cron_token');
        if ($token === '') {
            $token = bin2hex(random_bytes(24));
            $this->settings->set(self::GROUP, 'queue_cron_token', $token, true, 0);
        }

        $batchSize = min(100, max(1, $this->settings->getInt(self::GROUP, 'queue_batch_size', 10)));
        $maxAttempts = min(20, max(1, $this->settings->getInt(self::GROUP, 'queue_max_attempts', 5)));
        $retryDelay = min(86400, max(60, $this->settings->getInt(self::GROUP, 'queue_retry_delay_seconds', 300)));
        $throttleDelay = min(86400, max(60, $this->settings->getInt(self::GROUP, 'queue_throttle_delay_seconds', 900)));
        $lockTimeout = min(86400, max(60, $this->settings->getInt(self::GROUP, 'queue_lock_timeout_seconds', 900)));
        $rateLimitPerMinute = min(600, max(1, $this->settings->getInt(self::GROUP, 'queue_rate_limit_per_minute', self::DEFAULT_RATE_LIMIT_PER_MINUTE)));
        $lastRun = $this->settings->get(self::GROUP, self::SETTINGS_LAST_RUN, []);
        $cronFilePath = ABSPATH . 'cron.php';
        $cronWebPath = '/cron.php';
        $siteUrl = defined('SITE_URL') ? rtrim((string) SITE_URL, '/') : '';
        $cronUrl = $siteUrl . $cronWebPath . '?task=mail-queue&quiet=1';
        $cronUrlWithToken = $cronUrl . '&token=' . rawurlencode($token);

        return [
            'enabled' => $this->settings->getBool(self::GROUP, 'queue_enabled', true),
            'batch_size' => $batchSize,
            'max_attempts' => $maxAttempts,
            'retry_delay_seconds' => $retryDelay,
            'throttle_delay_seconds' => $throttleDelay,
            'lock_timeout_seconds' => $lockTimeout,
            'rate_limit_per_minute' => $rateLimitPerMinute,
            'cron_token' => $token,
            'cron_url' => $cronUrl,
            'cron_url_with_token' => $cronUrlWithToken,
            'cron_header_name' => 'X-CMS-Cron-Token',
            'cron_header_value' => $token,
            'cli_command' => 'php ' . escapeshellarg($cronFilePath) . ' --task=mail-queue --limit=' . $batchSize . ' --quiet',
            'last_run' => is_array($lastRun) ? $lastRun : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getDashboardData(int $limit = 25): array
    {
        $config = $this->getConfiguration();

        return [
            'config' => $config,
            'stats' => $this->getStats(),
            'recent_jobs' => $this->getRecent($limit),
            'last_run' => $config['last_run'],
            'failure_categories' => $this->getFailureCategories(),
            'stale_jobs' => $this->getStaleJobs(),
        ];
    }

    public function isEnabled(): bool
    {
        return !empty($this->getConfiguration()['enabled']);
    }

    public function shouldQueue(array $headers = []): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }

        $normalized = $this->decodeHeaders($this->encodeHeaders($headers));
        $forceDirect = strtolower(trim((string) ($normalized['X-365CMS-Force-Direct'] ?? '0')));

        return !in_array($forceDirect, ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * @param array<string, string> $headers
     * @return array{success:bool,message?:string,error?:string,id?:int}
     */
    public function enqueue(
        string $recipient,
        string $subject,
        string $htmlBody,
        array $headers = [],
        ?\DateTimeInterface $availableAt = null,
        string $source = 'system',
        ?int $maxAttempts = null
    ): array {
        return $this->enqueueMessage($recipient, $subject, $htmlBody, $headers, $availableAt, $source, $maxAttempts, 'html');
    }

    /**
     * @param array<string, string> $headers
     * @return array{success:bool,message?:string,error?:string,id?:int}
     */
    public function enqueuePlain(
        string $recipient,
        string $subject,
        string $plainBody,
        array $headers = [],
        ?\DateTimeInterface $availableAt = null,
        string $source = 'system',
        ?int $maxAttempts = null
    ): array {
        return $this->enqueueMessage($recipient, $subject, $plainBody, $headers, $availableAt, $source, $maxAttempts, 'plain');
    }

    /**
     * Klartext-Mail in die Queue, für Aufrufer in einer eigenen Transaktion (Community-Benachrichtigungen, Vertrag DESIGN-2b §3.8):
     * dieselben Prüfungen wie `enqueuePlain()` (Adresse, Betreff, Kopfzeilen), geschrieben über `Database::execute()` auf derselben
     * Verbindung. Datenbankfehler (`\PDOException`) werden nicht gefangen, damit `Transaction::run()` einen Deadlock wiederholt und die
     * Transaktion samt Hinweis zurücknimmt. `0` heißt nur fachliche Ablehnung (ungültige Adresse, leerer Betreff), sonst die neue ID.
     *
     * @param array<string, string> $headers
     */
    public function enqueuePlainStrict(string $recipient, string $subject, string $plainBody, array $headers = [], string $source = 'system'): int
    {
        $row = $this->queueRow($recipient, $subject, $plainBody, $headers, null, $source, null, 'plain');
        if (!isset($row['row'])) {
            return 0;
        }
        $columns = array_keys($row['row']);
        $sql = "INSERT INTO {$this->prefix}mail_queue (" . implode(', ', $columns) . ') VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')';
        $params = array_values($row['row']);
        $this->db->execute($sql, $params);

        return (int) $this->db->lastInsertId();
    }

    /**
     * @param array<string, string> $headers
     * @return array{success:bool,message?:string,error?:string,id?:int}
     */
    public function enqueueWithAttachment(
        string $recipient,
        string $subject,
        string $body,
        string $attachmentPath,
        string $attachmentName = '',
        bool $isHtml = true,
        array $headers = [],
        ?\DateTimeInterface $availableAt = null,
        string $source = 'system',
        ?int $maxAttempts = null
    ): array {
        $attachmentPath = trim($attachmentPath);
        if ($attachmentPath === '' || !is_file($attachmentPath) || !is_readable($attachmentPath)) {
            return ['success' => false, 'error' => 'Anhang für die Queue ist nicht lesbar.'];
        }

        $attachmentName = trim($attachmentName) !== '' ? trim($attachmentName) : basename($attachmentPath);
        $attachmentMime = mime_content_type($attachmentPath) ?: 'application/octet-stream';

        return $this->enqueueMessage(
            $recipient,
            $subject,
            $body,
            $headers,
            $availableAt,
            $source,
            $maxAttempts,
            $isHtml ? 'html' : 'plain',
            $attachmentPath,
            $attachmentName,
            $attachmentMime
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function getDiagnosticsData(int $limit = 100): array
    {
        $config = $this->getConfiguration();

        return [
            'config' => $config,
            'stats' => $this->getStats(),
            'last_run' => $config['last_run'],
            'recent_jobs' => $this->getRecent($limit),
            'failure_categories' => $this->getFailureCategories(),
            'stale_jobs' => $this->getStaleJobs(),
        ];
    }

    /**
     * @param array<string, string> $headers
     * @return array{success:bool,message?:string,error?:string,id?:int}
     */
    private function enqueueMessage(
        string $recipient,
        string $subject,
        string $body,
        array $headers = [],
        ?\DateTimeInterface $availableAt = null,
        string $source = 'system',
        ?int $maxAttempts = null,
        string $contentType = 'html',
        ?string $attachmentPath = null,
        ?string $attachmentName = null,
        ?string $attachmentMime = null
    ): array {
        $prepared = $this->queueRow($recipient, $subject, $body, $headers, $availableAt, $source, $maxAttempts, $contentType, $attachmentPath, $attachmentName, $attachmentMime);
        if (!isset($prepared['row'])) {
            return ['success' => false, 'error' => $prepared['error'] ?? 'Mail-Queue-Eintrag ungültig.'];
        }
        $recipient = (string) $prepared['row']['recipient'];
        $subject = (string) $prepared['row']['subject'];
        $insertId = $this->db->insert('mail_queue', $prepared['row']);

        if ($insertId === false) {
            $this->logger->error('Mail-Queue-Eintrag konnte nicht gespeichert werden', [
                'recipient' => $recipient,
                'subject' => $subject,
                'db_error' => $this->db->last_error,
            ]);

            return ['success' => false, 'error' => 'Mail-Queue-Eintrag konnte nicht gespeichert werden.'];
        }

        return [
            'success' => true,
            'message' => 'E-Mail wurde in die Queue aufgenommen.',
            'id' => (int) $insertId,
        ];
    }

    /**
     * Gemeinsame Prüfung und Zeile eines Queue-Eintrags für `enqueueMessage()` und `enqueuePlainStrict()`: gültige Adresse, bereinigter
     * Betreff (nicht leer), bereinigte Kopfzeilen. `{row: array}` oder `{error: string}`.
     *
     * @param array<string, string> $headers
     * @return array{row?: array<string, mixed>, error?: string}
     */
    private function queueRow(
        string $recipient,
        string $subject,
        string $body,
        array $headers,
        ?\DateTimeInterface $availableAt,
        string $source,
        ?int $maxAttempts,
        string $contentType,
        ?string $attachmentPath = null,
        ?string $attachmentName = null,
        ?string $attachmentMime = null
    ): array {
        $recipient = trim($recipient);
        if (filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
            return ['error' => 'Ungültige Empfänger-E-Mail-Adresse für die Queue.'];
        }

        $subject = $this->sanitizeSubject($subject);
        if ($subject === '') {
            return ['error' => 'Für die Queue ist ein Betreff erforderlich.'];
        }

        $headers = $this->sanitizeHeaders($headers);
        $contentType = $contentType === 'plain' ? 'plain' : 'html';
        $maxAttempts = $maxAttempts !== null ? min(20, max(1, $maxAttempts)) : (int) $this->getConfiguration()['max_attempts'];

        return ['row' => [
            'recipient' => $recipient,
            'subject' => $subject,
            'body' => $body,
            'headers' => $this->encodeHeaders($headers),
            'content_type' => $contentType,
            'source' => trim($source) !== '' ? trim($source) : 'system',
            'status' => 'pending',
            'attempts' => 0,
            'max_attempts' => $maxAttempts,
            'available_at' => ($availableAt ?? new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            'sent_at' => null,
            'locked_at' => null,
            'last_attempt_at' => null,
            'attachment_path' => $attachmentPath,
            'attachment_name' => $attachmentName,
            'attachment_mime' => $attachmentMime,
            'error_category' => null,
            'last_error' => null,
        ]];
    }

    /**
        * @return array{success:bool,message?:string,error?:string,worker:string,claimed:int,sent:int,retried:int,failed_final:int,released_stale:int,processed:int,skipped?:bool,rate_limited?:bool,next_rate_window_in_seconds?:int}
     */
    public function processDueJobs(?int $limit = null, string $worker = 'cron', bool $force = false): array
    {
        $config = $this->getConfiguration();
        if (empty($config['enabled']) && !$force) {
            return [
                'success' => true,
                'skipped' => true,
                'message' => 'Mail-Queue ist deaktiviert; Cron-Worker übersprungen.',
                'worker' => $worker,
                'claimed' => 0,
                'sent' => 0,
                'retried' => 0,
                'failed_final' => 0,
                'released_stale' => 0,
                'processed' => 0,
            ];
        }

        $limit = $limit !== null ? min(100, max(1, $limit)) : (int) $config['batch_size'];
        $releasedStale = $this->releaseStaleProcessingJobs((int) $config['lock_timeout_seconds']);
        $rateLimit = (int) ($config['rate_limit_per_minute'] ?? self::DEFAULT_RATE_LIMIT_PER_MINUTE);
        $remainingRateSlots = $this->getRemainingRateLimitSlots($rateLimit);

        $summary = [
            'success' => true,
            'message' => 'Keine fälligen Queue-Jobs gefunden.',
            'worker' => $worker,
            'claimed' => 0,
            'sent' => 0,
            'retried' => 0,
            'failed_final' => 0,
            'released_stale' => $releasedStale,
            'processed' => 0,
        ];

        if ($remainingRateSlots <= 0) {
            $summary['rate_limited'] = true;
            $summary['next_rate_window_in_seconds'] = $this->getSecondsUntilNextRateWindow();
            $summary['message'] = 'Mail-Queue pausiert: Versandlimit pro Minute erreicht.';
            $this->persistLastRunSummary($summary, $worker);

            return $summary;
        }

        $jobs = $this->getDueJobs(min($limit, $remainingRateSlots));

        foreach ($jobs as $job) {
            $jobId = (int) ($job->id ?? 0);
            if ($jobId <= 0) {
                continue;
            }

            if (!$this->reserveRateLimitSlot($rateLimit)) {
                $summary['rate_limited'] = true;
                $summary['next_rate_window_in_seconds'] = $this->getSecondsUntilNextRateWindow();
                break;
            }

            if (!$this->claimJob($jobId)) {
                continue;
            }

            $summary['claimed']++;
            $attemptNumber = (int) ($job->attempts ?? 0) + 1;
            $maxAttempts = (int) ($job->max_attempts ?? $config['max_attempts']);
            $headers = $this->decodeHeaders($job->headers ?? null);
            $headers['X-365CMS-Queue-Id'] = (string) $jobId;
            $headers['X-365CMS-Queue-Attempt'] = (string) $attemptNumber;
            $headers['X-365CMS-Test-Source'] = (string) ($job->source ?? 'queue');
            $contentType = (string) ($job->content_type ?? 'html');
            $attachmentPath = trim((string) ($job->attachment_path ?? ''));
            $attachmentName = (string) ($job->attachment_name ?? '');
            $attachmentMime = (string) ($job->attachment_mime ?? '');

            if ($attachmentPath !== '') {
                $result = MailService::getInstance()->sendWithAttachmentDetailed(
                    (string) ($job->recipient ?? ''),
                    (string) ($job->subject ?? ''),
                    (string) ($job->body ?? ''),
                    $attachmentPath,
                    $attachmentName,
                    $contentType !== 'plain',
                    $headers,
                    $attachmentMime
                );
            } elseif ($contentType === 'plain') {
                $result = MailService::getInstance()->sendPlainDetailed(
                    (string) ($job->recipient ?? ''),
                    (string) ($job->subject ?? ''),
                    (string) ($job->body ?? ''),
                    $headers
                );
            } else {
                $result = MailService::getInstance()->sendDetailed(
                    (string) ($job->recipient ?? ''),
                    (string) ($job->subject ?? ''),
                    (string) ($job->body ?? ''),
                    $headers
                );
            }

            if (!empty($result['success'])) {
                $this->markSent($jobId);
                $summary['sent']++;
                continue;
            }

            $error = trim((string) ($result['error'] ?? 'Unbekannter Queue-Fehler'));
            $retryable = !empty($result['retryable']);
            $delay = $this->resolveRetryDelay($result, $config);
            $errorCategory = (string) ($result['error_category'] ?? 'temporary');

            if ($errorCategory === 'throttle') {
                $this->rescheduleJob($jobId, $error, $delay, $errorCategory);
                $summary['retried']++;
                $summary['rate_limited'] = true;
                $summary['next_rate_window_in_seconds'] = max($this->getSecondsUntilNextRateWindow(), $delay);
                $this->logger->warning('Mail-Queue-Batch wegen Provider-Drosselung pausiert.', [
                    'job_id' => $jobId,
                    'delay_seconds' => $delay,
                    'error' => $this->truncateError($error),
                ]);
                break;
            }

            if ($retryable && $attemptNumber < $maxAttempts) {
                $this->rescheduleJob($jobId, $error, $delay, $errorCategory);
                $summary['retried']++;
                continue;
            }

            $this->markFailed($jobId, $error, $errorCategory);
            $summary['failed_final']++;
        }

        $summary['processed'] = $summary['sent'] + $summary['retried'] + $summary['failed_final'];
        $summary['message'] = $summary['processed'] > 0
            ? 'Mail-Queue verarbeitet: ' . $summary['sent'] . ' versendet, ' . $summary['retried'] . ' erneut geplant, ' . $summary['failed_final'] . ' final fehlgeschlagen.'
            : 'Keine fälligen Queue-Jobs gefunden.';

        if (!empty($summary['rate_limited'])) {
            $summary['message'] .= ' Versandlimit aktiv; weiterer Versand wird später fortgesetzt.';
        }

        $this->persistLastRunSummary($summary, $worker);

        return $summary;
    }

    /**
     * @param array<string, mixed> $payload
        * @return array{success:bool,message?:string,error?:string,worker:string,claimed:int,sent:int,retried:int,failed_final:int,released_stale:int,processed:int,skipped?:bool,rate_limited?:bool,next_rate_window_in_seconds?:int}
     */
    public function handleCronHook(array $payload = []): array
    {
        $limit = isset($payload['limit']) ? (int) $payload['limit'] : null;
        $force = !empty($payload['force']);

        return $this->processDueJobs($limit, 'cron', $force);
    }

    public function saveConfiguration(array $config): bool
    {
        return $this->settings->setMany(self::GROUP, [
            'queue_enabled' => !empty($config['enabled']),
            'queue_batch_size' => min(100, max(1, (int) ($config['batch_size'] ?? 10))),
            'queue_max_attempts' => min(20, max(1, (int) ($config['max_attempts'] ?? 5))),
            'queue_retry_delay_seconds' => min(86400, max(60, (int) ($config['retry_delay_seconds'] ?? 300))),
            'queue_throttle_delay_seconds' => min(86400, max(60, (int) ($config['throttle_delay_seconds'] ?? 900))),
            'queue_lock_timeout_seconds' => min(86400, max(60, (int) ($config['lock_timeout_seconds'] ?? 900))),
            'queue_rate_limit_per_minute' => min(600, max(1, (int) ($config['rate_limit_per_minute'] ?? self::DEFAULT_RATE_LIMIT_PER_MINUTE))),
        ], [], 0);
    }

    public function rotateCronToken(): string
    {
        $token = bin2hex(random_bytes(24));
        $this->settings->set(self::GROUP, 'queue_cron_token', $token, true, 0);

        return $token;
    }

    public function releaseStaleProcessingJobs(int $lockTimeoutSeconds = 900): int
    {
        $lockTimeoutSeconds = min(86400, max(60, $lockTimeoutSeconds));
        $cutoff = date('Y-m-d H:i:s', time() - $lockTimeoutSeconds);

        try {
            $stmt = $this->db->execute(
                "UPDATE {$this->prefix}mail_queue
                 SET status = 'pending', locked_at = NULL, available_at = NOW(), error_category = COALESCE(error_category, 'stale_lock'), last_error = COALESCE(last_error, 'Stale Job automatisch freigegeben.')
                 WHERE status = 'processing' AND locked_at IS NOT NULL AND locked_at < ?",
                [$cutoff]
            );

            return $stmt->rowCount();
        } catch (\Throwable $e) {
            $this->logger->warning('Stale Mail-Queue-Jobs konnten nicht freigegeben werden', [
                'exception' => $e,
            ]);

            return 0;
        }
    }

    /**
     * @return array<string, int>
     */
    public function getStats(): array
    {
        $stats = [
            'pending' => 0,
            'processing' => 0,
            'sent' => 0,
            'failed' => 0,
        ];

        try {
            $rows = $this->db->get_results(
                "SELECT status, COUNT(*) AS cnt FROM {$this->prefix}mail_queue GROUP BY status"
            ) ?: [];

            foreach ($rows as $row) {
                $status = (string) ($row->status ?? '');
                if (array_key_exists($status, $stats)) {
                    $stats[$status] = (int) ($row->cnt ?? 0);
                }
            }
        } catch (\Throwable) {
            // Tabelle evtl. vor erster Migration noch nicht vorhanden.
        }

        return $stats;
    }

    /**
     * @return list<object>
     */
    public function getRecent(int $limit = 25): array
    {
        $limit = min(100, max(1, $limit));

        try {
            return $this->db->get_results(
                "SELECT id, recipient, subject, status, attempts, max_attempts, source, content_type, available_at, sent_at, locked_at, last_attempt_at, error_category, last_error, created_at, updated_at
                 FROM {$this->prefix}mail_queue
                 ORDER BY created_at DESC
                 LIMIT ?",
                [$limit]
            ) ?: [];
        } catch (\Throwable $e) {
            $this->logger->warning('Mail-Queue-Übersicht konnte nicht geladen werden', [
                'exception' => $e,
            ]);

            return [];
        }
    }

    /**
     * @return list<object>
     */
    private function getDueJobs(int $limit): array
    {
        // Release-Prüfung 4.1.0 (PERF, batch:1): Massenversand (BULK_SOURCES) nach hinten sortieren, sonst FIFO wie bisher.
        $bulkPlaceholders = implode(', ', array_fill(0, count(self::BULK_SOURCES), '?'));

        return $this->db->get_results(
            "SELECT *
             FROM {$this->prefix}mail_queue
             WHERE status = 'pending'
               AND (available_at IS NULL OR available_at <= ?)
             ORDER BY (source IN ({$bulkPlaceholders})) ASC, COALESCE(available_at, created_at) ASC, id ASC
             LIMIT ?",
            array_merge([date('Y-m-d H:i:s')], self::BULK_SOURCES, [$limit])
        ) ?: [];
    }

    /**
     * Release-Prüfung 4.1.0 (PERF, batch:0): Trockenlauf der Aufbewahrungsregel `mail_queue`.
     */
    public function countPrunableJobs(int $retentionDays): int
    {
        if ($retentionDays <= 0) {
            return 0;
        }

        return (int) $this->db->get_var(
            "SELECT COUNT(*) FROM {$this->prefix}mail_queue WHERE " . self::PRUNABLE_WHERE,
            [$retentionDays]
        );
    }

    /**
     * Release-Prüfung 4.1.0 (PERF, batch:0): Löscht versendete und endgültig fehlgeschlagene Jobs samt Body
     * blockweise (DataRetentionService::deleteInBatches). Offene und laufende Jobs bleiben unberührt.
     */
    public function pruneFinishedJobs(int $retentionDays): int
    {
        if ($retentionDays <= 0) {
            return 0;
        }

        return DataRetentionService::getInstance()->deleteInBatches('mail_queue', self::PRUNABLE_WHERE, [$retentionDays]);
    }

    private function claimJob(int $jobId): bool
    {
        try {
            $stmt = $this->db->execute(
                "UPDATE {$this->prefix}mail_queue
                 SET status = 'processing', attempts = attempts + 1, locked_at = NOW(), last_attempt_at = NOW()
                 WHERE id = ? AND status = 'pending'",
                [$jobId]
            );

            return $stmt->rowCount() > 0;
        } catch (\Throwable $e) {
            $this->logger->warning('Mail-Queue-Job konnte nicht übernommen werden', [
                'job_id' => $jobId,
                'exception' => $e,
            ]);

            return false;
        }
    }

    private function markSent(int $jobId): void
    {
        $this->db->execute(
            "UPDATE {$this->prefix}mail_queue
             SET status = 'sent', sent_at = NOW(), locked_at = NULL, available_at = NULL, error_category = NULL, last_error = NULL
             WHERE id = ?",
            [$jobId]
        );
    }

    private function rescheduleJob(int $jobId, string $error, int $delaySeconds, string $errorCategory): void
    {
        $nextRun = date('Y-m-d H:i:s', time() + $delaySeconds);

        $this->db->execute(
            "UPDATE {$this->prefix}mail_queue
             SET status = 'pending', locked_at = NULL, available_at = ?, error_category = ?, last_error = ?
             WHERE id = ?",
            [$nextRun, $errorCategory, $this->truncateError($error), $jobId]
        );
    }

    private function markFailed(int $jobId, string $error, string $errorCategory): void
    {
        $this->db->execute(
            "UPDATE {$this->prefix}mail_queue
             SET status = 'failed', locked_at = NULL, available_at = NULL, error_category = ?, last_error = ?
             WHERE id = ?",
            [$errorCategory, $this->truncateError($error), $jobId]
        );
    }

    /**
     * @param array<string, mixed> $summary
     */
    private function persistLastRunSummary(array $summary, string $worker): void
    {
        $payload = [
            'executed_at' => date('Y-m-d H:i:s'),
            'worker' => $worker,
            'claimed' => (int) ($summary['claimed'] ?? 0),
            'sent' => (int) ($summary['sent'] ?? 0),
            'retried' => (int) ($summary['retried'] ?? 0),
            'failed_final' => (int) ($summary['failed_final'] ?? 0),
            'released_stale' => (int) ($summary['released_stale'] ?? 0),
            'processed' => (int) ($summary['processed'] ?? 0),
        ];

        if (!empty($summary['rate_limited'])) {
            $payload['rate_limited'] = true;
            $payload['next_rate_window_in_seconds'] = (int) ($summary['next_rate_window_in_seconds'] ?? 0);
        }

        $this->settings->set(self::GROUP, self::SETTINGS_LAST_RUN, $payload, false, 0);
    }

    private function getRemainingRateLimitSlots(int $limit): int
    {
        $window = $this->getCurrentRateWindow();
        $sent = (int) ($window['sent'] ?? 0);

        return max(0, $limit - $sent);
    }

    private function reserveRateLimitSlot(int $limit): bool
    {
        $window = $this->getCurrentRateWindow();
        $sent = (int) ($window['sent'] ?? 0);
        if ($sent >= $limit) {
            return false;
        }

        $window['sent'] = $sent + 1;
        $this->settings->set(self::GROUP, self::SETTINGS_RATE_WINDOW, $window, false, 0);

        return true;
    }

    /**
     * @return array{window_start:int,sent:int}
     */
    private function getCurrentRateWindow(): array
    {
        $now = time();
        $window = $this->settings->get(self::GROUP, self::SETTINGS_RATE_WINDOW, []);
        if (!is_array($window)) {
            $window = [];
        }

        $windowStart = (int) ($window['window_start'] ?? 0);
        if ($windowStart <= 0 || ($now - $windowStart) >= 60) {
            return [
                'window_start' => $now,
                'sent' => 0,
            ];
        }

        return [
            'window_start' => $windowStart,
            'sent' => max(0, (int) ($window['sent'] ?? 0)),
        ];
    }

    private function getSecondsUntilNextRateWindow(): int
    {
        $window = $this->getCurrentRateWindow();
        $windowStart = (int) ($window['window_start'] ?? time());

        return max(1, 60 - (time() - $windowStart));
    }

    /**
     * @return list<array{category:string,count:int}>
     */
    private function getFailureCategories(): array
    {
        try {
            $rows = $this->db->get_results(
                "SELECT COALESCE(error_category, 'unknown') AS category, COUNT(*) AS cnt
                 FROM {$this->prefix}mail_queue
                 WHERE status IN ('pending', 'failed') AND last_error IS NOT NULL
                 GROUP BY COALESCE(error_category, 'unknown')
                 ORDER BY cnt DESC, category ASC"
            ) ?: [];

            return array_map(static function (object $row): array {
                return [
                    'category' => (string) ($row->category ?? 'unknown'),
                    'count' => (int) ($row->cnt ?? 0),
                ];
            }, $rows);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return list<object>
     */
    private function getStaleJobs(): array
    {
        $config = $this->getConfiguration();
        $cutoff = date('Y-m-d H:i:s', time() - (int) ($config['lock_timeout_seconds'] ?? 900));

        try {
            return $this->db->get_results(
                "SELECT id, recipient, subject, attempts, max_attempts, locked_at, last_attempt_at, error_category, last_error, source, updated_at
                 FROM {$this->prefix}mail_queue
                 WHERE status = 'processing' AND locked_at IS NOT NULL AND locked_at < ?
                 ORDER BY locked_at ASC",
                [$cutoff]
            ) ?: [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @param array<string, mixed> $result
     * @param array<string, mixed> $config
     */
    private function resolveRetryDelay(array $result, array $config): int
    {
        $category = (string) ($result['error_category'] ?? 'temporary');
        $recommended = (int) ($result['recommended_delay'] ?? 0);

        $baseDelay = $category === 'throttle'
            ? (int) ($config['throttle_delay_seconds'] ?? 900)
            : (int) ($config['retry_delay_seconds'] ?? 300);

        return max(60, $recommended > 0 ? $recommended : $baseDelay);
    }

    /**
     * @param array<string, string> $headers
     */
    private function encodeHeaders(array $headers): ?string
    {
        $headers = $this->sanitizeHeaders($headers);
        if ($headers === []) {
            return null;
        }

        try {
            return json_encode($headers, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, string>
     */
    private function decodeHeaders(mixed $headers): array
    {
        if (!is_string($headers) || trim($headers) === '') {
            return [];
        }

        try {
            $decoded = json_decode($headers, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($decoded)) {
                return [];
            }

            $normalized = [];
            foreach ($decoded as $key => $value) {
                if (!is_scalar($value)) {
                    continue;
                }

                $name = $this->sanitizeHeaderName((string) $key);
                $headerValue = $this->sanitizeHeaderValue((string) $value);
                if ($name === '' || $headerValue === '') {
                    continue;
                }

                $normalized[$name] = $headerValue;
            }

            return $normalized;
        } catch (\Throwable) {
            return [];
        }
    }

    private function truncateError(string $error): string
    {
        $error = trim($error);
        if ($error === '') {
            return 'Unbekannter Mail-Queue-Fehler';
        }

        return mb_substr($error, 0, 4000);
    }

    private function sanitizeSubject(string $subject): string
    {
        $subject = trim($this->sanitizeHeaderValue($subject));
        $subject = preg_replace('/\s+/u', ' ', $subject) ?? $subject;

        return $this->substring($subject, self::MAX_SUBJECT_LENGTH);
    }

    /**
     * @param array<string, string> $headers
     * @return array<string, string>
     */
    private function sanitizeHeaders(array $headers): array
    {
        $normalized = [];

        foreach ($headers as $key => $value) {
            if (!is_scalar($value)) {
                continue;
            }

            $name = $this->sanitizeHeaderName((string) $key);
            $headerValue = $this->sanitizeHeaderValue((string) $value);
            if ($name === '' || $headerValue === '') {
                continue;
            }

            $normalized[$name] = $headerValue;
        }

        return $normalized;
    }

    private function sanitizeHeaderName(string $name): string
    {
        $name = trim($name);

        return preg_match('/^[A-Za-z0-9-]+$/', $name) === 1 ? $name : '';
    }

    private function sanitizeHeaderValue(string $value): string
    {
        $value = str_replace(["\r", "\n", "\0"], ' ', $value);
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u', '', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }

    private function substring(string $value, int $length): string
    {
        return function_exists('mb_substr') ? mb_substr($value, 0, $length) : substr($value, 0, $length);
    }
}
