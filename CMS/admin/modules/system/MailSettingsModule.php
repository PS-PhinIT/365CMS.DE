<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

use CMS\AuditLogger;
use CMS\Auth;
use CMS\Logger;
use CMS\Services\AzureMailTokenProvider;
use CMS\Services\GraphApiService;
use CMS\Services\MailLogService;
use CMS\Services\MailQueueService;
use CMS\Services\MailService;
use CMS\Services\SettingsService;

final class MailSettingsViewData
{
    /** @param array<string, mixed> $data */
    public function __construct(private array $data)
    {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->data;
    }
}

final class MailSettingsActionResult
{
    /** @param array<string, mixed> $data */
    public function __construct(private array $data)
    {
    }

    public static function failure(string $error): self
    {
        return new self(['success' => false, 'error' => $error]);
    }

    public function isSuccess(): bool
    {
        return !empty($this->data['success']);
    }

    public function message(): string
    {
        return (string) ($this->data['message'] ?? '');
    }

    public function error(): string
    {
        return (string) ($this->data['error'] ?? '');
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->data;
    }
}

class MailSettingsModule
{
    private const string AUTH_MODE_CREDENTIALS = 'credentials';
    private const string AUTH_MODE_OAUTH2 = 'oauth2';
    private const int MAX_TEXT_LENGTH = 190;
    private const int MAX_HOST_LENGTH = 190;
    private const int MAX_NAME_LENGTH = 120;
    private const int MAX_SCOPE_LENGTH = 190;
    private const int MAX_URL_LENGTH = 255;

    private SettingsService $settings;
    private MailLogService $mailLogs;
    private MailQueueService $mailQueue;

    public function __construct()
    {
        $this->settings = SettingsService::getInstance();
        $this->mailLogs = MailLogService::getInstance();
        $this->mailQueue = MailQueueService::getInstance();
    }

    public function getData(): MailSettingsViewData
    {
        if (!$this->canAccess()) {
            return new MailSettingsViewData([
                'transport' => [],
                'azure' => [],
                'graph' => [],
                'transport_info' => [],
                'mail_logs' => [],
                'mail_stats' => [],
                'queue' => [],
                'queue_stats' => $this->defaultQueueStats(),
                'error' => 'Zugriff verweigert.',
            ]);
        }

        try {
            $mail = $this->settings->getGroup('mail');
            $graph = $this->settings->getGroup('graph');
            $transportInfo = MailService::getInstance()->getTransportInfo();
            $recentLogs = $this->mailLogs->getRecent(50);
            $stats = $this->mailLogs->getStats();
            $azure = AzureMailTokenProvider::getInstance()->getConfiguration();
            $graphConfig = GraphApiService::getInstance()->getConfiguration();
            $queueDashboard = $this->mailQueue->getDashboardData(25);

            return new MailSettingsViewData([
                'transport' => $this->buildTransportData($mail, $transportInfo),
                'azure' => $this->buildAzureData($mail, $azure),
                'graph' => $this->buildGraphData($graph, $graphConfig),
                'transport_info' => $transportInfo,
                'mail_logs' => $recentLogs,
                'mail_stats' => $stats,
                'queue' => $queueDashboard,
                'queue_stats' => is_array($queueDashboard['stats'] ?? null) ? $queueDashboard['stats'] : $this->defaultQueueStats(),
            ]);
        } catch (\Throwable $e) {
            Logger::instance()->withChannel('admin.mail-settings')->error('Mail-Settings-Daten konnten nicht geladen werden.', [
                'exception' => $e::class,
                'message' => $this->sanitizeText($e->getMessage(), self::MAX_TEXT_LENGTH),
            ]);

            return new MailSettingsViewData([
                'transport' => [],
                'azure' => [],
                'graph' => [],
                'transport_info' => [],
                'mail_logs' => ['rows' => []],
                'mail_stats' => [],
                'queue' => ['config' => [], 'recent_jobs' => [], 'last_run' => []],
                'queue_stats' => $this->defaultQueueStats(),
                'error' => 'Mail-Einstellungen konnten gerade nicht geladen werden. Bitte Logs prüfen.',
            ]);
        }
    }

