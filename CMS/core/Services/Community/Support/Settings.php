<?php
/**
 * Einstellungen des Community-Moduls: Gruppe `community`, flache snake_case-Schlüssel (Option `community.<schlüssel>`).
 *
 * - `DEFINITIONS` ist die eine Quelle für Typ, Standard und Grenzen; nur Schlüssel mit Leser im Code stehen darin
 *   (`TESTS/community-settings` prüft Leser und Wirkungstest je Schlüssel).
 * - `normalize()` ist rein (ohne Datenbank): klemmt Zahlen, erzwingt Typen, füllt Strukturen auf die Standards auf und
 *   verwirft ungültige Werte zugunsten des Standards. Rohwerte in der Tabelle `settings` dürfen verschmutzt sein.
 * - Gelesen wird einmal je Request über `SettingsService::getGroup('community')` (eine Abfrage), nicht über `get()` je
 *   Schlüssel. Standards werden nie in die Datenbank geschrieben: `save()` schreibt nur Werte, die vom aktuell
 *   wirksamen Wert abweichen.
 * - Textwerte mit dem Präfix `json:` oder `enc:` würde `SettingsService` beim Lesen als JSON bzw. Chiffre deuten;
 *   der einzige Textwert (`base_path`) lässt wegen seines Musters kein `:` zu. Strukturen (`map`) speichert `SettingsService`
 *   als `json:`-Text; Texte darin (Wortfilter, Ersatztext) sind davon nicht betroffen.
 * - Grenzen je Vertrauensstufe (`trust_limits`): `-1` = unbegrenzt (`UNLIMITED`), `0` = keine.
 *
 * @package CMSv2\Core\Services\Community
 */

declare(strict_types=1);

namespace CMS\Services\Community\Support;

use CMS\Logger;
use CMS\Services\SettingsService;

if (!defined('ABSPATH')) {
    exit;
}

final class Settings
{
    /**
     * Einstellungsgruppe in der Tabelle `settings`. Nicht zu verwechseln mit `CommunityCapabilities::GROUP`: das ist
     * die Gruppe im Rollen-Katalog.
     */
    public const GROUP = 'community';

    /** Vertrauensstufen 0 (neu) bis 4 (Leitung). */
    public const TRUST_LEVELS = [0, 1, 2, 3, 4];

    /**
     * Felder der Grenzen je Vertrauensstufe: Beiträge je Tag, Themen je Stunde (CommunityAccess), Links, Bilder und Anhänge je
     * Beitrag (`can()` mit Kontext `content`), Anhänge je Tag (AttachmentService über `limitsFor()`).
     */
    public const LIMIT_FIELDS = ['posts_per_day', 'topics_per_hour', 'links_per_post', 'images_per_post', 'attachments_per_post', 'attachments_per_day'];

    /** Wert eines Feldes in `trust_limits` für „unbegrenzt“; `0` heißt „keine“. */
    public const UNLIMITED = -1;

    /** Obergrenze je Feld in `trust_limits`; Werte unter `UNLIMITED` ergeben den Standard des Feldes. */
    public const LIMIT_MAX = 100000;

    /** Höchstzahl der Einträge im Wortfilter (block, review, replace zusammen, in dieser Vorrangreihenfolge). */
    public const WORD_FILTER_MAX = 500;

    /** Listen des Wortfilters in Vorrangreihenfolge: ein Eintrag in mehreren Listen bleibt nur in der ersten. */
    public const WORD_FILTER_LISTS = ['block', 'review', 'replace'];

    /** Standard des Wortfilters: keine Einträge, Ersatztext `***`. */
    public const DEFAULT_WORD_FILTER = ['block' => [], 'review' => [], 'replace' => [], 'replacement' => '***'];

    /** Alle Reaktionen, die `reaction_set` enthalten darf. */
    public const REACTION_KEYS = ['like', 'love', 'laugh', 'idea', 'thanks', 'wow', 'sad', 'celebrate'];

    /** Standard von `reaction_set`; `[]` schaltet Reaktionen ab. */
    public const DEFAULT_REACTION_SET = ['like', 'love', 'laugh', 'idea'];

