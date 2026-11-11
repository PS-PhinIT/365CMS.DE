<?php
/**
 * Spam-Schutz für Formulare: Honeypot, signierte Startzeit, Rate-Limit, Rechenaufgabe und die globalen
 * AntiSpam-Regeln (`AntispamService`). Abgewiesene Versuche landen als `antispam_rejected` im
 * Sicherheitsprotokoll (`security_log`, Regel `forms:<grund>`), so wie die übrigen AntiSpam-Ereignisse.
 *
 * Stufen je Formular:
 * - `low`: Honeypot
 * - `standard`: Honeypot, Mindestzeit, Rate-Limit je IP und Stunde, globale AntiSpam-Regeln
 * - `high`: zusätzlich eine Rechenaufgabe
 *
 * @package CMSv2\Core\Services\Forms
 */

declare(strict_types=1);

namespace CMS\Services\Forms;

use CMS\Database;
use CMS\Security;
use CMS\Services\AntispamService;

if (!defined('ABSPATH')) {
    exit;
}

final class FormSpamGuard
{
    public const HONEYPOT_FIELD = 'website_url';
    public const TIME_FIELD = '_ts';
    public const CAPTCHA_FIELD = 'cms_captcha';
    public const MIN_SECONDS = 3;
    public const RATE_WINDOW = 3600;

    private const CAPTCHA_SESSION = 'cms_forms_captcha';

    /** Signierte Startzeit (verhindert gefälschte Zeitstempel). */
    public static function timestampToken(?int $time = null): string
    {
        $time ??= time();

        return $time . '.' . self::sign((string) $time);
    }

    /** Startzeit aus dem Token oder null bei fehlender/ungültiger Signatur. */
    public static function verifyTimestamp(string $token): ?int
    {
        if (preg_match('/^(\d{9,11})\.([a-f0-9]{32})$/', trim($token), $match) !== 1) {
            return null;
        }
        if (!hash_equals(self::sign($match[1]), $match[2])) {
            return null;
        }
        $time = (int) $match[1];

        return $time <= time() + 60 ? $time : null;
    }

    /**
     * Neue Rechenaufgabe für ein Formular (Antwort bleibt in der Sitzung).
     *
     * @return array{a: int, b: int}
     */
    public static function captchaQuestion(string $formKey): array
    {
        $a = random_int(1, 9);
        $b = random_int(1, 9);
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION[self::CAPTCHA_SESSION][$formKey] = ['answer' => $a + $b, 'time' => time()];
        }