    public function saveTransport(array $post): MailSettingsActionResult
    {
        if (!$this->canAccess()) {
            return $this->denyResult();
        }

        $driver = ($post['driver'] ?? 'mail') === 'smtp' ? 'smtp' : 'mail';
        $authMode = $this->normalizeAuthMode((string) ($post['auth_mode'] ?? self::AUTH_MODE_CREDENTIALS));
        $smtpEncryption = (string) ($post['smtp_encryption'] ?? 'tls');
        if (!in_array($smtpEncryption, ['tls', 'ssl', ''], true)) {
            $smtpEncryption = 'tls';
        }

        $values = [
            'driver' => $driver,
            'auth_mode' => $authMode,
            'smtp_host' => $this->sanitizeHost((string) ($post['smtp_host'] ?? '')),
            'smtp_port' => max(1, min(65535, (int) ($post['smtp_port'] ?? 587))),
            'smtp_encryption' => $smtpEncryption,
            'smtp_username' => $this->sanitizeText((string) ($post['smtp_username'] ?? ''), self::MAX_TEXT_LENGTH),
            'from_email' => $this->sanitizeEmail((string) ($post['from_email'] ?? '')),
            'from_name' => $this->sanitizeText((string) ($post['from_name'] ?? ''), self::MAX_NAME_LENGTH),
            'test_recipient' => $this->sanitizeEmail((string) ($post['test_recipient'] ?? '')),
        ];

        if ($driver === 'smtp' && $values['smtp_host'] === '') {
            return $this->errorResult('Für SMTP muss ein gültiger SMTP-Host angegeben werden.');
        }

        if ($values['from_email'] === '') {
            return $this->errorResult('Bitte eine gültige Absender-E-Mail-Adresse angeben.');
        }

        try {
            if (!$this->settings->setMany('mail', $values, [], 0)) {
                return $this->errorResult('Transport-Einstellungen konnten nicht gespeichert werden.');
            }

            $smtpPassword = trim((string) ($post['smtp_password'] ?? ''));
            if ($smtpPassword !== '') {
                $this->settings->set('mail', 'smtp_password', $smtpPassword, true, 0);
            }

            if (!empty($post['clear_smtp_password'])) {
                $this->settings->forget('mail', 'smtp_password');
            }

            if ($authMode !== 'oauth2') {
                AzureMailTokenProvider::getInstance()->clearCache();
            }
        } catch (\Throwable $e) {
            return $this->failResult('setting.mail.transport.save_failed', 'Transport-Einstellungen konnten nicht gespeichert werden.', $e);
        }

        AuditLogger::instance()->log(
            AuditLogger::CAT_SETTING,
            'setting.mail.transport.save',
            'Mail-Transport-Einstellungen gespeichert.',
            'setting',
            null,
            [
                'driver' => $driver,
                'auth_mode' => $authMode,
                'smtp_host' => $this->maskHost($values['smtp_host']),
                'smtp_port' => $values['smtp_port'],
                'from_email' => $this->maskEmail($values['from_email']),
            ],
            'info'
        );

        return $this->successResult('Transport-Einstellungen gespeichert.');
    }