    /** Endungen, die `attachment_types` kennt (gleich `array_keys(AttachmentService::TYPES)`). */
    public const ATTACHMENT_TYPES = ['jpg', 'png', 'webp', 'gif', 'pdf', 'zip', 'txt'];

    /** Standard von `attachment_types` (1 = erlaubt). */
    public const DEFAULT_ATTACHMENT_TYPES = ['jpg' => 1, 'png' => 1, 'webp' => 1, 'gif' => 1, 'pdf' => 1, 'zip' => 1, 'txt' => 0];

    /** Schlüssel der Forum-Rechtematrix (`permissions_json` und `default_permissions`). */
    public const PERMISSION_KEYS = [
        'read', 'create_topic', 'reply', 'edit_own', 'delete_own', 'upload',
        'poll', 'vote', 'react', 'mark_solution', 'bypass_approval', 'bypass_slow_mode',
    ];

    /** Subjekte der Rechte-Standards: Gast und angemeldetes Mitglied (jede Rolle). */
    public const PERMISSION_SUBJECTS = ['guest', 'member'];

    /** Erste Pfadsegmente, die `base_path` nicht belegen darf (zusätzlich jedes Segment mit dem Präfix `cms-`). */
    public const RESERVED_BASE_PATHS = ['admin', 'member', 'api', 'en', 'assets', 'uploads', 'themes', 'plugins'];

    public const BASE_PATH_PATTERN = '/^[a-z0-9][a-z0-9-]{0,39}$/';

    /**
     * Standards der Grenzen je Vertrauensstufe (`-1` = unbegrenzt, `0` = keine). Die Zahlen sind Platzhalter, in PR #3 im Admin
     * änderbar (DESIGN-2b I-34). Beiträge je Tag und Themen je Stunde sind nie 0.
     *
     * @var array<int, array{posts_per_day: int, topics_per_hour: int, links_per_post: int, images_per_post: int, attachments_per_post: int, attachments_per_day: int}>
     */
    public const DEFAULT_TRUST_LIMITS = [
        0 => ['posts_per_day' => 5, 'topics_per_hour' => 1, 'links_per_post' => 1, 'images_per_post' => 0, 'attachments_per_post' => 0, 'attachments_per_day' => 0],
        1 => ['posts_per_day' => 30, 'topics_per_hour' => 3, 'links_per_post' => 5, 'images_per_post' => 10, 'attachments_per_post' => 3, 'attachments_per_day' => 20],
        2 => ['posts_per_day' => 100, 'topics_per_hour' => 5, 'links_per_post' => -1, 'images_per_post' => -1, 'attachments_per_post' => 3, 'attachments_per_day' => 50],
        3 => ['posts_per_day' => 300, 'topics_per_hour' => 10, 'links_per_post' => -1, 'images_per_post' => -1, 'attachments_per_post' => 3, 'attachments_per_day' => 100],
        4 => ['posts_per_day' => 300, 'topics_per_hour' => 10, 'links_per_post' => -1, 'images_per_post' => -1, 'attachments_per_post' => 3, 'attachments_per_day' => 100],
    ];

    /**
     * Rechte-Standards für Foren ohne eigene Rechte-Zeilen (Wert 1 = gewährt). `guest.read` gilt nur bei offenem Tor
     * `guests_can_read`; `Settings::permissionDefaults('guest')` verknüpft beides.
     *
     * @var array<string, array<string, int>>
     */
    public const DEFAULT_PERMISSIONS = [
        'guest' => [
            'read' => 1, 'create_topic' => 0, 'reply' => 0, 'edit_own' => 0, 'delete_own' => 0, 'upload' => 0,
            'poll' => 0, 'vote' => 0, 'react' => 0, 'mark_solution' => 0, 'bypass_approval' => 0, 'bypass_slow_mode' => 0,
        ],
        'member' => [
            'read' => 1, 'create_topic' => 1, 'reply' => 1, 'edit_own' => 1, 'delete_own' => 0, 'upload' => 1,
            'poll' => 0, 'vote' => 1, 'react' => 1, 'mark_solution' => 1, 'bypass_approval' => 0, 'bypass_slow_mode' => 0,
        ],
    ];

