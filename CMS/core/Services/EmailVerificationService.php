<?php
/**
 * E-Mail-Verifizierung neuer Konten (Option `member_email_verification`).
 *
 * Ist die Option aktiv, legt Auth::register() Konten mit dem Status `unverified` an und verschickt
 * einen Bestätigungslink. Erst der Link (oder ein abgeschlossener Passwort-Reset, der ebenfalls den
 * Zugriff auf das Postfach belegt) setzt das Konto auf `active`. Bis dahin scheitern Login, Passkey,
 * Remember-me und Sitzungen an der bestehenden Statusprüfung (`status = 'active'`).
 *
 * @package 365CMS
 */

declare(strict_types=1);

namespace CMS\Services;

use CMS\AuditLogger;
use CMS\Database;
use CMS\Hooks;
use CMS\Logger;
use CMS\Router;
use CMS\Security;

if (!defined('ABSPATH')) {
    exit;
}

final class EmailVerificationService
{
    public const OPTION = 'member_email_verification';
    public const STATUS_UNVERIFIED = 'unverified';
    public const PATH = '/cms-verify-email';
    public const TOKEN_TTL_SECONDS = 172800;

    private const META_TOKEN = 'email_verification_token';
    private const META_EXPIRES = 'email_verification_expires';
    private const META_VERIFIED_AT = 'email_verified_at';

    private const RESEND_LIMIT = 3;
    private const RESEND_WINDOW = 3600;
    private const VERIFY_IP_LIMIT = 10;
    private const VERIFY_IP_WINDOW = 900;

    private static ?self $instance = null;

    private Database $db;
    private string $prefix;
    private Logger $logger;

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    private function __construct()
    {
        $this->db = Database::instance();
        $this->prefix = $this->db->getPrefix();
        $this->logger = Logger::instance()->withChannel('auth.email-verification');
    }

    public function isRequired(): bool
    {
        return OptionStore::getInstance()->get(self::OPTION, '0') === '1';
    }

    /**
     * Erzeugt einen neuen Bestätigungslink (ein älterer wird ungültig) und verschickt ihn.
     */
    public function sendVerificationMail(int $userId, ?string $locale = null): bool
    {
        $user = $this->findUnverifiedUser($userId);
        if ($user === null || trim((string) ($user->email ?? '')) === '') {
            return false;
        }

        $token = bin2hex(random_bytes(32));
        $this->setMeta($userId, self::META_TOKEN, hash('sha256', $token));
        $this->setMeta($userId, self::META_EXPIRES, (string) (time() + self::TOKEN_TTL_SECONDS));

        $siteName = defined('SITE_NAME') ? (string) SITE_NAME : '365CMS';
        $name = trim((string) ($user->display_name ?? ''));
        $name = $name !== '' ? $name : (string) ($user->username ?? '');
        $subject = '[' . $siteName . '] E-Mail-Adresse bestätigen';
        $body = "Hallo {$name},\n\n"
            . "bitte bestätige deine E-Mail-Adresse, um dein Konto bei {$siteName} zu aktivieren.\n\n"
            . 'Klicke auf den folgenden Link (gültig für ' . intdiv(self::TOKEN_TTL_SECONDS, 3600) . " Stunden):\n"
            . $this->buildVerificationUrl($userId, $token, $locale) . "\n\n"
            . "Falls du dich nicht registriert hast, kannst du diese E-Mail ignorieren.\n\n"
            . "Viele Grüße\n{$siteName}";

        $sent = false;
        try {
            $sent = function_exists('cms_mail')
                && \cms_mail((string) $user->email, $subject, $body, ['Content-Type' => 'text/plain; charset=UTF-8']);
        } catch (\Throwable $e) {
            $this->logger->error('Bestätigungs-E-Mail konnte nicht versendet werden.', [
                'user_id' => $userId,
                'exception' => $e->getMessage(),
            ]);
            return false;
        }

        if (!$sent) {
            $this->logger->warning('Bestätigungs-E-Mail wurde vom Mailer nicht angenommen.', ['user_id' => $userId]);
        }

        return $sent;
    }

    /**
     * Neuer Link für ein unbestätigtes Konto (Login mit korrektem Passwort); höchstens
     * RESEND_LIMIT Mails je Konto und Stunde.
     */
    public function resend(int $userId, ?string $locale = null): bool
    {
        if ($this->findUnverifiedUser($userId) === null) {
            return false;
        }

        $identifier = 'uid:' . $userId;
        if (!$this->checkIdentifierRateLimit($identifier, 'email_verify_resend', self::RESEND_LIMIT, self::RESEND_WINDOW)) {
            return false;
        }
        Security::recordDbRateLimitAttempt(Security::getClientIp(), 'email_verify_resend', $identifier);

        return $this->sendVerificationMail($userId, $locale);
    }