    public function saveAzure(array $post): MailSettingsActionResult
    {
        if (!$this->canAccess()) {
            return $this->denyResult();
        }

        $values = [
            'azure_tenant_id' => $this->sanitizeText((string) ($post['azure_tenant_id'] ?? ''), self::MAX_TEXT_LENGTH),
            'azure_client_id' => $this->sanitizeText((string) ($post['azure_client_id'] ?? ''), self::MAX_TEXT_LENGTH),
            'azure_mailbox' => $this->sanitizeEmail((string) ($post['azure_mailbox'] ?? '')),
            'azure_scope' => $this->sanitizeText((string) ($post['azure_scope'] ?? 'https://outlook.office365.com/.default'), self::MAX_SCOPE_LENGTH),
            'azure_token_endpoint' => $this->sanitizeUrl((string) ($post['azure_token_endpoint'] ?? ''), true),
        ];

        if ($values['azure_tenant_id'] === '' || $values['azure_client_id'] === '') {
            return $this->errorResult('Tenant-ID und Client-ID müssen gesetzt sein.');
        }

        try {
            if (!$this->settings->setMany('mail', $values, [], 0)) {
                return $this->errorResult('Azure-OAuth2-Einstellungen konnten nicht gespeichert werden.');
            }

            $clientSecret = trim((string) ($post['azure_client_secret'] ?? ''));
            if ($clientSecret !== '') {
                $this->settings->set('mail', 'azure_client_secret', $clientSecret, true, 0);
            }
            if (!empty($post['clear_azure_client_secret'])) {
                $this->settings->forget('mail', 'azure_client_secret');
            }

            AzureMailTokenProvider::getInstance()->clearCache();
        } catch (\Throwable $e) {
            return $this->failResult('setting.mail.azure.save_failed', 'Azure-OAuth2-Einstellungen konnten nicht gespeichert werden.', $e);
        }

        AuditLogger::instance()->log(
            AuditLogger::CAT_SETTING,
            'setting.mail.azure.save',
            'Azure-Mail-OAuth2-Einstellungen gespeichert.',
            'setting',
            null,
            [
                'tenant_id' => $this->maskIdentifier($values['azure_tenant_id']),
                'client_id' => $this->maskIdentifier($values['azure_client_id']),
                'mailbox' => $this->maskEmail($values['azure_mailbox']),
            ],
            'info'
        );

        return $this->successResult('Azure-OAuth2-Einstellungen gespeichert.');
    }

    public function saveGraph(array $post): MailSettingsActionResult
    {
        if (!$this->canAccess()) {
            return $this->denyResult();
        }

        $values = [
            'tenant_id' => $this->sanitizeText((string) ($post['graph_tenant_id'] ?? ''), self::MAX_TEXT_LENGTH),
            'client_id' => $this->sanitizeText((string) ($post['graph_client_id'] ?? ''), self::MAX_TEXT_LENGTH),
            'scope' => $this->sanitizeText((string) ($post['graph_scope'] ?? 'https://graph.microsoft.com/.default'), self::MAX_SCOPE_LENGTH),
            'base_url' => $this->sanitizeUrl((string) ($post['graph_base_url'] ?? 'https://graph.microsoft.com/v1.0')),
            'token_endpoint' => $this->sanitizeUrl((string) ($post['graph_token_endpoint'] ?? ''), true),
        ];

        if ($values['tenant_id'] === '' || $values['client_id'] === '' || $values['base_url'] === '') {
            return $this->errorResult('Tenant-ID, Client-ID und Graph-Basis-URL müssen gültig gesetzt sein.');
        }

        try {
            if (!$this->settings->setMany('graph', $values, [], 0)) {
                return $this->errorResult('Graph-Einstellungen konnten nicht gespeichert werden.');
            }

            $clientSecret = trim((string) ($post['graph_client_secret'] ?? ''));
            if ($clientSecret !== '') {
                $this->settings->set('graph', 'client_secret', $clientSecret, true, 0);
            }
            if (!empty($post['clear_graph_client_secret'])) {
                $this->settings->forget('graph', 'client_secret');
            }

            GraphApiService::getInstance()->clearCache();
        } catch (\Throwable $e) {
            return $this->failResult('setting.graph.save_failed', 'Graph-Einstellungen konnten nicht gespeichert werden.', $e);
        }

        AuditLogger::instance()->log(
            AuditLogger::CAT_SETTING,
            'setting.graph.save',
            'Microsoft-Graph-Einstellungen gespeichert.',
            'setting',
            null,
            [
                'tenant_id' => $this->maskIdentifier($values['tenant_id']),
                'client_id' => $this->maskIdentifier($values['client_id']),
                'base_url' => $this->maskUrl($values['base_url']),
            ],
            'info'
        );

        return $this->successResult('Microsoft-Graph-Einstellungen gespeichert.');
    }

