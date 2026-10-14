<?php
/**
 * Zentraler Mail-Service für 365CMS.
 *
 * Unterstützt:
 * - PHP mail() Fallback
 * - klassisches SMTP mit Benutzername/Passwort
 * - Microsoft 365 SMTP via Azure OAuth2 / XOAUTH2
 *
 * Die Laufzeitkonfiguration wird bevorzugt aus der bestehenden Tabelle
 * `{prefix}settings` gelesen und fällt bei Bedarf auf `config/app.php` zurück.
 *
 * @package CMSv2\Core\Services
 */

declare(strict_types=1);

namespace CMS\Services;

use CMS\Logger;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\Smtp\Auth\XOAuth2Authenticator;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

if (!defined('ABSPATH')) {
    exit;
}

class MailService
{
    private const AUTH_MODE_CREDENTIALS = 'credentials';
    private const AUTH_MODE_OAUTH2 = 'oauth2';
    private const MAX_SUBJECT_LENGTH = 255;
    private const MAX_BODY_LENGTH = 200000;
    private const MAX_ERROR_LENGTH = 500;
    private const BLOCKED_HEADER_NAMES = [
        'to',
        'subject',
        'date',
        'return-path',
        'received',
        'message-id',
        'resent-to',
        'resent-from',
        'resent-sender',
        'resent-cc',
        'resent-bcc',
    ];

    private static ?self $instance = null;

    private SettingsService $settings;
    private MailLogService $mailLogs;
    private Logger $logger;

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    private function __construct()
    {
        $this->settings = SettingsService::getInstance();
        $this->mailLogs = MailLogService::getInstance();
        $this->logger = Logger::instance()->withChannel('mail');
    }

    /**
     * @return array<string, bool|int|string>
     */
    public function getTransportInfo(): array
    {
        $config = $this->getEffectiveConfig();

        return [
            'uses_smtp' => $config['use_smtp'],
            'transport' => $config['driver'],
            'transport_label' => $config['transport_label'],
            'provider' => $config['provider'],
            'host' => $config['smtp_host'],
            'port' => $config['smtp_port'],
            'encryption' => $config['smtp_encryption'] !== '' ? $config['smtp_encryption'] : 'none',
            'encryption_raw' => $config['smtp_encryption'],
            'username' => $config['smtp_username'],
            'from_email' => $config['from_email'],
            'from_name' => $config['from_name'],
            'auth_mode' => $config['auth_mode'],
            'auth_mode_label' => $config['auth_mode'] === self::AUTH_MODE_OAUTH2 ? 'Azure OAuth2 / XOAUTH2' : 'Benutzername + Passwort',
        ];
    }

    /**
     * @return array{success:bool,message?:string,error?:string,transport?:string}
     */
    public function sendBackendTestEmail(string $to, string $source = 'admin'): array
    {
        $recipient = trim($to);
        if (filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
            return ['success' => false, 'error' => 'Bitte eine gültige Empfänger-E-Mail-Adresse angeben.'];
        }

        $transport = $this->getTransportInfo();
        $sent = $this->send(
            $recipient,
            '365CMS Test-E-Mail',
            $this->buildBackendTestBody($recipient, $source, $transport),
            [
                'X-365CMS-Test-Mail' => '1',
                'X-365CMS-Test-Source' => $source,
            ]
        );

        if (!$sent) {
            return [
                'success' => false,
                'error' => 'Die Test-E-Mail konnte nicht versendet werden. Bitte Transport, Authentifizierung und Absenderkonfiguration prüfen.',
                'transport' => (string) ($transport['transport_label'] ?? ''),
            ];
        }

        return [
            'success' => true,
            'message' => 'Test-E-Mail erfolgreich an ' . $recipient . ' versendet (' . ($transport['transport_label'] ?? 'Mailversand') . ').',
            'transport' => (string) ($transport['transport_label'] ?? ''),
        ];
    }

    /**
     * @return array{success:bool,message?:string,error?:string,id?:int}
     */
    public function queueBackendTestEmail(string $to, string $source = 'admin-queue'): array
    {
        $recipient = trim($to);
        if (filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
            return ['success' => false, 'error' => 'Bitte eine gültige Empfänger-E-Mail-Adresse angeben.'];
        }

        $transport = $this->getTransportInfo();

        return MailQueueService::getInstance()->enqueue(
            $recipient,
            '365CMS Test-E-Mail (Queue)',
            $this->buildBackendTestBody($recipient, $source, $transport),
            [
                'X-365CMS-Test-Mail' => '1',
                'X-365CMS-Test-Source' => $source,
            ],
            null,
            $source
        );
    }

    /**
     * @return array{success:bool,error?:string,transport?:string,provider?:string,source?:string,retryable?:bool,error_category?:string,recommended_delay?:int,message_id?:string}
     */
    public function sendDetailed(string $to, string $subject, string $htmlBody, array $headers = []): array
    {
        try {
            $config = $this->getEffectiveConfig();
            if (!$config['use_smtp'] && !$this->isSymfonyMimeAvailable()) {
                return $this->dispatchMailFallbackDetailed($to, $subject, $htmlBody, $headers, true);
            }

            $this->assertSymfonyMimeAvailableForConfig($config);

            $htmlBody = $this->normalizeBody($htmlBody);
            $plainBody = $this->createPlainTextBody($htmlBody);
            $email = $this->createBaseEmail($to, $subject, $headers)
                ->html($htmlBody)
                ->text($plainBody);

            return $this->dispatchMessageDetailed($to, $subject, $email, function () use ($to, $subject, $htmlBody, $headers): bool {
                return $this->sendMessageFallback($to, $subject, $htmlBody, $headers, true);
            }, $headers);
        } catch (\Throwable $e) {
            $config = $this->getEffectiveConfig();
            $classification = $this->classifyDeliveryFailure($e->getMessage(), $config);
            $source = $this->resolveSource($headers);
            $publicError = $this->buildPublicErrorMessage($classification, $config);

            $this->logFailure($to, $subject, 'mail_exception', $e->getMessage(), $headers, $source, $config);

            return [
                'success' => false,
                'error' => $publicError,
                'transport' => (string) ($config['transport_label'] ?? 'Mailversand'),
                'provider' => (string) ($config['provider'] ?? 'mail'),
                'source' => $source,
                'retryable' => (bool) ($classification['retryable'] ?? false),
                'error_category' => (string) ($classification['category'] ?? 'temporary'),
                'recommended_delay' => (int) ($classification['recommended_delay'] ?? 300),
            ];
        }
    }