    /**
     * Typ, Standard und Grenzen je Schlüssel. Typen: `slug`, `bool`, `int` (`min`, `max`), `map` (`schema`).
     *
     * @var array<string, array<string, mixed>>
     */
    /** Wann Mitglieder beim Schreiben ein Captcha lösen: nie, bis zum ersten veröffentlichten Beitrag, auf Vertrauensstufe 0, immer. */
    public const CAPTCHA_MODES = ['off', 'first_post', 'trust0', 'always'];

    /** Verfahren des `CaptchaService`. */
    public const CAPTCHA_PROVIDERS = ['pow', 'image', 'math'];

    public const DEFINITIONS = [
        'base_path' => ['type' => 'slug', 'default' => 'forum'],
        'guests_can_read' => ['type' => 'bool', 'default' => false],
        'require_verified_email' => ['type' => 'bool', 'default' => true],
        'topics_per_page' => ['type' => 'int', 'default' => 20, 'min' => 5, 'max' => 100],
        'posts_per_page' => ['type' => 'int', 'default' => 15, 'min' => 5, 'max' => 100],
        'title_min' => ['type' => 'int', 'default' => 5, 'min' => 1, 'max' => 100],
        'title_max' => ['type' => 'int', 'default' => 200, 'min' => 10, 'max' => 200],
        'post_min' => ['type' => 'int', 'default' => 2, 'min' => 1, 'max' => 1000],
        'post_max' => ['type' => 'int', 'default' => 50000, 'min' => 100, 'max' => 200000],
        'edit_window_minutes' => ['type' => 'int', 'default' => 30, 'min' => 0, 'max' => 10080],
        'edit_window_minutes_trusted' => ['type' => 'int', 'default' => 1440, 'min' => 0, 'max' => 10080],
        'rate_post_seconds' => ['type' => 'int', 'default' => 30, 'min' => 0, 'max' => 3600],
        'rate_topic_seconds' => ['type' => 'int', 'default' => 120, 'min' => 0, 'max' => 86400],
        'new_member_approval_posts' => ['type' => 'int', 'default' => 2, 'min' => 0, 'max' => 50],
        'max_pending_per_user' => ['type' => 'int', 'default' => 3, 'min' => 1, 'max' => 20],
        'duplicate_window_seconds' => ['type' => 'int', 'default' => 120, 'min' => 10, 'max' => 3600],
        'trust_level1_posts' => ['type' => 'int', 'default' => 1, 'min' => 0, 'max' => 1000],
        'trust_level2_posts' => ['type' => 'int', 'default' => 20, 'min' => 1, 'max' => 10000],
        'trust_limits' => ['type' => 'map', 'schema' => 'trust_limits', 'default' => self::DEFAULT_TRUST_LIMITS],
        'default_permissions' => ['type' => 'map', 'schema' => 'permissions', 'default' => self::DEFAULT_PERMISSIONS],
        'unread_horizon_days' => ['type' => 'int', 'default' => 90, 'min' => 1, 'max' => 180],
        // PR #2b (Vertrag DESIGN-2b §3.1)
        'word_filter' => ['type' => 'map', 'schema' => 'word_filter', 'default' => self::DEFAULT_WORD_FILTER],
        'use_core_antispam' => ['type' => 'bool', 'default' => false],
        'spam_duplicate_hours' => ['type' => 'int', 'default' => 24, 'min' => 0, 'max' => 168],
        'ip_hash_days' => ['type' => 'int', 'default' => 30, 'min' => 0, 'max' => 90],
        'moved_stub_days' => ['type' => 'int', 'default' => 7, 'min' => 1, 'max' => 365],
        'moderation_reason_required' => ['type' => 'bool', 'default' => true],
        'report_hide_threshold' => ['type' => 'int', 'default' => 3, 'min' => 0, 'max' => 20],
        'moderator_ban_max_days' => ['type' => 'int', 'default' => 30, 'min' => 1, 'max' => 365],
        'notify_mail' => ['type' => 'bool', 'default' => true],
        'reaction_set' => ['type' => 'map', 'schema' => 'reaction_set', 'default' => self::DEFAULT_REACTION_SET],
        'attachment_types' => ['type' => 'map', 'schema' => 'attachment_types', 'default' => self::DEFAULT_ATTACHMENT_TYPES],
        'attachment_max_kb' => ['type' => 'int', 'default' => 5120, 'min' => 64, 'max' => 51200],
        // PR #5 (Captcha, E7): wann ein Captcha verlangt wird und welches Verfahren (`CaptchaService`)
        'captcha_mode' => ['type' => 'enum', 'values' => self::CAPTCHA_MODES, 'default' => 'first_post'],
        'captcha_provider' => ['type' => 'enum', 'values' => self::CAPTCHA_PROVIDERS, 'default' => 'pow'],
    ];

