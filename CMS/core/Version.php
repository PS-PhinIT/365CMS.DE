<?php
declare(strict_types=1);

namespace CMS;

if (!defined('ABSPATH')) {
    exit;
}

final class Version
{
    public const CURRENT = '3.5.2';
    public const RELEASE_DATE = '2026-10-17';
    public const STATUS = 'stable';

    /**
     * Mindest-PHP-Version, die der ausgelieferte Code samt gebündelter Libraries verlangt
     * (Symfony 8.1: PHP >= 8.4.1). Gilt auch, wenn eine ältere config.php noch einen
     * niedrigeren CMS_MIN_PHP_VERSION-Wert definiert, weil Core-Updates config.php nicht ersetzen.
     */
    public const MIN_PHP = '8.4.1';

    public static function current(): string
    {
        return self::CURRENT;
    }

    /**
     * Effektive Mindest-PHP-Version: Maximum aus CMS_MIN_PHP_VERSION (config.php) und MIN_PHP.
     */
    public static function minimumPhp(): string
    {
        $configured = defined('CMS_MIN_PHP_VERSION') ? (string) CMS_MIN_PHP_VERSION : self::MIN_PHP;

        return version_compare($configured, self::MIN_PHP, '>') ? $configured : self::MIN_PHP;
    }

    public static function releaseDate(): string
    {
        return self::RELEASE_DATE;
    }
}