<?php
/**
 * Ergebnis von `CaptchaService::verify()`.
 *
 * Codes: `ok`, `missing` (keine oder ungültige Kennung der Aufgabe), `expired` (zu alt), `context` (Aufgabe stammt aus einem anderen
 * Formular), `invalid` (falsche Antwort), `unavailable` (keine Sitzung, die Aufgaben speichern kann).
 * Jede geprüfte Aufgabe ist danach verbraucht, auch bei einer falschen Antwort (kein Raten an derselben Aufgabe).
 *
 * @package CMSv2\Core\Services\Security
 */

declare(strict_types=1);

namespace CMS\Services\Security\Captcha;

if (!defined('ABSPATH')) {
    exit;
}

final class CaptchaResult
{
    public const OK = 'ok';
    public const MISSING = 'missing';
    public const EXPIRED = 'expired';
    public const CONTEXT = 'context';
    public const INVALID = 'invalid';
    public const UNAVAILABLE = 'unavailable';

    public function __construct(
        public readonly bool $ok,
        public readonly string $code,
        public readonly string $provider = ''
    ) {
    }

    public static function ok(string $provider): self
    {
        return new self(true, self::OK, $provider);
    }

    public static function failed(string $code, string $provider = ''): self
    {
        return new self(false, $code === self::OK ? self::INVALID : $code, $provider);
    }
}