    /** Paare (Mindest- vor Höchstwert): ein Mindestwert über dem Höchstwert wird auf den Höchstwert gesenkt. */
    private const MIN_MAX_PAIRS = ['title_min' => 'title_max', 'post_min' => 'post_max'];

    private static ?self $instance = null;

    /** @var \Closure(): array<string, mixed> */
    private \Closure $reader;

    /** @var \Closure(array<string, mixed>): bool */
    private \Closure $writer;

    /** @var array<string, mixed>|null */
    private ?array $cache = null;

    /**
     * @param \Closure(): array<string, mixed>|null $reader Rohwerte der Gruppe (Standard: SettingsService::getGroup)
     * @param \Closure(array<string, mixed>): bool|null $writer schreibt die übergebenen Schlüssel (Standard: SettingsService::setMany)
     */
    public function __construct(?\Closure $reader = null, ?\Closure $writer = null)
    {
        $this->reader = $reader ?? static fn (): array => SettingsService::getInstance()->getGroup(self::GROUP);
        $this->writer = $writer ?? static fn (array $values): bool => SettingsService::getInstance()->setMany(self::GROUP, $values);
    }

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    /** Nur für Tests: ersetzt die gemeinsame Instanz (null = neu aufbauen). */
    public static function setInstance(?self $instance): void
    {
        self::$instance = $instance;
    }

    /**
     * Einstellungen aus festen Rohwerten (Tests, Wirkungstests der Dienste); schreibt nichts.
     *
     * @param array<string, mixed> $raw
     */
    public static function fromArray(array $raw): self
    {
        return new self(static fn (): array => $raw, static fn (array $values): bool => true);
    }

    /**
     * Rohwerte → vollständige, gültige Einstellungen (alle Schlüssel aus DEFINITIONS, Typen und Grenzen eingehalten).
     * Unbekannte Schlüssel entfallen, ungültige Werte ergeben den Standard.
     *
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    public static function normalize(array $raw): array
    {
        $result = [];
        foreach (self::DEFINITIONS as $key => $definition) {
            $value = $raw[$key] ?? null;
            $result[$key] = match ($definition['type']) {
                'int' => self::parseInt($value, $definition['min'], $definition['max'], $definition['default']),
                'bool' => self::parseBool($value) ?? $definition['default'],
                'slug' => self::parseSlug($value, $definition['default']),
                'enum' => self::parseEnum($value, $definition['values'], $definition['default']),
                default => match ($definition['schema']) {
                    'trust_limits' => self::parseTrustLimits($value),
                    'word_filter' => self::parseWordFilter($value),
                    'reaction_set' => self::parseReactionSet($value),
                    'attachment_types' => self::parseAttachmentTypes($value),
                    default => self::parsePermissions($value),
                },
            };
        }

        foreach (self::MIN_MAX_PAIRS as $min => $max) {
            if ($result[$min] > $result[$max]) {
                $result[$min] = $result[$max];
            }
        }

        return $result;
    }

    /**
     * Alle wirksamen Einstellungen; die Rohwerte werden einmal je Instanz gelesen.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        if ($this->cache === null) {
            try {
                $raw = ($this->reader)();
            } catch (\Throwable $e) {
                Logger::instance()->withChannel('community')->warning('Community-Einstellungen nicht lesbar, Standards gelten: {exception}', [
                    'exception' => $e,
                ]);
                $raw = [];
            }
            $this->cache = self::normalize($raw);
        }

        return $this->cache;
    }

    public function get(string $key): mixed
    {
        $all = $this->all();
        if (!array_key_exists($key, $all)) {
            throw new \InvalidArgumentException('Unbekannte Community-Einstellung: ' . $key);
        }

        return $all[$key];
    }

    public function int(string $key): int
    {
        $value = $this->get($key);
        if (!is_int($value)) {
            throw new \InvalidArgumentException('Community-Einstellung ist keine Zahl: ' . $key);
        }

        return $value;
    }

    public function bool(string $key): bool
    {
        $value = $this->get($key);
        if (!is_bool($value)) {
            throw new \InvalidArgumentException('Community-Einstellung ist kein Schalter: ' . $key);
        }

        return $value;
    }

    public function string(string $key): string
    {
        $value = $this->get($key);
        if (!is_string($value)) {
            throw new \InvalidArgumentException('Community-Einstellung ist kein Text: ' . $key);
        }

        return $value;
    }

    /** @return array<int|string, mixed> */
    public function map(string $key): array
    {
        $value = $this->get($key);
        if (!is_array($value)) {
            throw new \InvalidArgumentException('Community-Einstellung ist keine Struktur: ' . $key);
        }

        return $value;
    }