    public function sendTestEmail(array $post): MailSettingsActionResult
    {
        if (!$this->canAccess()) {
            return $this->denyResult();
        }

        $recipient = $this->sanitizeEmail((string) ($post['test_email_recipient'] ?? $post['test_recipient'] ?? ''));
        if ($recipient === '') {
            return $this->errorResult('Bitte eine gültige Empfänger-E-Mail-Adresse angeben.');
        }

        try {
            $result = MailService::getInstance()->sendBackendTestEmail($recipient, 'admin-mail-settings');
        } catch (\Throwable $e) {
            return $this->failResult('setting.mail.test_failed', 'Test-E-Mail konnte nicht versendet werden.', $e);
        }

        $result = $this->sanitizeActionResponse($result, ['transport']);

        AuditLogger::instance()->log(
            AuditLogger::CAT_SETTING,
            'setting.mail.test',
            !empty($result['success']) ? 'Test-E-Mail aus dem Mail-Admin versendet.' : 'Test-E-Mail aus dem Mail-Admin fehlgeschlagen.',
            'setting',
            null,
            [
                'recipient' => $this->maskEmail($recipient),
                'transport' => $this->sanitizeText((string) ($result['transport'] ?? ''), 40),
                'result' => !empty($result['success']) ? 'success' : 'error',
            ],
            !empty($result['success']) ? 'info' : 'warning'
        );

        return $result;
    }

    public function saveQueue(array $post): MailSettingsActionResult
    {
        if (!$this->canAccess()) {
            return $this->denyResult();
        }

        $queueConfig = [
            'enabled' => !empty($post['queue_enabled']),
            'batch_size' => max(1, min(100, (int) ($post['queue_batch_size'] ?? 10))),
            'max_attempts' => max(1, min(20, (int) ($post['queue_max_attempts'] ?? 5))),
            'retry_delay_seconds' => max(30, min(86400, (int) ($post['queue_retry_delay_seconds'] ?? 300))),
            'throttle_delay_seconds' => max(0, min(86400, (int) ($post['queue_throttle_delay_seconds'] ?? 900))),
            'lock_timeout_seconds' => max(60, min(86400, (int) ($post['queue_lock_timeout_seconds'] ?? 900))),
            'rate_limit_per_minute' => max(1, min(600, (int) ($post['queue_rate_limit_per_minute'] ?? 8))),
        ];

        try {
            $saved = $this->mailQueue->saveConfiguration($queueConfig);
            if ($saved && !empty($post['regenerate_queue_cron_token'])) {
                $this->mailQueue->rotateCronToken();
            }
        } catch (\Throwable $e) {
            return $this->failResult('setting.mail.queue.save_failed', 'Queue-Einstellungen konnten nicht gespeichert werden.', $e);
        }

        if (!$saved) {
            return $this->errorResult('Queue-Einstellungen konnten nicht gespeichert werden.');
        }

        AuditLogger::instance()->log(
            AuditLogger::CAT_SETTING,
            'setting.mail.queue.save',
            'Mail-Queue-Einstellungen gespeichert.',
            'setting',
            null,
            [
                'enabled' => $queueConfig['enabled'],
                'batch_size' => $queueConfig['batch_size'],
                'max_attempts' => $queueConfig['max_attempts'],
                'cron_token_rotated' => !empty($post['regenerate_queue_cron_token']),
            ],
            'info'
        );

        return $this->successResult('Queue-Einstellungen gespeichert.');
    }

    public function runQueueNow(array $post): MailSettingsActionResult
    {
        if (!$this->canAccess()) {
            return $this->denyResult();
        }

        $limit = max(1, min(100, (int) ($post['queue_run_limit'] ?? 0)));
        try {
            $result = $this->mailQueue->processDueJobs($limit > 0 ? $limit : null, 'admin-manual', true);
        } catch (\Throwable $e) {
            return $this->failResult('mail.queue.run_failed', 'Mail-Queue-Lauf konnte nicht gestartet werden.', $e);
        }

        $result = $this->sanitizeActionResponse($result);

        AuditLogger::instance()->log(
            AuditLogger::CAT_SYSTEM,
            'mail.queue.run',
            !empty($result['success']) ? 'Mail-Queue manuell ausgeführt.' : 'Mail-Queue-Lauf fehlgeschlagen.',
            'system',
            null,
            $this->summarizeQueueResult($result),
            !empty($result['success']) ? 'info' : 'warning'
        );

        return $result;
    }

