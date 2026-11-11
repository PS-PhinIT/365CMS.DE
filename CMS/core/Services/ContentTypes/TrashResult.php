<?php
/**
 * Ergebnis einer Papierkorb-Aktion. Meldungen sind für die Oberfläche bestimmt (übersetzt, ohne Interna).
 *
 * @package CMSv2\Core\Services
 */

declare(strict_types=1);

namespace CMS\Services\ContentTypes;

if (!defined('ABSPATH')) {
    exit;
}

final class TrashResult
{
    public const CODE_OK = 'ok';
    public const CODE_NOT_FOUND = 'not_found';
    public const CODE_CONFIRMATION_REQUIRED = 'confirmation_required';
    public const CODE_INVALID_STATE = 'invalid_state';
    public const CODE_ERROR = 'error';

    /**
     * @param array<string, mixed> $data z. B. `slug` (neuer Slug nach Konfliktauflösung), `warnings`
     */
    private function __construct(
        public readonly bool $ok,
        public readonly string $code,
        public readonly string $message,
        public readonly array $data,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function success(string $message = '', array $data = []): self
    {
        return new self(true, self::CODE_OK, $message, $data);
    }

    /** @param array<string, mixed> $data */
    public static function failure(string $code, string $message, array $data = []): self
    {
        return new self(false, $code === self::CODE_OK ? self::CODE_ERROR : $code, $message, $data);
    }

    /** @param list<string> $warnings */
    public static function confirmationRequired(array $warnings, string $message = ''): self
    {
        return new self(false, self::CODE_CONFIRMATION_REQUIRED, $message, ['warnings' => array_values($warnings)]);
    }
}