    /**
     * Grenzen einer Vertrauensstufe, alle Felder aus `LIMIT_FIELDS` (`-1` = unbegrenzt, `0` = keine). Stufen außerhalb 0 bis 4
     * werden an den Rand gelegt.
     *
     * @return array{posts_per_day: int, topics_per_hour: int, links_per_post: int, images_per_post: int, attachments_per_post: int, attachments_per_day: int}
     */
    public function limitsForLevel(int $level): array
    {
        $level = max(self::TRUST_LEVELS[0], min(self::TRUST_LEVELS[count(self::TRUST_LEVELS) - 1], $level));
        $limits = $this->map('trust_limits')[$level];
        $result = [];
        foreach (self::LIMIT_FIELDS as $field) {
            $result[$field] = (int) $limits[$field];
        }

        return $result;
    }

    /**
     * Rechte-Standards eines Subjekts (`guest` oder `member`) für Foren ohne eigene Rechte-Zeilen. Beim Gast gilt
     * `read` nur bei offenem Tor `guests_can_read`. Unbekanntes Subjekt: alles verweigert.
     *
     * @return array<string, bool>
     */
    public function permissionDefaults(string $subject): array
    {
        $rows = $this->map('default_permissions');
        if (!isset($rows[$subject])) {
            return array_fill_keys(self::PERMISSION_KEYS, false);
        }

        $defaults = array_map(static fn (mixed $flag): bool => $flag === 1, $rows[$subject]);
        if ($subject === 'guest') {
            $defaults['read'] = $defaults['read'] && $this->bool('guests_can_read');
        }

        return $defaults;
    }

    /**
     * Speichert Werte. Unbekannte Schlüssel entfallen, ungültige Werte ergeben den Standard (wie beim Lesen). Geschrieben
     * werden nur Schlüssel, deren Wert vom aktuell wirksamen Wert abweicht: ein Standard landet nie ungefragt in der
     * Datenbank, ein früher gespeicherter Wert lässt sich aber auf den Standard zurücksetzen.
     *
     * @param array<string, mixed> $values
     */
    public function save(array $values): bool
    {
        $input = array_intersect_key($values, self::DEFINITIONS);
        $current = $this->all();
        $next = self::normalize(array_merge($current, $input));

        $changed = [];
        foreach (array_keys($input) as $key) {
            if ($next[$key] !== $current[$key]) {
                $changed[$key] = $next[$key];
            }
        }
        if ($changed === []) {
            return true;
        }

        try {
            $written = ($this->writer)($changed) === true;
        } catch (\Throwable $e) {
            Logger::instance()->withChannel('community')->warning('Community-Einstellungen nicht gespeichert: {exception}', [
                'exception' => $e,
            ]);
            $written = false;
        }
        $this->invalidate();

        return $written;
    }

    /** Verwirft die gelesenen Werte (nach Schreibzugriffen anderer, in Tests). */
    public function invalidate(): void
    {
        $this->cache = null;
    }

    private static function parseInt(mixed $value, int $min, int $max, int $default): int
    {
        if (is_int($value)) {
            return max($min, min($max, $value));
        }
        if (is_float($value)) {
            if (!is_finite($value) || floor($value) !== $value) {
                return $default;
            }

            return $value <= $min ? $min : ($value >= $max ? $max : (int) $value);
        }
        if (!is_string($value) || preg_match('/^\s*([+-]?)0*(\d+)\s*$/', $value, $m) !== 1) {
            return $default;
        }
        if (strlen($m[2]) > 15) {
            return $m[1] === '-' ? $min : $max;
        }

        return max($min, min($max, $m[1] === '-' ? -(int) $m[2] : (int) $m[2]));
    }

