<?php
/**
 * Einstellbare Login-Grenzwerte (Einstellungen → Login & Sperren).
 *
 * Wirksam ist die Einstellung (Gruppe `security`, Schlüssel `max_login_attempts` bzw. `login_timeout`), wenn sie
 * gesetzt ist und im erlaubten Bereich liegt; sonst die Konstante aus `config/app.php` (`MAX_LOGIN_ATTEMPTS`,
 * `LOGIN_TIMEOUT`), sonst der eingebaute Standard. Auch die Konstante wird auf den erlaubten Bereich begrenzt.
 *
 * Genutzt von `Auth::login()` (Admin- und Mitglieder-Anmeldung) und der Passwort-Zurücksetzung mit Token
 * (`CmsAuthPageService`), die dieselben Fehlversuchs-Einträge in `login_attempts` zählen.
 *
 * @package CMSv2\Core\Services
 */

declare(strict_types=1);

namespace CMS\Services;

use CMS\AuditLogger;

if (!defined('ABSPATH')) {
    exit;
}

final class LoginThrottleSettings
{
    public const GROUP = 'security';
    public const KEY_ATTEMPTS = 'max_login_attempts';
    public const KEY_TIMEOUT = 'login_timeout';

    public const MIN_ATTEMPTS = 3;
    public const MAX_ATTEMPTS = 20;
    public const MIN_TIMEOUT = 60;
    public const MAX_TIMEOUT = 3600;

    public const DEFAULT_ATTEMPTS = 5;
    public const DEFAULT_TIMEOUT = 300;

    public const SOURCE_SETTING = 'setting';
    public const SOURCE_CONSTANT = 'constant';
    public const SOURCE_DEFAULT = 'default';

    /** @var array{attempts:array{value:int,source:string},timeout:array{value:int,source:string}}|null */
    private static ?array $cache = null;

    public static function maxAttempts(): int
    {
        return self::resolve()['attempts']['value'];
    }

    public static function lockoutSeconds(): int
    {
        return self::resolve()['timeout']['value'];
    }

    /**
     * @return array{attempts:array{value:int,source:string,constant:?int,setting:?int},timeout:array{value:int,source:string,constant:?int,setting:?int}}
     */
    public static function describe(): array
    {
        $resolved = self::resolve();
        $stored = self::stored();

        return [
            'attempts' => $resolved['attempts'] + [
                'constant' => defined('MAX_LOGIN_ATTEMPTS') ? (int) MAX_LOGIN_ATTEMPTS : null,
                'setting' => $stored['attempts'],
            ],
            'timeout' => $resolved['timeout'] + [
                'constant' => defined('LOGIN_TIMEOUT') ? (int) LOGIN_TIMEOUT : null,
                'setting' => $stored['timeout'],
            ],
        ];
    }

    public static function attemptsInRange(int $value): bool
    {
        return $value >= self::MIN_ATTEMPTS && $value <= self::MAX_ATTEMPTS;
    }

    public static function timeoutInRange(int $value): bool
    {
        return $value >= self::MIN_TIMEOUT && $value <= self::MAX_TIMEOUT;
    }

    /**
     * Speichert beide Werte (null = Einstellung entfernen, dann gilt wieder die Konstante). Werte außerhalb des
     * Bereichs werden begrenzt. Änderungen landen im Audit-Log.
     */
    public static function save(?int $attempts, ?int $timeout, int $userId): bool
    {
        $before = self::describe();
        $settings = SettingsService::getInstance();
        $ok = true;

        foreach ([self::KEY_ATTEMPTS => [$attempts, self::MIN_ATTEMPTS, self::MAX_ATTEMPTS], self::KEY_TIMEOUT => [$timeout, self::MIN_TIMEOUT, self::MAX_TIMEOUT]] as $key => [$value, $min, $max]) {
            if ($value === null) {
                $ok = $settings->forget(self::GROUP, $key) && $ok;
            } else {
                $ok = $settings->set(self::GROUP, $key, max($min, min($max, $value))) && $ok;
            }
        }
        self::$cache = null;
        $after = self::describe();

        if ($before['attempts']['value'] !== $after['attempts']['value'] || $before['timeout']['value'] !== $after['timeout']['value']
            || $before['attempts']['source'] !== $after['attempts']['source'] || $before['timeout']['source'] !== $after['timeout']['source']) {
            try {
                AuditLogger::instance()->log(
                    AuditLogger::CAT_SECURITY,
                    'security.login_thresholds_updated',
                    sprintf(
                        'Login-Grenzwerte geändert: %d Versuche (%s), Sperre %d s (%s).',
                        $after['attempts']['value'],
                        $after['attempts']['source'],
                        $after['timeout']['value'],
                        $after['timeout']['source']
                    ),
                    'setting',
                    null,
                    [
                        'before' => ['attempts' => $before['attempts']['value'], 'timeout' => $before['timeout']['value']],
                        'after' => ['attempts' => $after['attempts']['value'], 'timeout' => $after['timeout']['value']],
                        'user_id' => $userId,
                    ],
                    'warning'
                );
            } catch (\Throwable) {
            }
        }

        return $ok;
    }

    public static function reset(): void
    {
        self::$cache = null;
    }

    /**
     * @return array{attempts:array{value:int,source:string},timeout:array{value:int,source:string}}
     */
    private static function resolve(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $stored = self::stored();
        $attempts = $stored['attempts'] !== null && self::attemptsInRange($stored['attempts'])
            ? ['value' => $stored['attempts'], 'source' => self::SOURCE_SETTING]
            : (defined('MAX_LOGIN_ATTEMPTS')
                ? ['value' => max(self::MIN_ATTEMPTS, min(self::MAX_ATTEMPTS, (int) MAX_LOGIN_ATTEMPTS)), 'source' => self::SOURCE_CONSTANT]
                : ['value' => self::DEFAULT_ATTEMPTS, 'source' => self::SOURCE_DEFAULT]);
        $timeout = $stored['timeout'] !== null && self::timeoutInRange($stored['timeout'])
            ? ['value' => $stored['timeout'], 'source' => self::SOURCE_SETTING]
            : (defined('LOGIN_TIMEOUT')
                ? ['value' => max(self::MIN_TIMEOUT, min(self::MAX_TIMEOUT, (int) LOGIN_TIMEOUT)), 'source' => self::SOURCE_CONSTANT]
                : ['value' => self::DEFAULT_TIMEOUT, 'source' => self::SOURCE_DEFAULT]);

        return self::$cache = ['attempts' => $attempts, 'timeout' => $timeout];
    }

    /** @return array{attempts:?int,timeout:?int} */
    private static function stored(): array
    {
        try {
            $group = SettingsService::getInstance()->getGroup(self::GROUP);
        } catch (\Throwable) {
            $group = [];
        }
        $read = static function (string $key) use ($group): ?int {
            if (!array_key_exists($key, $group) || $group[$key] === null || $group[$key] === '') {
                return null;
            }
            $value = filter_var($group[$key], FILTER_VALIDATE_INT);

            return $value === false ? null : (int) $value;
        };

        return ['attempts' => $read(self::KEY_ATTEMPTS), 'timeout' => $read(self::KEY_TIMEOUT)];
    }
}
