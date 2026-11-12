<?php
/**
 * Umgebungsinfo für System → Info: PHP-Erweiterungen mit der Funktion, die sie brauchen, sowie OPcache und
 * Datenbank (Produkt und Version).
 *
 * Pflicht-Erweiterungen fehlen nur in kaputten Installationen (der Installer prüft sie); optionale schalten
 * einzelne Funktionen ab. Die Liste ist bewusst fest: sie beschreibt, was der Core nutzt, nicht alles,
 * was geladen ist.
 *
 * @package CMSv2\Core\Services
 */

declare(strict_types=1);

namespace CMS\Services;

use CMS\Database;

if (!defined('ABSPATH')) {
    exit;
}

final class EnvironmentInfoService
{
    public const LEVEL_REQUIRED = 'required';
    public const LEVEL_RECOMMENDED = 'recommended';
    public const LEVEL_OPTIONAL = 'optional';

    /**
     * Erweiterung => [Stufe, Sprachschlüssel der Funktion]. Mehrere Namen mit `|`: eine davon genügt.
     */
    private const EXTENSIONS = [
        'pdo_mysql' => [self::LEVEL_REQUIRED, 'environment.feature.database'],
        'mbstring' => [self::LEVEL_REQUIRED, 'environment.feature.text'],
        'json' => [self::LEVEL_REQUIRED, 'environment.feature.json'],
        'openssl' => [self::LEVEL_REQUIRED, 'environment.feature.crypto'],
        'ctype' => [self::LEVEL_REQUIRED, 'environment.feature.validation'],
        'fileinfo' => [self::LEVEL_REQUIRED, 'environment.feature.upload_check'],
        'session' => [self::LEVEL_REQUIRED, 'environment.feature.session'],
        'dom' => [self::LEVEL_REQUIRED, 'environment.feature.html'],
        'gd|imagick' => [self::LEVEL_RECOMMENDED, 'environment.feature.images'],
        'curl' => [self::LEVEL_RECOMMENDED, 'environment.feature.http'],
        'zip' => [self::LEVEL_RECOMMENDED, 'environment.feature.zip'],
        'intl' => [self::LEVEL_RECOMMENDED, 'environment.feature.intl'],
        'pdo_sqlite' => [self::LEVEL_RECOMMENDED, 'environment.feature.search'],
        'zlib' => [self::LEVEL_RECOMMENDED, 'environment.feature.compression'],
        'sodium' => [self::LEVEL_RECOMMENDED, 'environment.feature.sodium'],
        'Zend OPcache' => [self::LEVEL_RECOMMENDED, 'environment.feature.opcache'],
        'exif' => [self::LEVEL_OPTIONAL, 'environment.feature.exif'],
        'iconv' => [self::LEVEL_OPTIONAL, 'environment.feature.iconv'],
        'apcu' => [self::LEVEL_OPTIONAL, 'environment.feature.apcu'],
        'redis|memcached' => [self::LEVEL_OPTIONAL, 'environment.feature.object_cache'],
        'ldap' => [self::LEVEL_OPTIONAL, 'environment.feature.ldap'],
    ];

    /**
     * @return list<array{name:string,loaded:bool,version:string,level:string,feature:string}>
     */
    public function extensions(?callable $isLoaded = null): array
    {
        $isLoaded ??= static fn (string $name): bool => extension_loaded($name);
        $rows = [];
        foreach (self::EXTENSIONS as $names => [$level, $featureKey]) {
            $loadedName = '';
            foreach (explode('|', $names) as $name) {
                if ($isLoaded($name)) {
                    $loadedName = $name;
                    break;
                }
            }
            $version = $loadedName !== '' ? (string) (phpversion($loadedName) ?: '') : '';
            $rows[] = [
                'name' => str_replace('|', ' / ', $names),
                'loaded' => $loadedName !== '',
                'version' => $version !== PHP_VERSION ? $version : '',
                'level' => $level,
                'feature' => function_exists('__') ? (string) __($featureKey) : $featureKey,
            ];
        }

        return $rows;
    }

    /**
     * @return array{loaded:bool,enabled:bool,version:string,memory_used:int,memory_total:int,hit_rate:?float,jit:bool}
     */
    public function opcache(): array
    {
        $loaded = extension_loaded('Zend OPcache');
        $info = [
            'loaded' => $loaded,
            'enabled' => false,
            'version' => $loaded ? (string) (phpversion('Zend OPcache') ?: '') : '',
            'memory_used' => 0,
            'memory_total' => 0,
            'hit_rate' => null,
            'jit' => false,
        ];
        if (!$loaded || !function_exists('opcache_get_status')) {
            return $info;
        }
        $status = @opcache_get_status(false);
        if (!is_array($status)) {
            return $info;
        }
        $info['enabled'] = !empty($status['opcache_enabled']);
        $memory = is_array($status['memory_usage'] ?? null) ? $status['memory_usage'] : [];
        $info['memory_used'] = (int) ($memory['used_memory'] ?? 0);
        $info['memory_total'] = (int) ($memory['used_memory'] ?? 0) + (int) ($memory['free_memory'] ?? 0) + (int) ($memory['wasted_memory'] ?? 0);
        if (isset($status['opcache_statistics']['opcache_hit_rate'])) {
            $info['hit_rate'] = round((float) $status['opcache_statistics']['opcache_hit_rate'], 1);
        }
        $info['jit'] = !empty($status['jit']['on']);

        return $info;
    }

    /**
     * @return array{product:string,version:string,raw:string,driver:string}
     */
    public function database(): array
    {
        $raw = '';
        $driver = '';
        try {
            $pdo = Database::instance()->getConnection();
            $raw = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
            $driver = (string) $pdo->getAttribute(\PDO::ATTR_CLIENT_VERSION);
        } catch (\Throwable) {
        }

        return self::parseDatabaseVersion($raw) + ['raw' => $raw, 'driver' => $driver];
    }

    /**
     * `10.11.8-MariaDB-0ubuntu0.24.04.1` → MariaDB 10.11.8; `8.0.39` → MySQL 8.0.39.
     *
     * @return array{product:string,version:string}
     */
    public static function parseDatabaseVersion(string $raw): array
    {
        if ($raw === '') {
            return ['product' => '', 'version' => ''];
        }
        $version = preg_match('/^(\d+\.\d+(?:\.\d+)?)/', $raw, $m) === 1 ? $m[1] : $raw;
        // MariaDB meldet bei Replikation teils „5.5.5-10.x“.
        if (preg_match('/^5\.5\.5-(\d+\.\d+(?:\.\d+)?)/', $raw, $m) === 1) {
            $version = $m[1];
        }

        return ['product' => stripos($raw, 'mariadb') !== false ? 'MariaDB' : 'MySQL', 'version' => $version];
    }

    /** Anzahl fehlender Erweiterungen je Stufe. @return array<string,int> */
    public static function missingCounts(array $extensions): array
    {
        $counts = [self::LEVEL_REQUIRED => 0, self::LEVEL_RECOMMENDED => 0, self::LEVEL_OPTIONAL => 0];
        foreach ($extensions as $row) {
            if (empty($row['loaded'])) {
                $counts[(string) $row['level']] = ($counts[(string) $row['level']] ?? 0) + 1;
            }
        }

        return $counts;
    }
}