    /**
     * @return array{success:bool,error?:string,transport?:string,provider?:string,source?:string,retryable?:bool,error_category?:string,recommended_delay?:int,message_id?:string}
     */
    public function sendPlainDetailed(string $to, string $subject, string $plainBody, array $headers = []): array
    {
        try {
            $config = $this->getEffectiveConfig();
            if (!$config['use_smtp'] && !$this->isSymfonyMimeAvailable()) {
                return $this->dispatchMailFallbackDetailed($to, $subject, $plainBody, $headers, false);
            }

            $this->assertSymfonyMimeAvailableForConfig($config);

            $plainBody = $this->normalizeBody($plainBody);
            $email = $this->createBaseEmail($to, $subject, $headers)
                ->text($plainBody);

            return $this->dispatchMessageDetailed($to, $subject, $email, function () use ($to, $subject, $plainBody, $headers): bool {
                return $this->sendMessageFallback($to, $subject, $plainBody, $headers, false);
            }, $headers);
        } catch (\Throwable $e) {
            $config = $this->getEffectiveConfig();
            $classification = $this->classifyDeliveryFailure($e->getMessage(), $config);
            $source = $this->resolveSource($headers);
            $publicError = $this->buildPublicErrorMessage($classification, $config);

            $this->logFailure($to, $subject, 'mail_exception', $e->getMessage(), $headers, $source, $config);

            return [
                'success' => false,
                'error' => $publicError,
                'transport' => (string) ($config['transport_label'] ?? 'Mailversand'),
                'provider' => (string) ($config['provider'] ?? 'mail'),
                'source' => $source,
                'retryable' => (bool) ($classification['retryable'] ?? false),
                'error_category' => (string) ($classification['category'] ?? 'temporary'),
                'recommended_delay' => (int) ($classification['recommended_delay'] ?? 300),
            ];
        }
    }

    /**
     * @return array{success:bool,error?:string,transport?:string,provider?:string,source?:string,retryable?:bool,error_category?:string,recommended_delay?:int,message_id?:string}
     */
    public function sendWithAttachmentDetailed(
        string $to,
        string $subject,
        string $body,
        string $attachmentPath,
        string $attachmentName = '',
        bool $isHtml = true,
        array $headers = [],
        string $attachmentMimeType = ''
    ): array {
        if (!file_exists($attachmentPath) || !is_readable($attachmentPath)) {
            return [
                'success' => false,
                'error' => 'Anhang ist nicht lesbar.',
                'retryable' => false,
                'error_category' => 'configuration',
                'recommended_delay' => 0,
            ];
        }

        try {
            $config = $this->getEffectiveConfig();
            if (!$config['use_smtp'] && !$this->isSymfonyMimeAvailable()) {
                return $this->dispatchMailAttachmentFallbackDetailed($to, $subject, $body, $attachmentPath, $attachmentName, $isHtml, $headers);
            }

            $this->assertSymfonyMimeAvailableForConfig($config);

            $body = $this->normalizeBody($body);
            $attachmentName = $attachmentName !== '' ? $attachmentName : basename($attachmentPath);
            $mimeType = $attachmentMimeType !== '' ? $attachmentMimeType : (mime_content_type($attachmentPath) ?: 'application/octet-stream');
            $email = $this->createBaseEmail($to, $subject, $headers)
                ->attachFromPath($attachmentPath, $attachmentName, $mimeType);

            if ($isHtml) {
                $email
                    ->html($body)
                    ->text($this->createPlainTextBody($body));
            } else {
                $email->text($body);
            }

            return $this->dispatchMessageDetailed($to, $subject, $email, function () use ($to, $subject, $body, $attachmentPath, $attachmentName, $isHtml, $headers): bool {
                return $this->sendWithAttachmentFallback($to, $subject, $body, $attachmentPath, $attachmentName, $isHtml, $headers);
            }, $headers);
        } catch (\Throwable $e) {
            $config = $this->getEffectiveConfig();
            $classification = $this->classifyDeliveryFailure($e->getMessage(), $config);
            $source = $this->resolveSource($headers);
            $publicError = $this->buildPublicErrorMessage($classification, $config);

            $this->logFailure($to, $subject, 'mail_exception', $e->getMessage(), $headers, $source, $config);

            return [
                'success' => false,
                'error' => $publicError,
                'transport' => (string) ($config['transport_label'] ?? 'Mailversand'),
                'provider' => (string) ($config['provider'] ?? 'mail'),
                'source' => $source,
                'retryable' => (bool) ($classification['retryable'] ?? false),
                'error_category' => (string) ($classification['category'] ?? 'temporary'),
                'recommended_delay' => (int) ($classification['recommended_delay'] ?? 300),
            ];
        }
    }

    /**
     * @return array{success:bool,message?:string,error?:string,id?:int}
     */
    public function queueWithAttachment(
        string $to,
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
        return MailQueueService::getInstance()->enqueueWithAttachment(
            $to,
            $subject,
            $body,
            $attachmentPath,
            $attachmentName,
            $isHtml,
            $headers,
            $availableAt,
            $source,
            $maxAttempts
        );
    }

