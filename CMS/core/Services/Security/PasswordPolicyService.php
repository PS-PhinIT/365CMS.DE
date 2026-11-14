<?php
/**
 * Konfigurierbare Passwort-Richtlinie (Release 4.1.0, Bereich 1a).
 *
 * Ersetzt die festen Konstanten in Auth: Mindestlänge, Zeichenklassen, optionales Höchstalter und eine Historie der
 * letzten N Passwörter (keine Wiederverwendung). Die Standardwerte entsprechen dem bisherigen Verhalten
 * (12 Zeichen, vier Zeichenklassen), Ablauf ist standardmäßig aus (NIST SP 800-63B).
 *
 * Spezifikation: DOC/workflow/specs/v4.1/01-IDENTITY.md §1a
 *
 * @package CMSv2\Core\Services\Security
 */

declare(strict_types=1);

namespace CMS\Services\Security;

use CMS\Database;
use CMS\Hooks;
use CMS\Services\SettingsService;

if (!defined('ABSPATH')) {
    exit;
}

final class PasswordPolicyService
{
    public const SETTINGS_GROUP = 'password_policy';

    public const MIN_LENGTH_FLOOR = 8;
    public const MIN_LENGTH_CEILING = 128;
    public const DEFAULT_MIN_LENGTH = 12;
    public const MAX_AGE_CEILING = 730;
    public const HISTORY_CEILING = 24;
    public const DEFAULT_HISTORY = 5;

    /**
     * Zeichenklassen in fester Reihenfolge (Oberfläche, Meldungen). `source` ist Unicode-fähig und wird serverseitig
     * mit `/…/u` und im Browser mit `new RegExp(source, 'u')` geprüft: Umlaute zählen als Groß- bzw. Kleinbuchstaben,
     * „Sonderzeichen“ ist alles außer Buchstaben und Ziffern.
     */
    public const CLASSES = [
        'uppercase' => ['source' => '\p{Lu}', 'label' => 'Mindestens ein Großbuchstabe', 'message' => 'Das Passwort muss mindestens einen Großbuchstaben enthalten.'],
        'lowercase' => ['source' => '\p{Ll}', 'label' => 'Mindestens ein Kleinbuchstabe', 'message' => 'Das Passwort muss mindestens einen Kleinbuchstaben enthalten.'],
        'digit' => ['source' => '\p{Nd}', 'label' => 'Mindestens eine Ziffer', 'message' => 'Das Passwort muss mindestens eine Ziffer (0–9) enthalten.'],
        'special' => ['source' => '[^\p{L}\p{N}]', 'label' => 'Mindestens ein Sonderzeichen', 'message' => 'Das Passwort muss mindestens ein Sonderzeichen enthalten (z. B. !@#$%^&*).'],
    ];

    private static ?self $instance = null;

    /** @var \Closure(): array<string, mixed> */
    private \Closure $settingsReader;

    /** @var \Closure(int, int): list<string> letzte Hashes (neueste zuerst) */
    private \Closure $historyReader;

    /** @var \Closure(int): ?string aktueller Hash */
    private \Closure $currentHashReader;

    /** @var \Closure(int): ?string Zeitpunkt der letzten Änderung (Rückfall: created_at) */
    private \Closure $changedAtReader;

    /** @var \Closure(int, string, int): void neuen Hash speichern und auf N kürzen */
    private \Closure $historyWriter;