    public function releaseQueueStale(): MailSettingsActionResult
    {
        if (!$this->canAccess()) {
            return $this->denyResult();
        }

        try {
            $config = $this->mailQueue->getConfiguration();
            $released = $this->mailQueue->releaseStaleProcessingJobs((int) ($config['lock_timeout_seconds'] ?? 900));
        } catch (\Throwable $e) {
            return $this->failResult('mail.queue.release_stale_failed', 'Verwaiste Queue-Jobs konnten nicht freigegeben werden.', $e);
        }

        AuditLogger::instance()->log(
            AuditLogger::CAT_SYSTEM,
            'mail.queue.release_stale',
            'Stale Mail-Queue-Jobs wurden freigegeben.',
            'system',
            null,
            ['released' => $released],
            'warning'
        );

        return new MailSettingsActionResult([
            'success' => true,
            'message' => $released > 0
                ? $released . ' verwaiste Queue-Jobs wurden freigegeben.'
                : 'Keine verwaisten Queue-Jobs gefunden.',
        ]);
    }

    public function enqueueQueueTestEmail(array $post): MailSettingsActionResult
    {
        if (!$this->canAccess()) {
            return $this->denyResult();
        }

        $recipient = $this->sanitizeEmail((string) ($post['queue_test_recipient'] ?? $post['test_recipient'] ?? ''));
        if ($recipient === '') {
            return $this->errorResult('Bitte eine gültige Empfänger-E-Mail-Adresse angeben.');
        }

        try {
            $result = MailService::getInstance()->queueBackendTestEmail($recipient, 'admin-mail-queue');
        } catch (\Throwable $e) {
            return $this->failResult('setting.mail.queue.enqueue_test_failed', 'Test-E-Mail konnte nicht in die Queue gelegt werden.', $e);
        }

        $result = $this->sanitizeActionResponse($result, ['id']);

        AuditLogger::instance()->log(
            AuditLogger::CAT_SETTING,
            'setting.mail.queue.enqueue_test',
            !empty($result['success']) ? 'Test-E-Mail wurde in die Queue gelegt.' : 'Test-E-Mail konnte nicht in die Queue gelegt werden.',
            'setting',
            null,
            [
                'recipient' => $this->maskEmail($recipient),
                'queue_id' => $result['id'] ?? null,
                'result' => !empty($result['success']) ? 'success' : 'error',
            ],
            !empty($result['success']) ? 'info' : 'warning'
        );

        return $result;
    }

    public function testGraphConnection(): MailSettingsActionResult
    {
        if (!$this->canAccess()) {
            return $this->denyResult();
        }

        try {
            $result = GraphApiService::getInstance()->testConnection(true);
        } catch (\Throwable $e) {
            return $this->failResult('setting.graph.test_failed', 'Microsoft-Graph-Verbindungstest fehlgeschlagen.', $e);
        }

        $result = $this->sanitizeActionResponse($result, ['token_expires_at']);

        AuditLogger::instance()->log(
            AuditLogger::CAT_SETTING,
            'setting.graph.test',
            !empty($result['success']) ? 'Microsoft-Graph-Verbindung erfolgreich getestet.' : 'Microsoft-Graph-Verbindungstest fehlgeschlagen.',
            'setting',
            null,
            [
                'result' => !empty($result['success']) ? 'success' : 'error',
                'organization' => $this->sanitizeText((string) ($result['organization']['displayName'] ?? ''), self::MAX_NAME_LENGTH),
            ],
            !empty($result['success']) ? 'info' : 'warning'
        );

        return $result;
    }

