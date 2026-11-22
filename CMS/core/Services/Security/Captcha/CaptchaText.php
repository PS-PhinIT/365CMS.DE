<?php
/**
 * Texte und Maskierung der Captcha-Ausgabe: Schlüssel `captcha.*` über `__()` (Sprachdateien de/en), mit deutschem Standardtext,
 * falls der Schlüssel fehlt oder `__()` nicht geladen ist (CLI, Tests). Platzhalter `%s`/`%d` per `vsprintf()`.
 *
 * @package CMSv2\Core\Services\Security
 */

declare(strict_types=1);

namespace CMS\Services\Security\Captcha;

if (!defined('ABSPATH')) {
    exit;
}

final class CaptchaText
{
    public static function t(string $key, string $default, string|int ...$args): string
    {
        $text = function_exists('__') ? (string) __($key) : $key;
        if ($text === $key || $text === '') {
            $text = $default;
        }
        if ($args === []) {
            return $text;
        }

        try {
            return vsprintf($text, $args);
        } catch (\ValueError) {
            return $text;
        }
    }

    public static function e(string|int $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