    /** @var \Closure(): int */
    private \Closure $clock;

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    /**
     * Alle Abhängigkeiten sind für Tests injizierbar; ohne Angabe gelten Einstellungen und Datenbank des Cores.
     *
     * @param array<string, \Closure> $overrides settings, history, currentHash, changedAt, historyWriter, clock
     */
    public function __construct(array $overrides = [])
    {
        $this->settingsReader = $overrides['settings'] ?? static function (): array {
            try {
                return SettingsService::getInstance()->getGroup(self::SETTINGS_GROUP);
            } catch (\Throwable) {
                return [];
            }
        };
        $this->historyReader = $overrides['history'] ?? static function (int $userId, int $limit): array {
            $db = Database::instance();
            $rows = $db->get_results(
                "SELECT password_hash FROM {$db->getPrefix()}password_history WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT " . max(1, $limit),
                [$userId]
            );

            return array_values(array_map(static fn(object $row): string => (string) $row->password_hash, $rows));
        };
        $this->currentHashReader = $overrides['currentHash'] ?? static function (int $userId): ?string {
            $db = Database::instance();
            $hash = $db->get_var("SELECT password FROM {$db->getPrefix()}users WHERE id = ? LIMIT 1", [$userId]);

            return is_string($hash) && $hash !== '' ? $hash : null;
        };
        $this->changedAtReader = $overrides['changedAt'] ?? static function (int $userId): ?string {
            $db = Database::instance();
            $row = $db->get_row("SELECT password_changed_at, created_at FROM {$db->getPrefix()}users WHERE id = ? LIMIT 1", [$userId]);
            if ($row === null) {
                return null;
            }
            $value = (string) ($row->password_changed_at ?? '');

            return $value !== '' ? $value : ((string) ($row->created_at ?? '') ?: null);
        };
        $this->historyWriter = $overrides['historyWriter'] ?? static function (int $userId, string $hash, int $keep): void {
            $db = Database::instance();
            $prefix = $db->getPrefix();
            $db->execute("UPDATE {$prefix}users SET password_changed_at = UTC_TIMESTAMP() WHERE id = ?", [$userId]);
            if ($keep < 1) {
                // Historie abgeschaltet: nichts speichern, Bestand entfernen (Datensparsamkeit).
                $db->execute("DELETE FROM {$prefix}password_history WHERE user_id = ?", [$userId]);

                return;
            }
            $db->execute(
                "INSERT INTO {$prefix}password_history (user_id, password_hash, created_at) VALUES (?, ?, UTC_TIMESTAMP())",
                [$userId, $hash]
            );
            $ids = $db->get_results(
                "SELECT id FROM {$prefix}password_history WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT 1000 OFFSET " . $keep,
                [$userId]
            );
            foreach ($ids as $row) {
                $db->execute("DELETE FROM {$prefix}password_history WHERE id = ?", [(int) $row->id]);
            }
        };
        $this->clock = $overrides['clock'] ?? static fn(): int => time();
    }

    /**
     * Wirksame Richtlinie: Einstellungen → Grenzen → Filter `cms_password_policy` (darf nur verschärfen).
     *
     * @return array{min_length:int, require_uppercase:bool, require_lowercase:bool, require_digit:bool, require_special:bool, max_age_days:int, history:int}
     */
    public function definition(): array
    {
        $configured = self::normalize(($this->settingsReader)());
        $filtered = class_exists(Hooks::class) ? Hooks::applyFilters('cms_password_policy', $configured) : $configured;

        return self::tighten($configured, is_array($filtered) ? self::normalize($filtered) : $configured);
    }

    /**
     * Normalisiert rohe Werte (Formular, Einstellungen, Filter) auf Typen und Grenzen; fehlende Werte = Standard.
     *
     * @param array<string, mixed> $raw
     * @return array{min_length:int, require_uppercase:bool, require_lowercase:bool, require_digit:bool, require_special:bool, max_age_days:int, history:int}
     */
    public static function normalize(array $raw): array
    {
        $int = static function (mixed $value, int $default, int $min, int $max): int {
            if (is_string($value) && trim($value) === '') {
                return $default;
            }
            $number = filter_var($value, FILTER_VALIDATE_INT);

            return $number === false || $number === null ? $default : max($min, min($max, (int) $number));
        };
        $bool = static function (mixed $value, bool $default): bool {
            if ($value === null || $value === '') {
                return $default;
            }

            return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
        };

        return [
            'min_length' => $int($raw['min_length'] ?? null, self::DEFAULT_MIN_LENGTH, self::MIN_LENGTH_FLOOR, self::MIN_LENGTH_CEILING),
            'require_uppercase' => $bool($raw['require_uppercase'] ?? null, true),
            'require_lowercase' => $bool($raw['require_lowercase'] ?? null, true),
            'require_digit' => $bool($raw['require_digit'] ?? null, true),
            'require_special' => $bool($raw['require_special'] ?? null, true),
            'max_age_days' => $int($raw['max_age_days'] ?? null, 0, 0, self::MAX_AGE_CEILING),
            'history' => $int($raw['history'] ?? null, self::DEFAULT_HISTORY, 0, self::HISTORY_CEILING),
        ];
    }