        return ['a' => $a, 'b' => $b];
    }

    public static function verifyCaptcha(string $formKey, mixed $answer): bool
    {
        $stored = $_SESSION[self::CAPTCHA_SESSION][$formKey] ?? null;
        unset($_SESSION[self::CAPTCHA_SESSION][$formKey]);
        if (!is_array($stored) || !is_scalar($answer) || trim((string) $answer) === '') {
            return false;
        }

        return (int) trim((string) $answer) === (int) ($stored['answer'] ?? -1)
            && (int) ($stored['time'] ?? 0) >= time() - 86400;
    }

    /**
     * Prüft eine Absendung.
     *
     * `silent` = Honeypot ausgefüllt: Erfolg vortäuschen, nichts speichern und nichts versenden.
     *
     * @param array<string, mixed> $form
     * @param array<string, mixed> $post Roh-POST
     * @param array<string, mixed> $values Bereinigte Feldwerte
     * @return array{status: 'ok'|'silent'|'error', reason: string, message: string}
     */
    public static function check(array $form, array $post, array $values, string $locale): array
    {
        $level = (string) ($form['settings']['antispam_level'] ?? 'standard');
        $ip = Security::getClientIp();

        if (trim((string) (is_scalar($post[self::HONEYPOT_FIELD] ?? null) ? $post[self::HONEYPOT_FIELD] : '')) !== '') {
            self::logRejection('honeypot', $form);

            return ['status' => 'silent', 'reason' => 'honeypot', 'message' => ''];
        }
        if ($level === 'low') {
            return self::ok();
        }

        $startedAt = self::verifyTimestamp(is_scalar($post[self::TIME_FIELD] ?? null) ? (string) $post[self::TIME_FIELD] : '');
        if ($startedAt === null) {
            self::logRejection('invalid_timestamp', $form);

            return self::error('invalid_timestamp', FormI18n::t('forms.error.expired', $locale));
        }
        if (time() - $startedAt < self::MIN_SECONDS) {
            self::logRejection('too_fast', $form);

            return self::error('too_fast', FormI18n::t('forms.error.too_fast', $locale));
        }

        $limit = (int) ($form['settings']['rate_limit'] ?? 5);
        if ($limit > 0 && !Security::checkDbRateLimit($ip, self::rateLimitAction($form), $limit, self::RATE_WINDOW)) {
            self::logRejection('rate_limited', $form);

            return self::error('rate_limited', FormI18n::t('forms.error.rate_limit', $locale));
        }

        if ($level === 'high' && !self::verifyCaptcha((string) $form['form_key'], $post[self::CAPTCHA_FIELD] ?? '')) {
            self::logRejection('captcha', $form);

            return self::error('captcha', FormI18n::t('forms.error.captcha', $locale));
        }

        $summary = FormSubmissionService::summary($form['fields'] ?? [], $values);
        $content = [];
        foreach ($values as $value) {
            if (is_string($value) && $value !== '') {
                $content[] = $value;
            }
        }
        try {
            $result = AntispamService::getInstance()->evaluate([
                'honeypot_value' => '',
                'started_at' => $startedAt,
                'email' => $summary['sender_email'],
                'ip_address' => $ip,
                'author_name' => $summary['sender_name'],
                'content' => implode("\n", $content),
                'user_agent' => (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
                'source' => 'forms',
            ]);
        } catch (\Throwable) {
            $result = ['rejected' => false];
        }
        if (!empty($result['rejected'])) {
            $reason = (string) ($result['reason'] ?? 'antispam');

            return $reason === 'minimum_time'
                ? self::error($reason, FormI18n::t('forms.error.too_fast', $locale))
                : self::error($reason, FormI18n::t('forms.error.spam', $locale));
        }

        return self::ok();
    }

    /** Zählt eine erfolgreiche Absendung für das Rate-Limit (Tabelle `login_attempts`, kurzlebig). */
    public static function recordSubmission(array $form): void
    {
        if ((int) ($form['settings']['rate_limit'] ?? 0) > 0 && ($form['settings']['antispam_level'] ?? 'standard') !== 'low') {
            Security::recordDbRateLimitAttempt(Security::getClientIp(), self::rateLimitAction($form));
        }
    }

    /**
     * Protokolliert einen abgewiesenen Versuch im Sicherheitsprotokoll.
     *
     * @param array<string, mixed> $form
     */
    public static function logRejection(string $reason, array $form, string $action = 'antispam_rejected'): void
    {
        $reason = substr((string) preg_replace('/[^a-z0-9_]/', '', strtolower($reason)), 0, 40);
        $ip = Security::getClientIp();
        $uri = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        try {
            Database::instance()->insert('security_log', [
                'action' => $action,
                'ip_address' => filter_var($ip, FILTER_VALIDATE_IP) ? $ip : null,
                'request_uri' => substr($uri !== '' ? $uri : '/', 0, 1000),
                'user_agent' => substr((string) preg_replace('/[\x00-\x1F\x7F]/', '', (string) ($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 500),
                'rule_matched' => 'forms:' . $reason,
                'user_id' => isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null,
                'extra' => FormService::encode([
                    'reason' => $reason,
                    'source' => 'forms',
                    'form' => (string) ($form['form_key'] ?? ''),
                ]),
            ]);
        } catch (\Throwable) {
            // Protokollierung darf die Formularverarbeitung nie abbrechen.
        }
    }

    /**
     * @param array<string, mixed> $form
     */
    private static function rateLimitAction(array $form): string
    {
        return 'form_' . (int) ($form['id'] ?? 0);
    }

    private static function sign(string $value): string
    {
        $key = '';
        foreach (['NONCE_KEY', 'AUTH_KEY', 'SECURE_AUTH_KEY'] as $constant) {
            if (defined($constant) && (string) constant($constant) !== '') {
                $key = (string) constant($constant);
                break;
            }
        }
        if ($key === '') {
            $key = hash('sha256', (defined('DB_NAME') ? (string) DB_NAME : '') . '|' . (defined('ABSPATH') ? (string) ABSPATH : ''));
        }

        return substr(hash_hmac('sha256', 'forms|' . $value, $key), 0, 32);
    }

    /**
     * @return array{status: 'ok', reason: string, message: string}
     */
    private static function ok(): array
    {
        return ['status' => 'ok', 'reason' => '', 'message' => ''];
    }

    /**
     * @return array{status: 'error', reason: string, message: string}
     */
    private static function error(string $reason, string $message): array
    {
        return ['status' => 'error', 'reason' => $reason, 'message' => $message];
    }
}