    public function send(string $to, string $subject, string $htmlBody, array $headers = []): bool
    {
        $result = $this->sendDetailed($to, $subject, $htmlBody, $headers);

        return !empty($result['success']);
    }

    public function sendPlain(string $to, string $subject, string $plainBody, array $headers = []): bool
    {
        $result = $this->sendPlainDetailed($to, $subject, $plainBody, $headers);

        return !empty($result['success']);
    }

    public function sendWithAttachment(
        string $to,
        string $subject,
        string $htmlBody,
        string $attachmentPath,
        string $attachmentName = '',
        bool $isHtml = true,
        array $headers = []
    ): bool {
        $result = $this->sendWithAttachmentDetailed($to, $subject, $htmlBody, $attachmentPath, $attachmentName, $isHtml, $headers);

        return !empty($result['success']);
    }

    private function createBaseEmail(string $to, string $subject, array $headers = []): Email
    {
        $config = $this->getEffectiveConfig();
        $recipient = $this->sanitizeRequiredEmail($to, 'Empfänger-Adresse ist ungültig.');
        $normalizedSubject = $this->sanitizeSubject($subject);
        $fromEmail = $this->sanitizeSenderEmail((string) $config['from_email']);
        $fromName = $this->sanitizeDisplayName((string) $config['from_name']);

        $email = (new Email())
            ->from($this->formatAddress($fromEmail, $fromName))
            ->replyTo($fromEmail)
            ->to($recipient)
            ->subject($normalizedSubject)
            ->date(new \DateTimeImmutable());

        $email->getHeaders()->addTextHeader('X-Mailer', '365CMS/' . (defined('CMS_VERSION') ? CMS_VERSION : '2.0'));

        $this->applyHeaders($email, $headers);

        return $email;
    }


    /**
     * @return array{success:bool,error?:string,transport?:string,provider?:string,source?:string,retryable?:bool,error_category?:string,recommended_delay?:int,message_id?:string}
     */
    private function dispatchMessageDetailed(string $to, string $subject, Email $email, callable $fallback, array $headers = []): array
    {
        $config = $this->getEffectiveConfig();
        $source = $this->resolveSource($headers);

        if ($config['use_smtp']) {
            $result = $this->sendViaSymfony($email, $config);
            if (!empty($result['success'])) {
                $this->logSuccess($to, $subject, $config, $result['message_id'] ?? null, $headers, $source);

                return [
                    'success' => true,
                    'transport' => (string) ($config['transport_label'] ?? 'SMTP'),
                    'provider' => (string) ($config['provider'] ?? 'smtp'),
                    'source' => $source,
                    'message_id' => isset($result['message_id']) ? (string) $result['message_id'] : null,
                ];
            }

            $error = (string) ($result['error'] ?? 'Unbekannter SMTP-Fehler');
            $classification = [
                'retryable' => !empty($result['retryable']),
                'category' => (string) ($result['error_category'] ?? 'temporary'),
                'recommended_delay' => (int) ($result['recommended_delay'] ?? 300),
            ];
            $this->logFailure($to, $subject, (string) ($config['provider'] ?? 'smtp'), $error, $headers, $source, $config);

            return [
                'success' => false,
                'error' => $this->buildPublicErrorMessage($classification, $config),
                'transport' => (string) ($config['transport_label'] ?? 'SMTP'),
                'provider' => (string) ($config['provider'] ?? 'smtp'),
                'source' => $source,
                'retryable' => !empty($result['retryable']),
                'error_category' => (string) ($result['error_category'] ?? 'temporary'),
                'recommended_delay' => (int) ($result['recommended_delay'] ?? 300),
            ];
        }

        $success = (bool) $fallback();
        if ($success) {
            $this->logSuccess($to, $subject, $config, null, $headers, $source);

            return [
                'success' => true,
                'transport' => (string) ($config['transport_label'] ?? 'PHP mail() Fallback'),
                'provider' => (string) ($config['provider'] ?? 'mail'),
                'source' => $source,
            ];
        }

        $error = 'PHP mail() konnte die Nachricht nicht versenden.';
        $classification = $this->classifyDeliveryFailure($error, $config);
        $this->logFailure($to, $subject, 'mail', $error, $headers, $source, $config);

        return [
            'success' => false,
            'error' => $error,
            'transport' => (string) ($config['transport_label'] ?? 'PHP mail() Fallback'),
            'provider' => (string) ($config['provider'] ?? 'mail'),
            'source' => $source,
            'retryable' => (bool) ($classification['retryable'] ?? false),
            'error_category' => (string) ($classification['category'] ?? 'temporary'),
            'recommended_delay' => (int) ($classification['recommended_delay'] ?? 300),
        ];
    }