    /**
     * Der Filter darf die Richtlinie nur verschärfen: längere Mindestlänge, zusätzliche Klassen, kürzeres Höchstalter,
     * längere Historie – nie umgekehrt.
     *
     * @param array<string, mixed> $base
     * @param array<string, mixed> $candidate
     * @return array{min_length:int, require_uppercase:bool, require_lowercase:bool, require_digit:bool, require_special:bool, max_age_days:int, history:int}
     */
    public static function tighten(array $base, array $candidate): array
    {
        $maxAge = (int) $base['max_age_days'];
        $candidateAge = (int) $candidate['max_age_days'];
        if ($candidateAge > 0 && ($maxAge === 0 || $candidateAge < $maxAge)) {
            $maxAge = $candidateAge;
        }

        return [
            'min_length' => max((int) $base['min_length'], (int) $candidate['min_length']),
            'require_uppercase' => (bool) $base['require_uppercase'] || (bool) $candidate['require_uppercase'],
            'require_lowercase' => (bool) $base['require_lowercase'] || (bool) $candidate['require_lowercase'],
            'require_digit' => (bool) $base['require_digit'] || (bool) $candidate['require_digit'],
            'require_special' => (bool) $base['require_special'] || (bool) $candidate['require_special'],
            'max_age_days' => $maxAge,
            'history' => max((int) $base['history'], (int) $candidate['history']),
        ];
    }

    /**
     * Speichert die Richtlinie aus Formularwerten (normalisiert, Grenzen erzwungen). Nicht angehakte Klassen sind aus.
     *
     * @param array<string, mixed> $input Formularwerte
     * @return array{min_length:int, require_uppercase:bool, require_lowercase:bool, require_digit:bool, require_special:bool, max_age_days:int, history:int}
     */
    public static function save(array $input): array
    {
        $values = self::normalize([
            'min_length' => $input['min_length'] ?? null,
            'require_uppercase' => !empty($input['require_uppercase']),
            'require_lowercase' => !empty($input['require_lowercase']),
            'require_digit' => !empty($input['require_digit']),
            'require_special' => !empty($input['require_special']),
            'max_age_days' => $input['max_age_days'] ?? null,
            'history' => $input['history'] ?? null,
        ]);
        SettingsService::getInstance()->setMany(self::SETTINGS_GROUP, array_map(
            static fn(int|bool $value): string => is_bool($value) ? ($value ? '1' : '0') : (string) $value,
            $values
        ));

        return $values;
    }