    public function clearLogs(): MailSettingsActionResult
    {
        if (!$this->canAccess()) {
            return $this->denyResult();
        }

        try {
            $cleared = $this->mailLogs->clear();
        } catch (\Throwable $e) {
            return $this->failResult('mail.log.clear_failed', 'Mail-Logs konnten nicht geleert werden.', $e);
        }

        if (!$cleared) {
            return $this->errorResult('Mail-Logs konnten nicht geleert werden.');
        }

        AuditLogger::instance()->log(
            AuditLogger::CAT_SYSTEM,
            'mail.log.clear',
            'Mail-Logs wurden geleert.',
            'system',
            null,
            [],
            'warning'
        );

        return $this->successResult('Mail-Logs wurden geleert.');
    }

    public function clearAzureCache(): MailSettingsActionResult
    {
        if (!$this->canAccess()) {
            return $this->denyResult();
        }

        try {
            AzureMailTokenProvider::getInstance()->clearCache();
        } catch (\Throwable $e) {
            return $this->failResult('setting.mail.azure_cache.clear_failed', 'Azure-Token-Cache konnte nicht geleert werden.', $e);
        }

        AuditLogger::instance()->log(
            AuditLogger::CAT_SETTING,
            'setting.mail.azure_cache.clear',
            'Azure-Mail-Token-Cache wurde geleert.',
            'setting',
            null,
            [],
            'info'
        );

        return $this->successResult('Azure-Token-Cache wurde geleert.');
    }

    public function clearGraphCache(): MailSettingsActionResult
    {
        if (!$this->canAccess()) {
            return $this->denyResult();
        }

        try {
            GraphApiService::getInstance()->clearCache();
        } catch (\Throwable $e) {
            return $this->failResult('setting.graph.cache.clear_failed', 'Graph-Token-Cache konnte nicht geleert werden.', $e);
        }

        AuditLogger::instance()->log(
            AuditLogger::CAT_SETTING,
            'setting.graph.cache.clear',
            'Graph-Token-Cache wurde geleert.',
            'setting',
            null,
            [],
            'info'
        );

        return $this->successResult('Graph-Token-Cache wurde geleert.');
    }

    private function canAccess(): bool
    {
        return Auth::instance()->isAdmin();
    }

    /** @param array<string, mixed> $mail */
    /** @param array<string, mixed> $transportInfo */
    private function buildTransportData(array $mail, array $transportInfo): array
    {
        $authMode = $this->normalizeAuthMode((string) ($mail['auth_mode'] ?? ($transportInfo['auth_mode'] ?? self::AUTH_MODE_CREDENTIALS)));

        return [
            'driver' => (string) ($mail['driver'] ?? (!empty($transportInfo['uses_smtp']) ? 'smtp' : 'mail')),
            'auth_mode' => $authMode,
            'smtp_host' => (string) ($mail['smtp_host'] ?? ($transportInfo['host'] ?? '')),
            'smtp_port' => (int) ($mail['smtp_port'] ?? ($transportInfo['port'] ?? 587)),
            'smtp_encryption' => (string) ($mail['smtp_encryption'] ?? ($transportInfo['encryption_raw'] ?? 'tls')),
            'smtp_username' => (string) ($mail['smtp_username'] ?? ($transportInfo['username'] ?? '')),
            'from_email' => (string) ($mail['from_email'] ?? ($transportInfo['from_email'] ?? '')),
            'from_name' => (string) ($mail['from_name'] ?? ($transportInfo['from_name'] ?? '')),
            'secret_configured' => $this->settings->getString('mail', 'smtp_password') !== '',
            'test_recipient' => (string) ($mail['test_recipient'] ?? ($transportInfo['from_email'] ?? (defined('ADMIN_EMAIL') ? ADMIN_EMAIL : ''))),
        ];
    }