    /**
     * Prüft einen Bestätigungslink und aktiviert das Konto.
     *
     * @return array{success:bool,message:string}
     */
    public function verify(int $userId, string $token): array
    {
        $invalid = ['success' => false, 'message' => 'Ungültiger oder bereits verwendeter Bestätigungslink.'];
        $token = strtolower(trim($token));
        if ($userId <= 0 || preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            return $invalid;
        }

        $clientIp = Security::getClientIp();
        if (!Security::checkDbRateLimit($clientIp, 'email_verify', self::VERIFY_IP_LIMIT, self::VERIFY_IP_WINDOW)) {
            $this->logger->warning('E-Mail-Bestätigung wegen Rate-Limit geblockt.', ['ip' => $clientIp]);
            return ['success' => false, 'message' => 'Zu viele Bestätigungsversuche. Bitte versuchen Sie es in einigen Minuten erneut.'];
        }
        Security::recordDbRateLimitAttempt($clientIp, 'email_verify');

        try {
            $storedHash = $this->findUnverifiedUser($userId) !== null
                ? (string) ($this->getMeta($userId, self::META_TOKEN) ?? '')
                : '';
            if ($storedHash === '' || !hash_equals($storedHash, hash('sha256', $token))) {
                return $invalid;
            }

            if ((int) ($this->getMeta($userId, self::META_EXPIRES) ?? 0) < time()) {
                return ['success' => false, 'message' => 'Dieser Bestätigungslink ist abgelaufen. Melden Sie sich an, um einen neuen Link zu erhalten.'];
            }

            if (!$this->activate($userId, 'link')) {
                return $invalid;
            }
        } catch (\Throwable $e) {
            $this->logger->error('E-Mail-Bestätigung fehlgeschlagen.', [
                'user_id' => $userId,
                'exception' => $e->getMessage(),
            ]);
            return ['success' => false, 'message' => 'Die Bestätigung konnte gerade nicht verarbeitet werden.'];
        }

        return ['success' => true, 'message' => 'Ihre E-Mail-Adresse ist bestätigt. Sie können sich jetzt anmelden.'];
    }

    /**
     * Ein abgeschlossener Passwort-Reset belegt den Zugriff auf das Postfach und bestätigt damit
     * auch ein noch unbestätigtes Konto.
     */
    public function markVerifiedByEmail(string $email): bool
    {
        $userId = (int) $this->db->get_var(
            "SELECT id FROM {$this->prefix}users WHERE email = ? AND status = ? LIMIT 1",
            [$email, self::STATUS_UNVERIFIED]
        );

        return $userId > 0 && $this->activate($userId, 'password_reset');
    }

    private function activate(int $userId, string $via): bool
    {
        $stmt = $this->db->execute(
            "UPDATE {$this->prefix}users SET status = 'active' WHERE id = ? AND status = ?",
            [$userId, self::STATUS_UNVERIFIED]
        );
        if ($stmt->rowCount() < 1) {
            return false;
        }

        $this->db->execute(
            "DELETE FROM {$this->prefix}user_meta WHERE user_id = ? AND meta_key IN (?, ?)",
            [$userId, self::META_TOKEN, self::META_EXPIRES]
        );
        $this->setMeta($userId, self::META_VERIFIED_AT, date('Y-m-d H:i:s'));

        AuditLogger::instance()->log(
            AuditLogger::CAT_AUTH,
            'auth.email_verified',
            'E-Mail-Adresse bestätigt, Konto aktiviert',
            'user',
            $userId,
            ['via' => $via],
            'info'
        );

        // Folgeaudit OFFEN-22: Gastbestellungen erst mit bestätigter Adresse dem Konto zuordnen (vgl. SEC-36).
        $email = (string) ($this->db->get_var("SELECT email FROM {$this->prefix}users WHERE id = ?", [$userId]) ?? '');
        EmailChangeService::getInstance()->assignGuestOrders($userId, $email);

        Hooks::doAction('user_email_verified', $userId);

        return true;
    }

    private function buildVerificationUrl(int $userId, string $token, ?string $locale): string
    {
        $path = self::PATH;
        try {
            $path = ContentLocalizationService::getInstance()->buildLocalizedPath($path, $this->resolveLocale($locale));
        } catch (\Throwable) {
        }

        $siteUrl = rtrim(defined('SITE_URL') ? (string) SITE_URL : '', '/');

        return $siteUrl . $path . '?' . http_build_query(['uid' => $userId, 'token' => $token], '', '&', PHP_QUERY_RFC3986);
    }

    private function resolveLocale(?string $locale): string
    {
        $locale = trim((string) $locale);
        if ($locale === '' && PHP_SAPI !== 'cli') {
            try {
                $locale = Router::instance()->getRequestLocale();
            } catch (\Throwable) {
                $locale = '';
            }
        }

        return $locale !== '' ? $locale : 'de';
    }

    private function findUnverifiedUser(int $userId): ?object
    {
        if ($userId <= 0) {
            return null;
        }

        return $this->db->get_row(
            "SELECT id, username, email, display_name FROM {$this->prefix}users WHERE id = ? AND status = ? LIMIT 1",
            [$userId, self::STATUS_UNVERIFIED]
        );
    }

    private function checkIdentifierRateLimit(string $identifier, string $action, int $maxAttempts, int $timeWindow): bool
    {
        try {
            $count = (int) $this->db->get_var(
                "SELECT COUNT(*) FROM {$this->prefix}login_attempts WHERE username = ? AND action = ? AND attempted_at >= ?",
                [
                    Security::normalizeRateLimitIdentifier($identifier),
                    Security::normalizeRateLimitAction($action),
                    date('Y-m-d H:i:s', time() - $timeWindow),
                ]
            );

            return $count < $maxAttempts;
        } catch (\Throwable) {
            return Security::checkRateLimit($action . '_' . hash('sha256', $identifier), $maxAttempts, $timeWindow);
        }
    }

    private function getMeta(int $userId, string $key): ?string
    {
        $value = $this->db->get_var(
            "SELECT meta_value FROM {$this->prefix}user_meta WHERE user_id = ? AND meta_key = ? LIMIT 1",
            [$userId, $key]
        );

        return $value !== null && $value !== false ? (string) $value : null;
    }

    private function setMeta(int $userId, string $key, string $value): void
    {
        $this->db->execute(
            "INSERT INTO {$this->prefix}user_meta (user_id, meta_key, meta_value) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)",
            [$userId, $key, $value]
        );
    }
}
