<?php
/**
 * Bestätigte Änderung der E-Mail-Adresse (Folgeaudit OFFEN-22).
 *
 * Eine neue Adresse wird erst übernommen, wenn der Link an die neue Adresse geöffnet wurde. Bis dahin
 * bleibt die bisherige Adresse für Login und Passwort-Reset aktiv; sie erhält sofort einen Hinweis.
 * Erst eine bestätigte Adresse ordnet Gastbestellungen mit dieser Adresse dem Konto zu (vgl. SEC-36).
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

final class EmailChangeService
{
    public const PATH = '/cms-confirm-email-change';
    public const TOKEN_TTL_SECONDS = 172800;
    /** Spaltenbreite von `users.email`. */
    public const EMAIL_MAX_LENGTH = 100;

    public const INITIATOR_MEMBER = 'member';
    public const INITIATOR_ADMIN = 'admin';

    private const META_PENDING = 'pending_email';
    private const META_TOKEN = 'pending_email_token';
    private const META_EXPIRES = 'pending_email_expires';
    private const META_VERIFIED_AT = 'email_verified_at';

    private const REQUEST_LIMIT = 5;
    private const REQUEST_WINDOW = 3600;
    private const ADDRESS_NOTICE_LIMIT = 3;
    private const ADDRESS_NOTICE_WINDOW = 3600;
    private const CONFIRM_IP_LIMIT = 10;
    private const CONFIRM_IP_WINDOW = 900;

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
        $this->logger = Logger::instance()->withChannel('auth.email-change');
    }

    /**
     * @return true|string true oder Fehlermeldung
     */
    public static function validateAddress(string $email): bool|string
    {
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return 'Ungültige E-Mail-Adresse.';
        }

        if (strlen($email) > self::EMAIL_MAX_LENGTH) {
            return 'Die E-Mail-Adresse darf höchstens ' . self::EMAIL_MAX_LENGTH . ' Zeichen lang sein.';
        }

        return true;
    }

    /**
     * Fordert die Änderung an: Bestätigungslink an die neue, Hinweis an die bisherige Adresse.
     *
     * Gehört die neue Adresse bereits einem anderen Konto, antwortet der Mitgliederbereich wie sonst
     * auch (keine Auskunft über fremde Konten, vgl. OFFEN-24) und der Inhaber der Adresse bekommt statt
     * des Links einen Hinweis. Mit $revealConflict (Admin-Bereich) gibt es stattdessen eine Fehlermeldung.
     *
     * @return array{success:bool,message:string,pending:bool}
     */
    public function request(
        int $userId,
        string $newEmail,
        string $initiator = self::INITIATOR_MEMBER,
        bool $revealConflict = false,
        ?string $locale = null
    ): array {
        $newEmail = trim($newEmail);
        $valid = self::validateAddress($newEmail);
        if ($valid !== true) {
            return ['success' => false, 'message' => $valid, 'pending' => false];
        }

        $user = $this->findUser($userId);
        if ($user === null) {
            return ['success' => false, 'message' => 'Benutzer nicht gefunden.', 'pending' => false];
        }

        $currentEmail = (string) ($user->email ?? '');
        if (strcasecmp($currentEmail, $newEmail) === 0) {
            // Gleiches Postfach, nur andere Schreibweise: keine Bestätigung nötig.
            if ($currentEmail !== $newEmail) {
                $this->db->execute("UPDATE {$this->prefix}users SET email = ? WHERE id = ?", [$newEmail, $userId]);
            }

            return ['success' => true, 'message' => '', 'pending' => false];
        }

        $identifier = 'uid:' . $userId;
        if (!Security::checkDbIdentifierRateLimit($identifier, 'email_change', self::REQUEST_LIMIT, self::REQUEST_WINDOW)) {
            return ['success' => false, 'message' => 'Zu viele Änderungsanfragen. Bitte versuche es später erneut.', 'pending' => false];
        }
        Security::recordDbRateLimitAttempt(Security::getClientIp(), 'email_change', $identifier);

        $addressInUse = $this->isAddressUsedByOtherAccount($newEmail, $userId);
        if ($addressInUse && $revealConflict) {
            return ['success' => false, 'message' => 'Diese E-Mail-Adresse wird bereits von einem anderen Konto verwendet.', 'pending' => false];
        }

        $token = bin2hex(random_bytes(32));
        $this->setMeta($userId, self::META_PENDING, $newEmail);
        $this->setMeta($userId, self::META_TOKEN, hash('sha256', $token));
        $this->setMeta($userId, self::META_EXPIRES, (string) (time() + self::TOKEN_TTL_SECONDS));

        $delivered = $addressInUse
            ? $this->sendAddressInUseNotice($newEmail)
            : $this->sendConfirmationMail($user, $newEmail, $token, $locale);
        $noticeSent = $this->notifyCurrentAddress($currentEmail, $newEmail, $initiator);

        $this->audit('user.email_change_requested', 'Änderung der E-Mail-Adresse angefordert.', $userId, [
            'initiator' => $initiator,
            'link_sent' => $delivered && !$addressInUse,
            'address_in_use' => $addressInUse,
            'notice_sent' => $noticeSent,
        ], 'warning');

        if (!$addressInUse) {
            Hooks::doAction('user_email_change_requested', $userId, $newEmail, $initiator);
        }

        if (!$delivered) {
            return [
                'success' => true,
                'message' => 'Die Bestätigungs-E-Mail an ' . $newEmail . ' konnte gerade nicht versendet werden. Bitte versuche es später erneut; bis dahin bleibt ' . $currentEmail . ' aktiv.',
                'pending' => true,
            ];
        }

        return [
            'success' => true,
            'message' => 'Wir haben einen Bestätigungslink an ' . $newEmail . ' gesendet. Bis zur Bestätigung bleibt ' . $currentEmail . ' aktiv.',
            'pending' => true,
        ];
    }

    /**
     * Prüft den Link aus der Bestätigungs-Mail und übernimmt die neue Adresse.
     *
     * @return array{success:bool,message:string}
     */
    public function confirm(int $userId, string $token): array
    {
        $invalid = ['success' => false, 'message' => 'Ungültiger oder bereits verwendeter Bestätigungslink.'];
        $token = strtolower(trim($token));
        if ($userId <= 0 || preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            return $invalid;
        }

        $clientIp = Security::getClientIp();
        if (!Security::checkDbRateLimit($clientIp, 'email_change_confirm', self::CONFIRM_IP_LIMIT, self::CONFIRM_IP_WINDOW)) {
            $this->logger->warning('Bestätigung der E-Mail-Änderung wegen Rate-Limit geblockt.', ['ip' => $clientIp]);
            return ['success' => false, 'message' => 'Zu viele Bestätigungsversuche. Bitte versuche es in einigen Minuten erneut.'];
        }
        Security::recordDbRateLimitAttempt($clientIp, 'email_change_confirm');

        try {
            $storedHash = (string) ($this->getMeta($userId, self::META_TOKEN) ?? '');
            if ($storedHash === '' || !hash_equals($storedHash, hash('sha256', $token))) {
                return $invalid;
            }

            if ((int) ($this->getMeta($userId, self::META_EXPIRES) ?? 0) < time()) {
                $this->clearPending($userId);
                return ['success' => false, 'message' => 'Dieser Bestätigungslink ist abgelaufen. Bitte fordere die Änderung erneut an.'];
            }

            $newEmail = (string) ($this->getMeta($userId, self::META_PENDING) ?? '');
            $user = $this->findUser($userId);
            if ($user === null || self::validateAddress($newEmail) !== true) {
                $this->clearPending($userId);
                return $invalid;
            }

            if ($this->isAddressUsedByOtherAccount($newEmail, $userId)) {
                $this->clearPending($userId);
                return ['success' => false, 'message' => 'Diese E-Mail-Adresse wird inzwischen von einem anderen Konto verwendet. Die Änderung wurde verworfen.'];
            }

            $previousEmail = (string) ($user->email ?? '');
            $this->applyEmail($userId, $previousEmail, $newEmail);
            $this->setMeta($userId, self::META_VERIFIED_AT, date('Y-m-d H:i:s'));

            // Ein noch unbestätigtes Konto ist mit dem Link ebenfalls bestätigt.
            EmailVerificationService::getInstance()->markVerifiedByEmail($newEmail);
            $assignedOrders = $this->assignGuestOrders($userId, $newEmail);

            $this->audit('user.email_changed', 'E-Mail-Adresse nach Bestätigung geändert.', $userId, [
                'via' => 'confirmation',
                'guest_orders_assigned' => $assignedOrders,
            ], 'warning');
            Hooks::doAction('user_email_changed', $userId, $previousEmail, $newEmail);
        } catch (\Throwable $e) {
            $this->logger->error('Bestätigung der E-Mail-Änderung fehlgeschlagen.', [
                'user_id' => $userId,
                'exception' => $e->getMessage(),
            ]);
            return ['success' => false, 'message' => 'Die Bestätigung konnte gerade nicht verarbeitet werden.'];
        }

        return ['success' => true, 'message' => 'Deine neue E-Mail-Adresse ist bestätigt und ab sofort aktiv.'];
    }

    /**
     * Admin-Bereich: neue Adresse ausdrücklich ohne Bestätigung übernehmen. Die bisherige Adresse wird
     * benachrichtigt; Gastbestellungen werden nicht zugeordnet, weil das Postfach nicht bestätigt ist.
     *
     * @return true|string true oder Fehlermeldung
     */
    public function changeWithoutConfirmation(int $userId, string $newEmail, string $initiator = self::INITIATOR_ADMIN): bool|string
    {
        $newEmail = trim($newEmail);
        $valid = self::validateAddress($newEmail);
        if ($valid !== true) {
            return $valid;
        }

        $user = $this->findUser($userId);
        if ($user === null) {
            return 'Benutzer nicht gefunden.';
        }

        $previousEmail = (string) ($user->email ?? '');
        if ($previousEmail === $newEmail) {
            return true;
        }

        if ($this->isAddressUsedByOtherAccount($newEmail, $userId)) {
            return 'Diese E-Mail-Adresse wird bereits von einem anderen Konto verwendet.';
        }

        $this->applyEmail($userId, $previousEmail, $newEmail);
        if (strcasecmp($previousEmail, $newEmail) === 0) {
            return true;
        }

        $this->deleteMeta($userId, [self::META_VERIFIED_AT]);
        $noticeSent = $this->notifyPreviousAddressOfChange($previousEmail, $newEmail, $initiator);

        $this->audit('user.email_changed', 'E-Mail-Adresse ohne Bestätigung geändert.', $userId, [
            'via' => 'direct',
            'initiator' => $initiator,
            'notice_sent' => $noticeSent,
        ], 'warning');
        Hooks::doAction('user_email_changed', $userId, $previousEmail, $newEmail);

        return true;
    }

    public function cancel(int $userId, string $initiator = self::INITIATOR_MEMBER): bool
    {
        if ($userId <= 0 || $this->getMeta($userId, self::META_PENDING) === null) {
            return false;
        }

        $this->clearPending($userId);
        $this->audit('user.email_change_cancelled', 'Ausstehende Änderung der E-Mail-Adresse verworfen.', $userId, [
            'initiator' => $initiator,
        ], 'info');

        return true;
    }

    /**
     * @return array{email:string,expires_at:int}|null
     */
    public function getPending(int $userId): ?array
    {
        if ($userId <= 0) {
            return null;
        }

        $email = (string) ($this->getMeta($userId, self::META_PENDING) ?? '');
        if ($email === '') {
            return null;
        }

        $expiresAt = (int) ($this->getMeta($userId, self::META_EXPIRES) ?? 0);
        if ($expiresAt < time()) {
            $this->clearPending($userId);
            return null;
        }

        return ['email' => $email, 'expires_at' => $expiresAt];
    }

    /**
     * Ordnet Gastbestellungen (ohne Konto) mit der bestätigten Adresse dem Konto zu.
     */
    public function assignGuestOrders(int $userId, string $email): int
    {
        $email = trim($email);
        if ($userId <= 0 || $email === '') {
            return 0;
        }

        try {
            $count = $this->db->execute(
                "UPDATE {$this->prefix}orders SET user_id = ?
                 WHERE user_id IS NULL AND (LOWER(customer_email) = LOWER(?) OR LOWER(email) = LOWER(?))",
                [$userId, $email, $email]
            )->rowCount();
        } catch (\Throwable $e) {
            $this->logger->warning('Gastbestellungen konnten nicht zugeordnet werden.', [
                'user_id' => $userId,
                'exception' => $e->getMessage(),
            ]);
            return 0;
        }

        if ($count > 0) {
            $this->audit('orders.guest_orders_assigned', $count . ' Gastbestellung(en) nach bestätigter E-Mail-Adresse zugeordnet.', $userId, [
                'count' => $count,
            ], 'info');
            Hooks::doAction('guest_orders_assigned', $userId, $count);
        }

        return $count;
    }

    private function applyEmail(int $userId, string $previousEmail, string $newEmail): void
    {
        $this->db->execute("UPDATE {$this->prefix}users SET email = ? WHERE id = ?", [$newEmail, $userId]);
        $this->clearPending($userId);

        // Offene Reset-Links der bisherigen Adresse gelten nicht mehr.
        if ($previousEmail !== '' && strcasecmp($previousEmail, $newEmail) !== 0) {
            try {
                $this->db->execute("DELETE FROM {$this->prefix}password_resets WHERE email = ?", [$previousEmail]);
            } catch (\Throwable) {
            }
        }
    }

    private function sendConfirmationMail(object $user, string $newEmail, string $token, ?string $locale): bool
    {
        $siteName = $this->siteName();
        $name = trim((string) ($user->display_name ?? ''));
        $name = $name !== '' ? $name : (string) ($user->username ?? '');

        $body = "Hallo {$name},\n\n"
            . "diese Adresse wurde als neue E-Mail-Adresse für dein Konto bei {$siteName} angegeben. "
            . 'Bitte bestätige die Änderung über den folgenden Link (gültig für ' . intdiv(self::TOKEN_TTL_SECONDS, 3600) . " Stunden):\n"
            . $this->buildConfirmationUrl((int) $user->id, $token, $locale) . "\n\n"
            . "Bis zur Bestätigung bleibt die bisherige Adresse aktiv. Falls du diese Änderung nicht angefordert hast, kannst du diese E-Mail ignorieren.\n\n"
            . "Viele Grüße\n{$siteName}";

        return $this->sendMail($newEmail, '[' . $siteName . '] Neue E-Mail-Adresse bestätigen', $body, (int) $user->id);
    }

    /**
     * Hinweis an den Inhaber einer Adresse, die ein anderes Konto übernehmen wollte; höchstens
     * ADDRESS_NOTICE_LIMIT Mails je Adresse und Stunde.
     */
    private function sendAddressInUseNotice(string $email): bool
    {
        $identifier = 'addr:' . strtolower($email);
        if (!Security::checkDbIdentifierRateLimit($identifier, 'email_change_notice', self::ADDRESS_NOTICE_LIMIT, self::ADDRESS_NOTICE_WINDOW)) {
            return true;
        }
        Security::recordDbRateLimitAttempt(Security::getClientIp(), 'email_change_notice', $identifier);

        $siteName = $this->siteName();
        $loginUrl = '';
        try {
            $loginUrl = CmsAuthPageService::getInstance()->getPublicUrl('login');
        } catch (\Throwable) {
        }

        $body = "Hallo,\n\n"
            . "soeben wurde versucht, diese E-Mail-Adresse bei {$siteName} für ein anderes Konto zu hinterlegen. "
            . "Die Adresse gehört bereits zu deinem Konto, deshalb wurde nichts geändert.\n\n"
            . 'Du kannst dich wie gewohnt mit deinem Konto anmelden' . ($loginUrl !== '' ? ":\n{$loginUrl}\n\n" : ".\n\n")
            . "Warst du das nicht, kannst du diese E-Mail ignorieren.\n\n"
            . "Viele Grüße\n{$siteName}";

        return $this->sendMail($email, '[' . $siteName . '] Hinweis zu deiner E-Mail-Adresse', $body, 0);
    }

    private function notifyCurrentAddress(string $currentEmail, string $newEmail, string $initiator): bool
    {
        if ($currentEmail === '') {
            return false;
        }

        $siteName = $this->siteName();
        $siteUrl = rtrim(defined('SITE_URL') ? (string) SITE_URL : '', '/');
        $requestedBy = $initiator === self::INITIATOR_ADMIN
            ? 'von einem Administrator'
            : 'über den Mitgliederbereich (IP-Adresse ' . Security::getClientIp() . ')';

        $body = "Hallo,\n\n"
            . "für dein Konto bei {$siteName} wurde soeben {$requestedBy} eine Änderung der E-Mail-Adresse auf "
            . $this->maskEmail($newEmail) . " angefordert.\n"
            . 'Zeitpunkt: ' . date('d.m.Y H:i') . "\n\n"
            . "Die neue Adresse wird erst übernommen, wenn sie über den Link in der Bestätigungs-E-Mail bestätigt wird. "
            . "Bis dahin bleibt diese Adresse aktiv.\n\n"
            . 'Warst du das nicht, ändere bitte umgehend dein Passwort und verwirf die Änderung in deinem Profil'
            . ($siteUrl !== '' ? " ({$siteUrl}/member/profile)" : '')
            . ". Wende dich bei Fragen an den Betreiber.\n";

        return $this->sendMail($currentEmail, '[' . $siteName . '] Änderung deiner E-Mail-Adresse angefordert', $body, 0);
    }

    private function notifyPreviousAddressOfChange(string $previousEmail, string $newEmail, string $initiator): bool
    {
        if ($previousEmail === '') {
            return false;
        }

        $siteName = $this->siteName();
        $siteUrl = rtrim(defined('SITE_URL') ? (string) SITE_URL : '', '/');
        $changedBy = $initiator === self::INITIATOR_ADMIN ? ' von einem Administrator' : '';

        $body = "Hallo,\n\n"
            . "die E-Mail-Adresse deines Kontos bei {$siteName} wurde soeben{$changedBy} von dieser Adresse auf "
            . $this->maskEmail($newEmail) . " geändert.\n"
            . 'Zeitpunkt: ' . date('d.m.Y H:i') . "\n\n"
            . 'Warst du das nicht, wende dich bitte umgehend an den Betreiber'
            . ($siteUrl !== '' ? " ({$siteUrl})" : '') . ".\n\n"
            . "Diese Nachricht wurde automatisch an deine bisherige Adresse gesendet.\n";

        return $this->sendMail($previousEmail, '[' . $siteName . '] E-Mail-Adresse deines Kontos geändert', $body, 0);
    }

    private function sendMail(string $to, string $subject, string $body, int $userId): bool
    {
        try {
            return function_exists('cms_mail')
                && \cms_mail($to, $subject, $body, ['Content-Type' => 'text/plain; charset=UTF-8']);
        } catch (\Throwable $e) {
            $this->logger->warning('E-Mail zur Adressänderung konnte nicht versendet werden.', [
                'user_id' => $userId,
                'exception' => $e->getMessage(),
            ]);
            return false;
        }
    }

    private function maskEmail(string $email): string
    {
        $at = strrpos($email, '@');

        return $at === false ? '***' : mb_substr($email, 0, 1) . '***' . substr($email, $at);
    }

    private function buildConfirmationUrl(int $userId, string $token, ?string $locale): string
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

    private function siteName(): string
    {
        return defined('SITE_NAME') ? (string) SITE_NAME : '365CMS';
    }

    private function isAddressUsedByOtherAccount(string $email, int $userId): bool
    {
        return (int) $this->db->get_var(
            "SELECT id FROM {$this->prefix}users WHERE email = ? AND id <> ? LIMIT 1",
            [$email, $userId]
        ) > 0;
    }

    private function findUser(int $userId): ?object
    {
        if ($userId <= 0) {
            return null;
        }

        return $this->db->get_row(
            "SELECT id, username, email, display_name FROM {$this->prefix}users WHERE id = ? LIMIT 1",
            [$userId]
        );
    }

    private function clearPending(int $userId): void
    {
        $this->deleteMeta($userId, [self::META_PENDING, self::META_TOKEN, self::META_EXPIRES]);
    }

    /**
     * @param array<int,string> $keys
     */
    private function deleteMeta(int $userId, array $keys): void
    {
        if ($keys === []) {
            return;
        }

        $this->db->execute(
            "DELETE FROM {$this->prefix}user_meta WHERE user_id = ? AND meta_key IN (" . implode(', ', array_fill(0, count($keys), '?')) . ')',
            array_merge([$userId], $keys)
        );
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

    /**
     * @param array<string,mixed> $metadata
     */
    private function audit(string $action, string $description, int $userId, array $metadata, string $severity): void
    {
        try {
            AuditLogger::instance()->log(AuditLogger::CAT_USER, $action, $description, 'user', $userId, $metadata, $severity);
        } catch (\Throwable) {
        }
    }
}