    /** @param array<string, mixed> $mail */
    /** @param array<string, mixed> $azure */
    private function buildAzureData(array $mail, array $azure): array
    {
        return [
            'tenant_id' => (string) ($mail['azure_tenant_id'] ?? ''),
            'client_id' => (string) ($mail['azure_client_id'] ?? ''),
            'client_secret_configured' => $this->settings->getString('mail', 'azure_client_secret') !== '',
            'mailbox' => (string) ($mail['azure_mailbox'] ?? ''),
            'scope' => (string) ($mail['azure_scope'] ?? ($azure['scope'] ?? 'https://outlook.office365.com/.default')),
            'token_endpoint' => (string) ($mail['azure_token_endpoint'] ?? ($azure['token_endpoint'] ?? '')),
            'configured' => !empty($azure['configured']),
        ];
    }

    /** @param array<string, mixed> $graph */
    /** @param array<string, mixed> $graphConfig */
    private function buildGraphData(array $graph, array $graphConfig): array
    {
        return [
            'tenant_id' => (string) ($graph['tenant_id'] ?? ''),
            'client_id' => (string) ($graph['client_id'] ?? ''),
            'client_secret_configured' => $this->settings->getString('graph', 'client_secret') !== '',
            'scope' => (string) ($graph['scope'] ?? ($graphConfig['scope'] ?? 'https://graph.microsoft.com/.default')),
            'base_url' => (string) ($graph['base_url'] ?? ($graphConfig['base_url'] ?? 'https://graph.microsoft.com/v1.0')),
            'token_endpoint' => (string) ($graph['token_endpoint'] ?? ($graphConfig['token_endpoint'] ?? '')),
            'configured' => !empty($graphConfig['configured']),
        ];
    }

    /** @return array<string, int> */
    private function defaultQueueStats(): array
    {
        return [
            'pending' => 0,
            'processing' => 0,
            'sent' => 0,
            'failed' => 0,
        ];
    }

    private function sanitizeEmail(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $value = function_exists('mb_substr') ? mb_substr($value, 0, self::MAX_TEXT_LENGTH) : substr($value, 0, self::MAX_TEXT_LENGTH);

        return filter_var($value, FILTER_VALIDATE_EMAIL) ? $value : '';
    }

    private function sanitizeText(string $value, int $maxLength): string
    {
        $value = trim(strip_tags($value));
        if ($value === '') {
            return '';
        }

        return function_exists('mb_substr') ? mb_substr($value, 0, $maxLength) : substr($value, 0, $maxLength);
    }

    private function sanitizeHost(string $value): string
    {
        $value = strtolower(trim($value));
        if ($value === '') {
            return '';
        }

        $value = function_exists('mb_substr') ? mb_substr($value, 0, self::MAX_HOST_LENGTH) : substr($value, 0, self::MAX_HOST_LENGTH);
        if (preg_match('/^[a-z0-9.-]+$/', $value) !== 1) {
            return '';
        }

        return trim($value, '.');
    }