    /**
     * Prüft ein Passwort; mit Benutzer-ID zusätzlich gegen die Historie.
     *
     * @return array{valid:bool, message:?string, length:int, min_length:int, requirements:list<array{key:string,label:string,message:string,passed:bool}>, definition:array<string,mixed>}
     */
    public function evaluate(string $password, ?int $userId = null): array
    {
        $policy = $this->definition();
        $length = self::countLength($password);

        $requirements = [[
            'key' => 'min_length',
            'label' => 'Mindestens ' . $policy['min_length'] . ' Zeichen',
            'message' => 'Das Passwort muss mindestens ' . $policy['min_length'] . ' Zeichen lang sein.',
            'passed' => $length >= $policy['min_length'],
        ]];
        foreach (self::CLASSES as $key => $class) {
            if (empty($policy['require_' . $key])) {
                continue;
            }
            $requirements[] = [
                'key' => $key,
                'label' => $class['label'],
                'message' => $class['message'],
                'passed' => preg_match('/' . $class['source'] . '/u', $password) === 1,
            ];
        }
        if ($userId !== null && $userId > 0 && $policy['history'] > 0) {
            $requirements[] = [
                'key' => 'history',
                'label' => 'Keines der letzten ' . $policy['history'] . ' Passwörter',
                'message' => 'Dieses Passwort wurde kürzlich verwendet. Bitte wähle ein anderes (keines der letzten ' . $policy['history'] . ').',
                'passed' => $password === '' || !$this->isReused($userId, $password, $policy['history']),
            ];
        }

        $message = null;
        foreach ($requirements as $requirement) {
            if (!$requirement['passed']) {
                $message = $requirement['message'];
                break;
            }
        }

        $patterns = [];
        foreach (self::CLASSES as $key => $class) {
            if (!empty($policy['require_' . $key])) {
                $patterns[$key] = $class['source'];
            }
        }

        return [
            'valid' => $message === null,
            'message' => $message,
            'length' => $length,
            'min_length' => $policy['min_length'],
            'requirements' => $requirements,
            'definition' => [
                'min_length' => $policy['min_length'],
                'patterns' => $patterns,
                'max_age_days' => $policy['max_age_days'],
                'history' => $policy['history'],
            ],
        ];
    }

    public function validate(string $password, ?int $userId = null): true|string
    {
        $evaluation = $this->evaluate($password, $userId);

        return $evaluation['valid'] ? true : (string) ($evaluation['message'] ?? 'Die Passwort-Richtlinie ist nicht erfüllt.');
    }

    /**
     * Wurde das Passwort unter den letzten N verwendet (einschließlich des aktuellen)?
     */
    public function isReused(int $userId, string $password, ?int $limit = null): bool
    {
        $limit ??= $this->definition()['history'];
        if ($userId <= 0 || $limit < 1 || $password === '') {
            return false;
        }

        $hashes = ($this->historyReader)($userId, $limit);
        $current = ($this->currentHashReader)($userId);
        if ($current !== null && !in_array($current, $hashes, true)) {
            array_unshift($hashes, $current);
        }

        foreach (array_slice($hashes, 0, $limit) as $hash) {
            if ($hash !== '' && password_verify($password, $hash)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Nach dem Speichern eines neuen Passworts aufrufen: Historie und Passwort-Alter aktualisieren.
     */
    public function recordChange(int $userId, string $newHash, string $source): void
    {
        if ($userId <= 0 || $newHash === '') {
            return;
        }

        try {
            ($this->historyWriter)($userId, $newHash, $this->definition()['history']);
        } catch (\Throwable $e) {
            error_log('PasswordPolicyService::recordChange() fehlgeschlagen: ' . $e->getMessage());
        }

        if (class_exists(Hooks::class)) {
            Hooks::doAction('cms_password_changed', $userId, $source);
        }
    }

    /**
     * Ist das Passwort älter als das Höchstalter? Ohne Höchstalter nie.
     */
    public function isExpired(int $userId): bool
    {
        $maxAge = $this->definition()['max_age_days'];
        if ($userId <= 0 || $maxAge < 1) {
            return false;
        }

        $changedAt = ($this->changedAtReader)($userId);
        if ($changedAt === null) {
            return false;
        }
        $timestamp = strtotime($changedAt . ' UTC');
        if ($timestamp === false) {
            return false;
        }

        return ($this->clock)() - $timestamp > $maxAge * 86400;
    }

    private static function countLength(string $password): int
    {
        if ($password === '') {
            return 0;
        }
        if (function_exists('mb_strlen')) {
            return mb_strlen($password, 'UTF-8');
        }
        $count = preg_match_all('/./us', $password);

        return $count !== false ? $count : strlen($password);
    }
}