    /**
     * @param array<string, mixed> $config
     * @return array{success:bool,message_id?:string,error?:string,retryable?:bool,error_category?:string,recommended_delay?:int}
     */
    private function sendViaSymfony(Email $email, array $config): array
    {
        $transport = $this->createTransport($config);

        try {
            $sentMessage = $transport->send($email);
            return [
                'success' => true,
                'message_id' => $this->extractMessageId($sentMessage),
            ];
        } catch (\Throwable $e) {
            $this->logger->error('Symfony-Mailer-Fehler: {message}', [
                'message' => $e->getMessage(),
                'provider' => $config['provider'],
                'host' => $config['smtp_host'],
            ]);

            $classification = $this->classifyDeliveryFailure($e->getMessage(), $config);
            if (!empty($classification['clear_token_cache']) && (string) ($config['auth_mode'] ?? '') === 'oauth2') {
                AzureMailTokenProvider::getInstance()->clearCache();
            }

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'retryable' => (bool) ($classification['retryable'] ?? false),
                'error_category' => (string) ($classification['category'] ?? 'temporary'),
                'recommended_delay' => (int) ($classification['recommended_delay'] ?? 300),
            ];
        } finally {
            try {
                $transport->stop();
            } catch (\Throwable) {
            }
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    private function createTransport(array $config): EsmtpTransport
    {
        $encryption = strtolower(trim((string) $config['smtp_encryption']));
        $transport = new EsmtpTransport(
            (string) $config['smtp_host'],
            (int) $config['smtp_port'],
            $encryption === 'ssl'
        );

        if ($encryption === 'tls') {
            $transport->setRequireTls(true);
        } else {
            $transport->setAutoTls(false);
        }

        $username = trim((string) $config['smtp_username']);
        if ($username !== '') {
            $transport->setUsername($username);
        }

        if ((string) $config['auth_mode'] === 'oauth2') {
            $token = AzureMailTokenProvider::getInstance()->getAccessToken();
            $transport->setPassword((string) $token['access_token']);
            $transport->setAuthenticators([new XOAuth2Authenticator()]);
        } elseif ($username !== '' && (string) $config['smtp_password'] !== '') {
            $transport->setPassword((string) $config['smtp_password']);
        }

        $localDomain = $this->resolveLocalDomain();
        if ($localDomain !== '') {
            $transport->setLocalDomain($localDomain);
        }

        return $transport;
    }

    private function applyHeaders(Email $email, array $headers): void
    {
        foreach ($this->normalizeHeaders($headers) as $name => $value) {
            $headerName = strtolower($name);

            switch ($headerName) {
                case 'reply-to':
                    $email->replyTo(...$this->parseAddressList($value));
                    break;
                case 'cc':
                    $email->cc(...$this->parseAddressList($value));
                    break;
                case 'bcc':
                    $email->bcc(...$this->parseAddressList($value));
                    break;
                case 'from':
                    $email->from(...$this->parseAddressList($value));
                    break;
                case 'sender':
                    $addresses = $this->parseAddressList($value);
                    if ($addresses !== []) {
                        $email->sender($addresses[0]);
                    }
                    break;
                case 'content-type':
                case 'mime-version':
                case 'x-mailer':
                    break;
                default:
                    if ($this->isBlockedHeaderName($headerName)) {
                        break;
                    }

                    $email->getHeaders()->addTextHeader($name, $value);
            }
        }
    }

    /**
     * @return array<string,string>
     */
    private function normalizeHeaders(array $headers): array
    {
        $normalized = [];

        foreach ($headers as $key => $value) {
            if (is_int($key)) {
                if (!is_string($value) || !str_contains($value, ':')) {
                    continue;
                }

                [$headerName, $headerValue] = explode(':', $value, 2);
                $safeHeaderName = $this->sanitizeHeaderName($headerName);
                $safeHeaderValue = $this->sanitizeHeaderValue($headerValue);
                if ($safeHeaderName === '' || $safeHeaderValue === '') {
                    continue;
                }

                $normalized[$safeHeaderName] = $safeHeaderValue;
                continue;
            }

            if (!is_scalar($value)) {
                continue;
            }

            $safeHeaderName = $this->sanitizeHeaderName((string) $key);
            $safeHeaderValue = $this->sanitizeHeaderValue((string) $value);
            if ($safeHeaderName === '' || $safeHeaderValue === '') {
                continue;
            }

            $normalized[$safeHeaderName] = $safeHeaderValue;
        }

        return $normalized;
    }

    /**
     * @return list<string>
     */
    private function parseAddressList(string $value): array
    {
        $addresses = array_map('trim', explode(',', $value));
        $normalized = [];

        foreach ($addresses as $address) {
            if ($address === '') {
                continue;
            }

            $safeAddress = $this->sanitizeAddressHeader($address);
            if ($safeAddress === '') {
                continue;
            }

            $normalized[] = $safeAddress;
        }

        return $normalized;
    }

    private function sendMessageFallback(string $to, string $subject, string $body, array $headers, bool $isHtml): bool
    {
        $config = $this->getEffectiveConfig();
        $messageHeaders = $this->buildHeaders(
            array_merge(
                [
                    'Content-Type' => $isHtml
                        ? 'text/html; charset=UTF-8'
                        : 'text/plain; charset=UTF-8',
                ],
                $this->normalizeHeaders($headers)
            ),
            (string) $config['from_email'],
            (string) $config['from_name']
        );

        return mail($to, $subject, $body, $messageHeaders);
    }

    private function sendWithAttachmentFallback(
        string $to,
        string $subject,
        string $body,
        string $attachmentPath,
        string $attachmentName,
        bool $isHtml,
        array $headers
    ): bool {
        $config = $this->getEffectiveConfig();
        $boundary = '----=_CMS_' . bin2hex(random_bytes(16));
        $mimeType = mime_content_type($attachmentPath) ?: 'application/octet-stream';
        $plainBody = $isHtml ? $this->createPlainTextBody($body) : $body;

        $messageBody  = "--{$boundary}\r\n";
        $messageBody .= 'Content-Type: ' . ($isHtml ? 'text/html' : 'text/plain') . "; charset=UTF-8\r\n";
        $messageBody .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
        $messageBody .= quoted_printable_encode($isHtml ? $body : $plainBody) . "\r\n\r\n";

        $fileContent = chunk_split(base64_encode((string) file_get_contents($attachmentPath)));

        $messageBody .= "--{$boundary}\r\n";
        $messageBody .= "Content-Type: {$mimeType}; name=\"{$attachmentName}\"\r\n";
        $messageBody .= "Content-Transfer-Encoding: base64\r\n";
        $messageBody .= "Content-Disposition: attachment; filename=\"{$attachmentName}\"\r\n\r\n";
        $messageBody .= $fileContent . "\r\n";
        $messageBody .= "--{$boundary}--";

        $messageHeaders = $this->buildHeaders(
            array_merge(
                [
                    'Content-Type' => "multipart/mixed; boundary=\"{$boundary}\"",
                ],
                $this->normalizeHeaders($headers)
            ),
            (string) $config['from_email'],
            (string) $config['from_name']
        );

        return mail($to, $subject, $messageBody, $messageHeaders);
    }

    private function createPlainTextBody(string $htmlBody): string
    {
        return strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $htmlBody));
    }

    /**
     * @param array<string, bool|int|string> $transport
     */
    private function buildBackendTestBody(string $recipient, string $source, array $transport): string
    {
        $siteName = defined('SITE_NAME') ? (string) SITE_NAME : '365CMS';
        $siteUrl = defined('SITE_URL') ? (string) SITE_URL : '';
        $timestamp = date('d.m.Y H:i:s');

        return '<h2>365CMS Test-E-Mail</h2>'
            . '<p>Diese Nachricht wurde erfolgreich aus dem 365CMS-Backend ausgelöst.</p>'
            . '<ul>'
            . '<li><strong>Empfänger:</strong> ' . htmlspecialchars($recipient, ENT_QUOTES) . '</li>'
            . '<li><strong>Quelle:</strong> ' . htmlspecialchars($source, ENT_QUOTES) . '</li>'
            . '<li><strong>Zeitpunkt:</strong> ' . htmlspecialchars($timestamp, ENT_QUOTES) . '</li>'
            . '<li><strong>Transport:</strong> ' . htmlspecialchars((string) ($transport['transport_label'] ?? 'Mailversand'), ENT_QUOTES) . '</li>'
            . '<li><strong>Authentifizierung:</strong> ' . htmlspecialchars((string) ($transport['auth_mode_label'] ?? '—'), ENT_QUOTES) . '</li>'
            . '<li><strong>SMTP-Host:</strong> ' . htmlspecialchars((string) ($transport['host'] ?? '—'), ENT_QUOTES) . '</li>'
            . '<li><strong>SMTP-Port:</strong> ' . htmlspecialchars((string) ($transport['port'] ?? '—'), ENT_QUOTES) . '</li>'
            . '<li><strong>Verschlüsselung:</strong> ' . htmlspecialchars((string) ($transport['encryption'] ?? '—'), ENT_QUOTES) . '</li>'
            . '<li><strong>Absender:</strong> ' . htmlspecialchars((string) ($transport['from_name'] ?? ''), ENT_QUOTES) . ' &lt;' . htmlspecialchars((string) ($transport['from_email'] ?? ''), ENT_QUOTES) . '&gt;</li>'
            . '</ul>'
            . '<p><strong>Website:</strong> ' . htmlspecialchars($siteName, ENT_QUOTES)
            . ($siteUrl !== '' ? ' (<a href="' . htmlspecialchars($siteUrl, ENT_QUOTES) . '">' . htmlspecialchars($siteUrl, ENT_QUOTES) . '</a>)' : '')
            . '</p>'
            . '<p>Wenn diese Nachricht ankommt, ist die zentrale Mail-Implementierung des CMS korrekt verbunden.</p>';
    }

    /**
     * @param array<string,string> $extra
     */
    private function buildHeaders(array $extra = [], string $fromEmail = '', string $fromName = ''): string
    {
        $email = $this->sanitizeSenderEmail($fromEmail !== '' ? $fromEmail : (defined('SMTP_FROM_EMAIL') ? (string) SMTP_FROM_EMAIL : (defined('ADMIN_EMAIL') ? (string) ADMIN_EMAIL : 'noreply@localhost')));
        $name = $this->sanitizeDisplayName($fromName !== '' ? $fromName : (defined('SMTP_FROM_NAME') ? (string) SMTP_FROM_NAME : (defined('SITE_NAME') ? (string) SITE_NAME : 'CMS')));

        $headers = [
            'From' => $this->formatAddress($email, $name),
            'Reply-To' => $email,
            'MIME-Version' => '1.0',
            'X-Mailer' => '365CMS/' . (defined('CMS_VERSION') ? CMS_VERSION : '2.0'),
        ];

        foreach ($this->normalizeHeaders($extra) as $key => $value) {
            $headerName = strtolower($key);
            if ($this->isBlockedHeaderName($headerName)) {
                continue;
            }

            $headers[$key] = $value;
        }

        $lines = [];
        foreach ($headers as $nameKey => $value) {
            $lines[] = $nameKey . ': ' . $value;
        }

        return implode("\r\n", $lines);
    }

    private function formatAddress(string $email, string $name = ''): string
    {
        if ($name === '') {
            return $email;
        }

        $safeName = $this->sanitizeDisplayName($name);
        return '"' . $safeName . '" <' . $email . '>';
    }

    private function resolveLocalDomain(): string
    {
        $host = defined('SITE_URL') ? (string) parse_url((string) SITE_URL, PHP_URL_HOST) : '';

        if ($host !== '') {
            return $host;
        }

        $hostname = gethostname();
        return is_string($hostname) ? $hostname : '';
    }

    /**
     * @param array<string, mixed> $config
     */
    private function logSuccess(string $recipient, string $subject, array $config, ?string $messageId, array $headers, string $source): void
    {
        $this->mailLogs->log(
            $recipient,
            $subject,
            'sent',
            (string) ($config['driver'] ?? 'mail'),
            (string) ($config['provider'] ?? 'default'),
            $messageId,
            null,
            [
                'auth_mode' => $config['auth_mode'] ?? self::AUTH_MODE_CREDENTIALS,
                'host' => $config['smtp_host'] ?? '',
                'port' => $config['smtp_port'] ?? 0,
                'header_keys' => array_keys($this->normalizeHeaders($headers)),
            ],
            $source
        );
    }

    /**
     * @param array<string, mixed>|null $config
     */
    private function logFailure(string $recipient, string $subject, string $provider, string $error, array $headers = [], string $source = 'system', ?array $config = null): void
    {
        $transport = $config['driver'] ?? 'mail';
        $providerName = $config['provider'] ?? $provider;
        $safeError = $this->sanitizeErrorMessage($error);

        $this->logger->error('Mail-Versand fehlgeschlagen', [
            'recipient' => $recipient,
            'subject' => $subject,
            'provider' => $providerName,
            'error_text' => $safeError,
        ]);

        $this->mailLogs->log(
            $recipient,
            $subject,
            'failed',
            (string) $transport,
            (string) $providerName,
            null,
            $safeError,
            [
                'auth_mode' => $config['auth_mode'] ?? self::AUTH_MODE_CREDENTIALS,
                'host' => $config['smtp_host'] ?? '',
                'port' => $config['smtp_port'] ?? 0,
                'header_keys' => array_keys($this->normalizeHeaders($headers)),
            ],
            $source
        );
    }

    private function resolveSource(array $headers): string
    {
        $normalized = $this->normalizeHeaders($headers);
        $source = trim((string) ($normalized['X-365CMS-Test-Source'] ?? 'system'));

        return mb_substr($source !== '' ? $source : 'system', 0, 80);
    }

    private function extractMessageId(?SentMessage $sentMessage): ?string
    {
        if ($sentMessage === null) {
            return null;
        }

        $messageId = trim($sentMessage->getMessageId());
        return $messageId !== '' ? $messageId : null;
    }

    /**
     * @param array<string, mixed> $config
     * @return array{retryable:bool,category:string,recommended_delay:int,clear_token_cache:bool}
     */
    private function classifyDeliveryFailure(string $error, array $config): array
    {
        $message = strtolower(trim($error));
        $category = 'temporary';
        $retryable = true;
        $clearTokenCache = false;

        $containsAny = static function (string $haystack, array $needles): bool {
            foreach ($needles as $needle) {
                if ($needle !== '' && str_contains($haystack, $needle)) {
                    return true;
                }
            }

            return false;
        };

        if ($containsAny($message, [
            'recipient address rejected', 'mailbox unavailable', 'user unknown', 'no such user', '5.1.1', '5.1.0',
            'bad destination mailbox address', 'invalid recipient', 'relay access denied', 'not permitted to send',
        ])) {
            $category = 'recipient';
            $retryable = false;
        } elseif ($containsAny($message, [
            'invalid client', 'aadsts7000215', 'aadsts700016', 'unauthorized_client', 'invalid_scope', 'consent',
            'service principal', 'authentication unsuccessful', 'username and password not accepted', '535 5.7',
            'client secret', 'application was not found', 'tenant', 'egulias\\emailvalidator\\emailvalidator',
            'email-validator', 'symfony\\component\\mime\\address',
        ])) {
            $category = 'configuration';
            $retryable = false;
        } elseif ($containsAny($message, [
            'too many requests', 'rate limit', 'rate overlimit', 'sender rate', 'overlimit', 'throttle',
            'server busy', 'try again later', 'temporarily deferred', '4.7.', '429',
        ])) {
            $category = 'throttle';
        } elseif ($containsAny($message, [
            'expired token', 'token expired', 'invalid_token', 'temporarily unavailable', 'temporarily_unavailable',
            'token has expired', 'access token',
        ])) {
            $category = 'oauth';
            $clearTokenCache = true;
        } elseif ($containsAny($message, [
            'timed out', 'timeout', 'could not connect', 'connection refused', 'connection reset', 'broken pipe',
            'temporary failure', 'service unavailable', '421 ', '451 ', '4.4.', 'network',
        ])) {
            $category = 'network';
        }

        if ((string) ($config['auth_mode'] ?? '') === 'oauth2' && $category === 'temporary' && str_contains($message, 'oauth')) {
            $category = 'oauth';
            $clearTokenCache = true;
        }

        return [
            'retryable' => $retryable,
            'category' => $category,
            'recommended_delay' => $this->resolveRetryDelayForCategory($category),
            'clear_token_cache' => $clearTokenCache,
        ];
    }

    private function resolveRetryDelayForCategory(string $category): int
    {
        return match ($category) {
            'throttle' => max(60, $this->settings->getInt('mail', 'queue_throttle_delay_seconds', 900)),
            'oauth', 'network', 'temporary' => max(60, $this->settings->getInt('mail', 'queue_retry_delay_seconds', 300)),
            default => 0,
        };
    }

    private function isSymfonyMimeAvailable(): bool
    {
        return class_exists(Email::class)
            && class_exists(Address::class)
            && class_exists('Egulias\\EmailValidator\\EmailValidator');
    }

    private function assertSymfonyMimeAvailableForConfig(array $config): void
    {
        if ($this->isSymfonyMimeAvailable()) {
            return;
        }

        throw new \RuntimeException(
            !empty($config['use_smtp'])
                ? 'Der konfigurierte Mail-Transport benötigt die Abhängigkeit egulias/email-validator.'
                : 'Der Symfony-Mime-Stack ist nicht vollständig verfügbar.'
        );
    }

    /**
     * @return array{success:bool,error?:string,transport?:string,provider?:string,source?:string,retryable?:bool,error_category?:string,recommended_delay?:int,message_id?:string}
     */
    private function dispatchMailFallbackDetailed(string $to, string $subject, string $body, array $headers, bool $isHtml): array
    {
        $config = $this->getEffectiveConfig();
        $source = $this->resolveSource($headers);

        $recipient = $this->sanitizeRequiredEmail($to, 'Empfänger-Adresse ist ungültig.');
        $normalizedSubject = $this->sanitizeSubject($subject);
        $normalizedBody = $this->normalizeBody($body);

        $success = $this->sendMessageFallback($recipient, $normalizedSubject, $normalizedBody, $headers, $isHtml);
        if ($success) {
            $this->logSuccess($recipient, $normalizedSubject, $config, null, $headers, $source);

            return [
                'success' => true,
                'transport' => (string) ($config['transport_label'] ?? 'PHP mail() Fallback'),
                'provider' => (string) ($config['provider'] ?? 'mail'),
                'source' => $source,
            ];
        }

        $error = 'PHP mail() konnte die Nachricht nicht versenden.';
        $classification = $this->classifyDeliveryFailure($error, $config);
        $this->logFailure($recipient, $normalizedSubject, 'mail', $error, $headers, $source, $config);

        return [
            'success' => false,
            'error' => $error,
            'transport' => (string) ($config['transport_label'] ?? 'PHP mail() Fallback'),
            'provider' => (string) ($config['provider'] ?? 'mail'),
            'source' => $source,
            'retryable' => (bool) ($classification['retryable'] ?? false),
            'error_category' => (string) ($classification['category'] ?? 'temporary'),
            'recommended_delay' => (int) ($classification['recommended_delay'] ?? 300),
        ];
    }

    /**
     * @return array{success:bool,error?:string,transport?:string,provider?:string,source?:string,retryable?:bool,error_category?:string,recommended_delay?:int,message_id?:string}
     */
    private function dispatchMailAttachmentFallbackDetailed(
        string $to,
        string $subject,
        string $body,
        string $attachmentPath,
        string $attachmentName,
        bool $isHtml,
        array $headers
    ): array {
        $config = $this->getEffectiveConfig();
        $source = $this->resolveSource($headers);

        $recipient = $this->sanitizeRequiredEmail($to, 'Empfänger-Adresse ist ungültig.');
        $normalizedSubject = $this->sanitizeSubject($subject);
        $normalizedBody = $this->normalizeBody($body);
        $resolvedAttachmentName = $attachmentName !== '' ? $attachmentName : basename($attachmentPath);

        $success = $this->sendWithAttachmentFallback(
            $recipient,
            $normalizedSubject,
            $normalizedBody,
            $attachmentPath,
            $resolvedAttachmentName,
            $isHtml,
            $headers
        );

        if ($success) {
            $this->logSuccess($recipient, $normalizedSubject, $config, null, $headers, $source);

            return [
                'success' => true,
                'transport' => (string) ($config['transport_label'] ?? 'PHP mail() Fallback'),
                'provider' => (string) ($config['provider'] ?? 'mail'),
                'source' => $source,
            ];
        }

        $error = 'PHP mail() konnte die Nachricht nicht versenden.';
        $classification = $this->classifyDeliveryFailure($error, $config);
        $this->logFailure($recipient, $normalizedSubject, 'mail', $error, $headers, $source, $config);

        return [
            'success' => false,
            'error' => $error,
            'transport' => (string) ($config['transport_label'] ?? 'PHP mail() Fallback'),
            'provider' => (string) ($config['provider'] ?? 'mail'),
            'source' => $source,
            'retryable' => (bool) ($classification['retryable'] ?? false),
            'error_category' => (string) ($classification['category'] ?? 'temporary'),
            'recommended_delay' => (int) ($classification['recommended_delay'] ?? 300),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function getEffectiveConfig(): array
    {
        $driver = $this->settings->getString('mail', 'driver', defined('SMTP_HOST') && (string) SMTP_HOST !== '' ? 'smtp' : 'mail');
        $driver = $driver === 'smtp' ? 'smtp' : 'mail';

        $authMode = $this->normalizeAuthMode($this->settings->getString('mail', 'auth_mode', self::AUTH_MODE_CREDENTIALS));

        $smtpHost = $this->normalizeSmtpHost($this->settings->getString(
            'mail',
            'smtp_host',
            $authMode === 'oauth2'
                ? 'smtp.office365.com'
                : (defined('SMTP_HOST') ? (string) SMTP_HOST : '')
        ));
        if ($authMode === self::AUTH_MODE_OAUTH2 && $smtpHost === '') {
            $smtpHost = 'smtp.office365.com';
        }

        $smtpPort = $this->settings->getInt('mail', 'smtp_port', defined('SMTP_PORT') ? (int) SMTP_PORT : 587);
        $smtpEncryption = $this->normalizeEncryptionSetting($this->settings->getString('mail', 'smtp_encryption', defined('SMTP_ENCRYPTION') ? (string) SMTP_ENCRYPTION : 'tls'));

        $oauthMailbox = $this->settings->getString('mail', 'azure_mailbox');
        $smtpUsername = $this->sanitizeHeaderValue($this->settings->getString('mail', 'smtp_username', defined('SMTP_USER') ? (string) SMTP_USER : ''));
        if ($authMode === self::AUTH_MODE_OAUTH2 && $oauthMailbox !== '') {
            $smtpUsername = $this->sanitizeRequiredEmail($oauthMailbox, 'Azure-Mailbox ist ungültig.');
        }

        $smtpPassword = $this->settings->getString('mail', 'smtp_password', defined('SMTP_PASS') ? (string) SMTP_PASS : '');
        $fromEmail = $this->sanitizeSenderEmail($this->settings->getString('mail', 'from_email', defined('SMTP_FROM_EMAIL') ? (string) SMTP_FROM_EMAIL : (defined('ADMIN_EMAIL') ? (string) ADMIN_EMAIL : 'noreply@localhost')));
        $fromName = $this->sanitizeDisplayName($this->settings->getString('mail', 'from_name', defined('SMTP_FROM_NAME') ? (string) SMTP_FROM_NAME : (defined('SITE_NAME') ? (string) SITE_NAME : 'CMS')));

        $useSmtp = $driver === 'smtp' && $smtpHost !== '';
        if ($useSmtp && $this->requiresTls($smtpHost, $authMode) && $smtpEncryption === '') {
            $smtpEncryption = 'tls';
        }

        $provider = $useSmtp
            ? ($authMode === 'oauth2' ? 'smtp-oauth2' : 'smtp-basic')
            : 'mail';
        $transportLabel = match ($provider) {
            'smtp-oauth2' => 'SMTP via Symfony Mailer (Microsoft 365 OAuth2)',
            'smtp-basic' => 'SMTP via Symfony Mailer',
            default => 'PHP mail() Fallback',
        };

        return [
            'driver' => $driver,
            'use_smtp' => $useSmtp,
            'provider' => $provider,
            'transport_label' => $transportLabel,
            'auth_mode' => $authMode,
            'smtp_host' => $smtpHost,
            'smtp_port' => $smtpPort > 0 ? $smtpPort : 587,
            'smtp_encryption' => $smtpEncryption,
            'smtp_username' => $smtpUsername,
            'smtp_password' => $smtpPassword,
            'from_email' => $fromEmail,
            'from_name' => $fromName,
        ];
    }

    private function normalizeAuthMode(string $value): string
    {
        $value = strtolower(trim($value));

        return $value === self::AUTH_MODE_OAUTH2 ? self::AUTH_MODE_OAUTH2 : self::AUTH_MODE_CREDENTIALS;
    }

    private function normalizeBody(string $body): string
    {
        return mb_substr((string) $body, 0, self::MAX_BODY_LENGTH);
    }

    private function sanitizeRequiredEmail(string $email, string $errorMessage): string
    {
        $email = trim($this->sanitizeHeaderValue($email));
        $validated = filter_var($email, FILTER_VALIDATE_EMAIL);

        if ($validated === false) {
            throw new \InvalidArgumentException($errorMessage);
        }

        return (string) $validated;
    }

    private function sanitizeSenderEmail(string $email): string
    {
        $fallback = defined('ADMIN_EMAIL') && filter_var((string) ADMIN_EMAIL, FILTER_VALIDATE_EMAIL)
            ? (string) ADMIN_EMAIL
            : 'noreply@localhost';

        $email = trim($this->sanitizeHeaderValue($email));
        $validated = filter_var($email, FILTER_VALIDATE_EMAIL);

        return $validated !== false ? (string) $validated : $fallback;
    }

    private function sanitizeSubject(string $subject): string
    {
        $subject = trim($this->sanitizeHeaderValue($subject));
        $subject = preg_replace('/\s+/u', ' ', $subject) ?? $subject;
        $subject = mb_substr($subject, 0, self::MAX_SUBJECT_LENGTH);

        if ($subject === '') {
            throw new \InvalidArgumentException('Betreff fehlt.');
        }

        return $subject;
    }

    private function sanitizeDisplayName(string $name): string
    {
        $name = trim($this->sanitizeHeaderValue($name));
        $name = str_replace(['"', '\\'], '', $name);

        return mb_substr($name, 0, 120);
    }

    private function sanitizeHeaderName(string $name): string
    {
        $name = trim($name);
        if ($name === '' || preg_match('/^[A-Za-z0-9-]+$/', $name) !== 1) {
            return '';
        }

        return $name;
    }

    private function sanitizeHeaderValue(string $value): string
    {
        $value = str_replace(["\r", "\n", "\0"], ' ', $value);
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u', '', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }

    private function sanitizeAddressHeader(string $value): string
    {
        try {
            return Address::create($this->sanitizeHeaderValue($value))->toString();
        } catch (\Throwable) {
            return '';
        }
    }

    private function isBlockedHeaderName(string $headerName): bool
    {
        return in_array(strtolower($headerName), self::BLOCKED_HEADER_NAMES, true);
    }

    private function sanitizeErrorMessage(string $error): string
    {
        return mb_substr($this->sanitizeHeaderValue($error), 0, self::MAX_ERROR_LENGTH);
    }

    /**
     * @param array<string, mixed> $classification
     * @param array<string, mixed> $config
     */
    private function buildPublicErrorMessage(array $classification, array $config): string
    {
        $transport = (string) ($config['transport_label'] ?? 'Mailversand');

        return match ((string) ($classification['category'] ?? 'temporary')) {
            'recipient' => 'Die Empfänger-Adresse wurde vom Mail-Transport abgelehnt.',
            'configuration' => 'Der Mail-Transport ist aktuell nicht korrekt konfiguriert.',
            'oauth' => 'Die Mail-Authentifizierung ist abgelaufen oder aktuell nicht verfügbar.',
            'throttle' => 'Der Mail-Transport drosselt aktuell Anfragen. Bitte später erneut versuchen.',
            'network' => 'Der Mail-Transport ist derzeit nicht erreichbar (' . $transport . ').',
            default => 'Die E-Mail konnte über ' . $transport . ' derzeit nicht versendet werden.',
        };
    }

    private function normalizeSmtpHost(string $host): string
    {
        $host = trim($this->sanitizeHeaderValue($host));
        $host = trim($host, '.');

        return mb_substr($host, 0, 255);
    }

    private function normalizeEncryptionSetting(string $encryption): string
    {
        $encryption = strtolower(trim($this->sanitizeHeaderValue($encryption)));

        return in_array($encryption, ['tls', 'ssl', ''], true) ? $encryption : 'tls';
    }

    private function requiresTls(string $host, string $authMode): bool
    {
        if ($host === '') {
            return false;
        }

        if ($authMode === 'oauth2') {
            return true;
        }

        return !$this->isLocalSmtpHost($host);
    }

    private function isLocalSmtpHost(string $host): bool
    {
        $host = strtolower(trim($host));

        return in_array($host, ['localhost', '127.0.0.1', '::1', 'mailhog', 'smtp'], true)
            || str_ends_with($host, '.local');
    }
}