    /** @return bool|null null bei ungültigem Wert */
    private static function parseBool(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value === 1 ? true : ($value === 0 ? false : null);
        }
        if (!is_string($value)) {
            return null;
        }

        return match (strtolower(trim($value))) {
            '1', 'true', 'yes', 'on' => true,
            '0', 'false', 'no', 'off' => false,
            default => null,
        };
    }

    /**
     * Auswahl aus einer festen Liste (Groß-/Kleinschreibung und Leerraum am Rand egal); alles andere ergibt den Standard.
     *
     * @param list<string> $values
     */
    private static function parseEnum(mixed $value, array $values, string $default): string
    {
        if (!is_string($value)) {
            return $default;
        }
        $value = strtolower(trim($value));

        return in_array($value, $values, true) ? $value : $default;
    }

    private static function parseSlug(mixed $value, string $default): string
    {
        if (!is_string($value)) {
            return $default;
        }

        $slug = strtolower(trim($value, " \t\n\r\0\x0B/"));
        if (preg_match(self::BASE_PATH_PATTERN, $slug) !== 1
            || in_array($slug, self::RESERVED_BASE_PATHS, true)
            || str_starts_with($slug, 'cms-')
        ) {
            return $default;
        }

        return $slug;
    }

    /**
     * Struktur aus einem Array oder JSON-Text (mit oder ohne `json:`-Präfix); `$opening` sind die erlaubten ersten Zeichen des
     * Texts (`{` für Objekte, `[` für Listen).
     *
     * @return array<int|string, mixed>|null
     */
    private static function decodeMap(mixed $value, string $opening = '{'): ?array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value)) {
            return null;
        }

        $text = trim($value);
        if (str_starts_with($text, 'json:')) {
            $text = substr($text, 5);
        }
        if ($text === '' || !str_contains($opening, $text[0])) {
            return null;
        }

        try {
            $decoded = json_decode($text, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /** @return array<int, array<string, int>> je Stufe alle Felder aus LIMIT_FIELDS */
    private static function parseTrustLimits(mixed $value): array
    {
        $input = self::decodeMap($value) ?? [];
        $result = [];
        foreach (self::TRUST_LEVELS as $level) {
            $row = is_array($input[$level] ?? null) ? $input[$level] : [];
            foreach (self::LIMIT_FIELDS as $field) {
                $result[$level][$field] = self::parseLimit($row[$field] ?? null, self::DEFAULT_TRUST_LIMITS[$level][$field]);
            }
        }

        return $result;
    }

    /** Ein Feld von `trust_limits`: `-1` bis `LIMIT_MAX`; darunter (fail-safe) oder ungültig der Standard, darüber `LIMIT_MAX`. */
    private static function parseLimit(mixed $value, int $default): int
    {
        $parsed = self::parseInt($value, PHP_INT_MIN, self::LIMIT_MAX, $default);

        return $parsed < self::UNLIMITED ? $default : $parsed;
    }

    /**
     * Wortfilter: drei Listen (`block`, `review`, `replace`) und der Ersatztext. Einträge werden klein geschrieben (NFC,
     * Leerraum zu einem Leerzeichen) und gelten nur mit 2 bis 100 Zeichen, mindestens einem Buchstaben oder einer Ziffer,
     * ohne Steuerzeichen und mit `*` höchstens als letztem Zeichen. Doppelte Einträge bleiben nur in der Liste mit Vorrang
     * (`block` > `review` > `replace`); zusammen gelten höchstens `WORD_FILTER_MAX` Einträge in dieser Reihenfolge.
     *
     * @return array{block: list<string>, review: list<string>, replace: list<string>, replacement: string}
     */
    private static function parseWordFilter(mixed $value): array
    {
        $input = self::decodeMap($value) ?? [];
        $entries = [];
        $seen = [];
        foreach (self::WORD_FILTER_LISTS as $list) {
            foreach (is_array($input[$list] ?? null) ? $input[$list] : [] as $raw) {
                $entry = self::wordFilterEntry($raw);
                if ($entry !== null && !isset($seen[$entry])) {
                    $seen[$entry] = true;
                    $entries[] = [$list, $entry];
                }
            }
        }

        $result = ['block' => [], 'review' => [], 'replace' => []];
        foreach (array_slice($entries, 0, self::WORD_FILTER_MAX) as [$list, $entry]) {
            $result[$list][] = $entry;
        }
        $result['replacement'] = self::wordFilterReplacement($input['replacement'] ?? null);

        return $result;
    }

    /** Ein Eintrag des Wortfilters in Speicherform oder `null`, wenn er nicht gilt. */
    private static function wordFilterEntry(mixed $raw): ?string
    {
        if (!is_string($raw) || preg_match('//u', $raw) !== 1) {
            return null;
        }
        $entry = trim((string) preg_replace('/[\s\p{Z}]+/u', ' ', $raw));
        if (class_exists(\Normalizer::class)) {
            $entry = (string) \Normalizer::normalize($entry, \Normalizer::FORM_C);
        }
        $entry = mb_strtolower($entry, 'UTF-8');
        $length = mb_strlen($entry, 'UTF-8');
        if ($length < 2 || $length > 100
            || preg_match('/[\p{L}\p{N}]/u', $entry) !== 1
            || preg_match('/\p{Cc}/u', $entry) === 1
            || preg_match('/\*(?!$)/u', $entry) === 1
        ) {
            return null;
        }

        return $entry;
    }

    /** Ersatztext: 1 bis 20 Zeichen ohne Buchstaben, Ziffern und Steuerzeichen (kann so in keinem Eintrag erneut treffen), sonst `***`. */
    private static function wordFilterReplacement(mixed $raw): string
    {
        if (!is_string($raw) || preg_match('//u', $raw) !== 1) {
            return self::DEFAULT_WORD_FILTER['replacement'];
        }
        $length = mb_strlen($raw, 'UTF-8');
        if ($length < 1 || $length > 20 || preg_match('/[\p{L}\p{N}\p{Cc}]/u', $raw) === 1) {
            return self::DEFAULT_WORD_FILTER['replacement'];
        }

        return $raw;
    }

    /**
     * Reaktionen zur Auswahl: Liste (Array oder JSON-Text) aus `REACTION_KEYS`, eindeutig, Reihenfolge erhalten; fremde Werte
     * entfallen, `[]` schaltet Reaktionen ab. Keine Liste (auch ein JSON-Objekt) ergibt den Standard.
     *
     * @return list<string>
     */
    private static function parseReactionSet(mixed $value): array
    {
        $input = self::decodeMap($value, '[');
        if ($input === null || !array_is_list($input)) {
            return self::DEFAULT_REACTION_SET;
        }
        $result = [];
        foreach ($input as $key) {
            if (is_string($key) && in_array($key, self::REACTION_KEYS, true) && !in_array($key, $result, true)) {
                $result[] = $key;
            }
        }

        return $result;
    }

    /** @return array<string, int> Endung aus ATTACHMENT_TYPES => 0|1 (fehlend oder ungültig: Standard) */
    private static function parseAttachmentTypes(mixed $value): array
    {
        $input = self::decodeMap($value) ?? [];
        $result = [];
        foreach (self::ATTACHMENT_TYPES as $type) {
            $flag = self::parseBool($input[$type] ?? null);
            $result[$type] = $flag === null ? self::DEFAULT_ATTACHMENT_TYPES[$type] : ($flag ? 1 : 0);
        }

        return $result;
    }

    /** @return array<string, array<string, int>> */
    private static function parsePermissions(mixed $value): array
    {
        $input = self::decodeMap($value) ?? [];
        $result = [];
        foreach (self::PERMISSION_SUBJECTS as $subject) {
            $row = is_array($input[$subject] ?? null) ? $input[$subject] : [];
            foreach (self::PERMISSION_KEYS as $key) {
                $flag = self::parseBool($row[$key] ?? null);
                $result[$subject][$key] = $flag === null ? self::DEFAULT_PERMISSIONS[$subject][$key] : ($flag ? 1 : 0);
            }
        }

        return $result;
    }
}