    private function sanitizeUrl(string $value, bool $allowEmpty = false): string
    {
        $value = trim($value);
        if ($value === '') {
            return $allowEmpty ? '' : '';
        }

        $value = filter_var($value, FILTER_SANITIZE_URL) ?: '';
        $value = function_exists('mb_substr') ? mb_substr($value, 0, self::MAX_URL_LENGTH) : substr($value, 0, self::MAX_URL_LENGTH);
        if ($value === '' || filter_var($value, FILTER_VALIDATE_URL) === false) {
            return '';
        }

        $parts = parse_url($value);
        if (!is_array($parts)) {
            return '';
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = trim((string) ($parts['host'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true) || $host === '' || isset($parts['user']) || isset($parts['pass'])) {
            return '';
        }

        $normalized = $scheme . '://' . $host;
        if (isset($parts['port'])) {
            $normalized .= ':' . (int) $parts['port'];
        }

        $path = trim((string) ($parts['path'] ?? ''));
        if ($path !== '') {
            $normalized .= '/' . ltrim($path, '/');
        }

        return $normalized;
    }

    private function normalizeAuthMode(string $value): string
    {
        $value = strtolower(trim($value));

        return $value === self::AUTH_MODE_OAUTH2 ? self::AUTH_MODE_OAUTH2 : self::AUTH_MODE_CREDENTIALS;
    }

    private function maskEmail(string $email): string
    {
        $email = trim($email);
        if ($email === '' || !str_contains($email, '@')) {
            return '';
        }

        [$local, $domain] = explode('@', $email, 2);
        if ($local === '' || $domain === '') {
            return '';
        }

        $first = function_exists('mb_substr') ? mb_substr($local, 0, 1) : substr($local, 0, 1);

        return $first . '***@' . strtolower($domain);
    }

    private function maskIdentifier(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $length = function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
        if ($length <= 6) {
            return '***';
        }

        $prefix = function_exists('mb_substr') ? mb_substr($value, 0, 3) : substr($value, 0, 3);
        $suffix = function_exists('mb_substr') ? mb_substr($value, -3) : substr($value, -3);

        return $prefix . '***' . $suffix;
    }

    private function maskHost(string $host): string
    {
        $host = trim($host);
        if ($host === '') {
            return '';
        }

        $parts = explode('.', $host);
        if (count($parts) < 2) {
            return '***';
        }

        return '***.' . implode('.', array_slice($parts, -2));
    }

    private function maskUrl(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return '';
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        $host = $this->maskHost((string) ($parts['host'] ?? ''));
        if ($host === '') {
            return '';
        }

        return $scheme . '://' . $host;
    }

    /** @param array<string, mixed> $result */
    private function summarizeQueueResult(array $result): array
    {
        return [
            'success' => !empty($result['success']),
            'processed' => max(0, (int) ($result['processed'] ?? 0)),
            'sent' => max(0, (int) ($result['sent'] ?? 0)),
            'failed' => max(0, (int) ($result['failed'] ?? 0)),
            'message' => $this->sanitizeText((string) ($result['message'] ?? ''), self::MAX_TEXT_LENGTH),
        ];
    }

    /**
     * @param array<string, mixed> $result
     * @param list<string> $allowedExtraKeys
     * @return array<string, mixed>
     */
    private function sanitizeActionResponse(array $result, array $allowedExtraKeys = []): MailSettingsActionResult
    {
        $sanitized = [
            'success' => !empty($result['success']),
        ];

        if (isset($result['message'])) {
            $sanitized['message'] = $this->sanitizeText((string) $result['message'], self::MAX_TEXT_LENGTH);
        }

        if (isset($result['error'])) {
            $sanitized['error'] = $this->sanitizeText((string) $result['error'], self::MAX_TEXT_LENGTH);
        }

        foreach ($allowedExtraKeys as $key) {
            if (!array_key_exists($key, $result)) {
                continue;
            }

            $value = $result[$key];
            if (is_string($value)) {
                $sanitized[$key] = $this->sanitizeText($value, self::MAX_TEXT_LENGTH);
                continue;
            }

            if (is_int($value) || is_float($value) || is_bool($value) || $value === null) {
                $sanitized[$key] = $value;
            }
        }

        return new MailSettingsActionResult($sanitized);
    }

    private function failResult(string $action, string $message, \Throwable $e): MailSettingsActionResult
    {
        Logger::error($message, [
            'module' => 'MailSettingsModule',
            'action' => $action,
            'exception' => $e::class,
        ]);

        AuditLogger::instance()->log(
            AuditLogger::CAT_SETTING,
            $action,
            $message,
            'setting',
            null,
            ['exception' => $e::class],
            'error'
        );

        return $this->errorResult($message . ' Bitte Logs prüfen.');
    }

    private function denyResult(): MailSettingsActionResult
    {
        return $this->errorResult('Zugriff verweigert.');
    }

    private function successResult(string $message, array $extra = []): MailSettingsActionResult
    {
        return new MailSettingsActionResult(array_merge(['success' => true, 'message' => $message], $extra));
    }

    private function errorResult(string $message, array $extra = []): MailSettingsActionResult
    {
        return new MailSettingsActionResult(array_merge(['success' => false, 'error' => $message], $extra));
    }
}
