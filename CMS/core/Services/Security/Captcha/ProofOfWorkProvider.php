<?php
/**
 * Verfahren „pow“ (empfohlener Standard): Proof-of-Work nach dem ALTCHA-Prinzip, selbst gehostet, ohne Drittanbieter.
 *
 * Der Server erzeugt ein Salz und eine geheime Zahl n (0 bis MAX_NUMBER) und veröffentlicht nur `SHA-256(salz + n)`. Der Browser
 * (`assets/js/captcha/captcha.js`, Web Crypto) probiert die Zahlen durch und schickt n; der Server prüft einmal `SHA-256(salz + n)`.
 * Das kostet einen Client im Mittel MAX_NUMBER / 2 Hashes (einige Zehntel Sekunden), den Server eine. Die Aufgabe liegt nur in der
 * Sitzung (einmalig, siehe `CaptchaStore`); es gibt kein Geheimnis außerhalb der Sitzung.
 *
 * Proof-of-Work bremst Massenanmeldungen, beweist aber keinen Menschen: es ist eine Schicht von mehreren (Honeypot, Raten,
 * Wortfilter, Freigabe). Alternative für Nutzer ohne JavaScript oder ohne Web Crypto (kein sicherer Kontext): die Rechenaufgabe, die
 * das Skript ausblendet, sobald es die Prüfung übernimmt.
 *
 * @package CMSv2\Core\Services\Security
 */

declare(strict_types=1);

namespace CMS\Services\Security\Captcha;

use CMS\Services\Security\CaptchaService;

if (!defined('ABSPATH')) {
    exit;
}

final class ProofOfWorkProvider implements CaptchaProvider
{
    public const ID = 'pow';
    public const ALGORITHM = 'SHA-256';
    /** Größte geheime Zahl: im Mittel 30.000 Hashes für den Browser. */
    public const MAX_NUMBER = 60000;

    public function id(): string
    {
        return self::ID;
    }

    public function available(): bool
    {
        return in_array('sha256', hash_algos(), true);
    }

    public function create(\Closure $int, \Closure $bytes): array
    {
        $salt = bin2hex($bytes(16));
        $number = $int(0, self::MAX_NUMBER);
        $challenge = hash('sha256', $salt . $number);
        $math = MathChallenge::create($int);

        return [
            'public' => ['salt' => $salt, 'challenge' => $challenge, 'max' => self::MAX_NUMBER, 'math' => $math['public']],
            'private' => ['salt' => $salt, 'challenge' => $challenge, 'max' => self::MAX_NUMBER, 'answer' => $math['answer']],
        ];
    }

    public function render(string $id, array $public): string
    {
        $t = static fn (string $key, string $default): string => CaptchaText::e(CaptchaText::t($key, $default));

        return '<fieldset class="cms-captcha cms-captcha--pow" data-cms-captcha="pow"'
            . ' data-algorithm="' . self::ALGORITHM . '"'
            . ' data-salt="' . CaptchaText::e((string) ($public['salt'] ?? '')) . '"'
            . ' data-challenge="' . CaptchaText::e((string) ($public['challenge'] ?? '')) . '"'
            . ' data-max="' . (int) ($public['max'] ?? self::MAX_NUMBER) . '"'
            . ' data-text-working="' . $t('captcha.pow.working', 'Sicherheitsprüfung läuft …') . '"'
            . ' data-text-done="' . $t('captcha.pow.done', 'Sicherheitsprüfung bestanden.') . '"'
            . ' data-text-failed="' . $t('captcha.pow.failed', 'Die automatische Prüfung ist nicht möglich. Bitte löse die Rechenaufgabe.') . '">'
            . '<legend class="cms-captcha__legend">' . $t('captcha.legend', 'Sicherheitsprüfung') . '</legend>'
            . '<input type="hidden" name="' . CaptchaService::FIELD_ID . '" value="' . CaptchaText::e($id) . '">'
            . '<input type="hidden" name="' . CaptchaService::FIELD_POW . '" value="" data-cms-captcha-payload>'
            . '<p class="cms-captcha__status" role="status" aria-live="polite" data-cms-captcha-status></p>'
            . MathChallenge::field($id, (array) ($public['math'] ?? ['a' => 1, 'b' => 1, 'op' => 'plus']))
            . '</fieldset>';
    }

    public function verify(array $request, array $private): bool
    {
        $number = $request[CaptchaService::FIELD_POW] ?? null;
        if (is_string($number) && preg_match('/^\d{1,9}$/', $number) === 1) {
            $n = (int) $number;
            $solved = $n <= (int) ($private['max'] ?? 0)
                && hash_equals((string) ($private['challenge'] ?? ''), hash('sha256', (string) ($private['salt'] ?? '') . $n));
            if ($solved) {
                return true;
            }
        }

        return MathChallenge::check($request[CaptchaService::FIELD_ANSWER] ?? null, (int) ($private['answer'] ?? PHP_INT_MIN));
    }

    public function requiresConsent(): bool
    {
        return false;
    }

    public function cspSources(): array
    {
        return [];
    }
}
