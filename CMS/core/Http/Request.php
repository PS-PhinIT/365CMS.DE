<?php
declare(strict_types=1);

namespace CMS\Http;

if (!defined('ABSPATH')) {
    exit;
}

final class Request
{
    private function __construct()
    {
    }

    public static function method(): string
    {
        return strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    }

    public static function server(string $key, mixed $default = null): mixed
    {
        return $_SERVER[$key] ?? $default;
    }

    public static function header(string $name, string $default = ''): string
    {
        $normalized = 'HTTP_' . strtoupper(str_replace('-', '_', trim($name)));

        return trim((string) ($_SERVER[$normalized] ?? $default));
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return $_GET[$key] ?? $default;
    }

    public static function post(string $key, mixed $default = null): mixed
    {
        return $_POST[$key] ?? $default;
    }

    public static function file(string $key): mixed
    {
        return $_FILES[$key] ?? null;
    }

    public static function session(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    /** Liest einen GET-Parameter als String; Arrays (z. B. ?q[]=x) zählen als nicht gesetzt. */
    public static function stringFromGet(string $key, string $default = ''): string
    {
        $value = $_GET[$key] ?? null;

        return is_string($value) ? $value : $default;
    }

    public static function boolFromGet(string $key, bool $default = false): bool
    {
        if (!array_key_exists($key, $_GET)) {
            return $default;
        }

        $value = $_GET[$key];
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (int) $value !== 0;
        }

        $normalized = strtolower(trim((string) $value));

        if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }

        if (in_array($normalized, ['0', 'false', 'no', 'off', ''], true)) {
            return false;
        }

        return $default;
    }

    public static function intFromGet(string $key, ?int $default = null): ?int
    {
        if (!array_key_exists($key, $_GET)) {
            return $default;
        }

        $value = filter_var($_GET[$key], FILTER_VALIDATE_INT);

        return $value === false ? $default : (int) $value;
    }
}
