<?php
/**
 * Core-Aufbewahrungsregel „Kommentar-IP-Adressen“.
 *
 * Nach N Tagen werden die IP-Adressen von Kommentaren gekürzt (IPv4 /24, IPv6 /48) oder entfernt; der Kommentar
 * selbst bleibt bestehen. Einstellungen liegen in der Settings-Gruppe `retention` (`comment_ip_days`, 0 = aus;
 * `comment_ip_mode` = anonymize|remove) und werden unter Recht → Löschfristen gepflegt. Registriert wird die
 * Regel von `CMS\Services\FeatureHooks\SystemFeatureHooks`.
 *
 * @package CMSv2\Core\Services
 */

declare(strict_types=1);

namespace CMS\Services\Retention;

use CMS\Services\CommentService;
use CMS\Services\DataRetentionService;
use CMS\Services\OptionStore;
use CMS\Services\SettingsService;

if (!defined('ABSPATH')) {
    exit;
}

final class CommentIpRetention
{
    public const RULE_KEY = 'comment_ips';
    public const SETTINGS_GROUP = 'retention';
    public const DAYS_KEY = 'comment_ip_days';
    public const MODE_KEY = 'comment_ip_mode';
    public const MAX_DAYS = 3650;
    public const MODES = [CommentService::IP_RETENTION_ANONYMIZE, CommentService::IP_RETENTION_REMOVE];
    public const SETTINGS_ANCHOR = 'retention-comment-ips';

    /**
     * @return array{days:int,mode:string}
     */
    public static function settings(): array
    {
        $settings = SettingsService::getInstance();

        return self::normalize(
            $settings->getInt(self::SETTINGS_GROUP, self::DAYS_KEY, 0),
            $settings->getString(self::SETTINGS_GROUP, self::MODE_KEY, CommentService::IP_RETENTION_ANONYMIZE)
        );
    }

    /**
     * @return array{days:int,mode:string}
     */
    public static function normalize(mixed $days, mixed $mode): array
    {
        $days = is_numeric($days) ? (int) $days : 0;
        $mode = is_string($mode) ? strtolower(trim($mode)) : '';

        return [
            'days' => max(0, min(self::MAX_DAYS, $days)),
            'mode' => in_array($mode, self::MODES, true) ? $mode : CommentService::IP_RETENTION_ANONYMIZE,
        ];
    }

    /**
     * @return array{days:int,mode:string}|null gespeicherte Werte, null bei Fehler
     */
    public static function save(mixed $days, mixed $mode): ?array
    {
        $normalized = self::normalize($days, $mode);
        $saved = SettingsService::getInstance()->setMany(self::SETTINGS_GROUP, [
            self::DAYS_KEY => $normalized['days'],
            self::MODE_KEY => $normalized['mode'],
        ]);
        OptionStore::getInstance()->flush();

        return $saved ? $normalized : null;
    }

    public static function rule(): RetentionRule
    {
        $translate = static fn (string $key, string $fallback): string => self::translate($key, $fallback);

        return new RetentionRule(
            self::RULE_KEY,
            $translate('retention.rule.comment_ips', 'Kommentar-IP-Adressen'),
            $translate(
                'retention.rule.comment_ips_desc',
                'IP-Adressen von Kommentaren werden nach der Frist gekürzt oder entfernt; der Kommentar bleibt bestehen.'
            ),
            static fn (): int => self::settings()['days'],
            static function (): int {
                $settings = self::settings();

                return CommentService::getInstance()->countIpsDue($settings['days'], $settings['mode']);
            },
            static function (): int {
                $settings = self::settings();

                return CommentService::getInstance()->pruneIps($settings['days'], $settings['mode']);
            },
            DataRetentionService::ADMIN_PATH . '#' . self::SETTINGS_ANCHOR,
            RetentionRule::ACTION_ANONYMIZE,
        );
    }

    private static function translate(string $key, string $fallback): string
    {
        if (!function_exists('__')) {
            return $fallback;
        }

        $translated = (string) __($key);

        return $translated === $key ? $fallback : $translated;
    }
}
