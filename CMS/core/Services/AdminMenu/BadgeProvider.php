<?php
/**
 * Zähler für die Badges im Admin-Menü (Updates, Kommentare, Eingänge, DSGVO-Anfragen, Protokolle).
 *
 * Badges werden nie gecacht (KLARSTELLUNGEN §7): Der Menübaum kommt aus dem Cache, die Zahlen
 * werden danach bei jedem Aufruf frisch ermittelt – und nur für Schlüssel, die im sichtbaren Baum
 * vorkommen. Remote-Abfragen finden hier nie statt: Update-Zahlen stammen ausschließlich aus den
 * Caches der Update-Prüfung (UpdateService / AdminNoticeService). Jede Quelle ist gekapselt;
 * ein Fehler liefert 0 statt die Seite zu stören.
 *
 * Weitere Inhaltsarten (z. B. Formular-Eingänge ab Phase B) melden ihren Zähler über register().
 *
 * @package CMSv2\Core\Services\AdminMenu
 */

declare(strict_types=1);

namespace CMS\Services\AdminMenu;

use CMS\CacheManager;
use CMS\Database;
use CMS\Json;
use CMS\PluginManager;
use CMS\Services\AdminNoticeService;
use CMS\Services\UpdateService;
use CMS\Version;

if (!defined('ABSPATH')) {
    exit;
}

final class BadgeProvider
{
    public const UPDATES = 'updates';
    public const COMMENTS = 'comments';
    public const FORM_SUBMISSIONS = 'form_submissions';
    public const PRIVACY_REQUESTS = 'privacy_requests';
    public const LOGS = 'logs';

    /** Badge-Schlüssel, die Katalogeinträge tragen dürfen. */
    public const KEYS = [self::UPDATES, self::COMMENTS, self::FORM_SUBMISSIONS, self::PRIVACY_REQUESTS, self::LOGS];

    /** Schlüssel mit eingebauter Quelle; register() kann sie nicht überschreiben. */
    private const BUILT_IN = [self::UPDATES, self::COMMENTS, self::PRIVACY_REQUESTS, self::LOGS];

    private const KEY_PATTERN = '/^[a-z][a-z0-9_]{0,63}$/';
    private const MAX_LABEL = 99;

    /** Screenreader-Texte je Schlüssel: [Einzahl, Mehrzahl mit %d]. */
    private const TEXTS = [
        self::UPDATES => ['1 Update verfügbar', '%d Updates verfügbar'],
        self::COMMENTS => ['1 offener Kommentar', '%d offene Kommentare'],
        self::FORM_SUBMISSIONS => ['1 neuer Eingang', '%d neue Eingänge'],
        self::PRIVACY_REQUESTS => ['1 offene DSGVO-Anfrage', '%d offene DSGVO-Anfragen'],
        self::LOGS => ['1 ungelesener Hinweis', '%d ungelesene Hinweise'],
    ];

    /** @var array<string, \Closure(array<string, mixed>): int> */
    private static array $counters = [];
    /** @var array<string, array{0:string,1:string}> */
    private static array $texts = [];

    public function __construct(private readonly ?Database $db = null)
    {
    }

    /**
     * Meldet den Zähler einer weiteren Inhaltsart an (z. B. FormService für „form_submissions“).
     *
     * @param callable(array<string, mixed>): int $counter erhält den Kontext der Sidebar
     * @param array{0?:string,1?:string} $texts Screenreader-Text Einzahl / Mehrzahl (mit %d)
     */
    public static function register(string $key, callable $counter, array $texts = []): bool
    {
        if (!preg_match(self::KEY_PATTERN, $key) || in_array($key, self::BUILT_IN, true)) {
            return false;
        }

        self::$counters[$key] = \Closure::fromCallable($counter);
        if (isset($texts[0], $texts[1]) && is_string($texts[0]) && is_string($texts[1])) {
            self::$texts[$key] = [$texts[0], $texts[1]];
        }

        return true;
    }

    public static function isKnown(string $key): bool
    {
        return in_array($key, self::KEYS, true) || isset(self::$counters[$key]);
    }

    /**
     * Ermittelt die Zahlen für die übergebenen Schlüssel (jeder Schlüssel einmal).
     *
     * @param list<string> $keys Badge-Schlüssel aus dem sichtbaren Menübaum
     * @param array<string, mixed> $context z. B. ['logs' => ungelesene Hinweise aus der Session]
     * @return array<string, int>
     */
    public function counts(array $keys, array $context = []): array
    {
        $result = [];
        foreach ($keys as $key) {
            if (!is_string($key) || $key === '' || array_key_exists($key, $result) || !self::isKnown($key)) {
                continue;
            }
            $result[$key] = max(0, $this->count($key, $context));
        }

        return $result;
    }

    /**
     * Badge für die Ausgabe: sichtbare Zahl (ab 100 „99+“) und Screenreader-Text.
     * Mehrere Schlüssel (Gruppen, Kachelseiten) werden summiert; der Text nennt jede Quelle.
     *
     * @param array<string, int> $counts Schlüssel => Zahl
     * @return array{label:string,sr:string,count:int}|null null, wenn die Summe 0 ist
     */
    public static function format(array $counts): ?array
    {
        $total = 0;
        $parts = [];
        foreach ($counts as $key => $count) {
            $count = (int) $count;
            if ($count <= 0) {
                continue;
            }
            $total += $count;
            $parts[] = self::text((string) $key, $count);
        }

        if ($total <= 0) {
            return null;
        }

        return [
            'label' => $total > self::MAX_LABEL ? self::MAX_LABEL . '+' : (string) $total,
            'sr' => implode(', ', $parts),
            'count' => $total,
        ];
    }

    private static function text(string $key, int $count): string
    {
        [$one, $other] = self::$texts[$key] ?? self::TEXTS[$key] ?? ['1 Hinweis', '%d Hinweise'];

        return $count === 1 ? $one : sprintf($other, $count);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function count(string $key, array $context): int
    {
        try {
            if (isset(self::$counters[$key])) {
                return (int) (self::$counters[$key])($context);
            }

            return match ($key) {
                self::UPDATES => $this->countUpdates(),
                self::COMMENTS => $this->countRows('comments', 'status = ?', ['pending']),
                self::PRIVACY_REQUESTS => $this->countRows('privacy_requests', 'status IN (?, ?)', ['pending', 'processing'], true),
                self::LOGS => (int) ($context[self::LOGS] ?? 0),
                default => 0,
            };
        } catch (\Throwable) {
            return 0;
        }
    }

    private function database(): Database
    {
        return $this->db ?? Database::instance();
    }

    /**
     * @param list<string|int> $params
     */
    private function countRows(string $table, string $where, array $params, bool $optionalTable = false): int
    {
        $db = $this->database();
        $name = $db->getPrefix() . $table;
        if ($optionalTable && !$db->tableExists($name)) {
            return 0;
        }

        return (int) ($db->get_var('SELECT COUNT(*) FROM ' . $name . ' WHERE ' . $where, $params) ?? 0);
    }

    /**
     * Core-Update (0/1) + ausstehende Plugin-Updates, beides nur aus den Caches der Update-Prüfung.
     */
    private function countUpdates(): int
    {
        $count = 0;

        try {
            $db = $this->database();
            $raw = $db->get_var(
                'SELECT cache_value FROM ' . $db->getPrefix() . 'cache WHERE cache_key = ? AND (expires_at IS NULL OR expires_at > NOW())',
                [UpdateService::CORE_UPDATE_CACHE_KEY]
            );
            $core = Json::decodeArray($raw, []);
            $latest = (string) ($core['latest_version'] ?? '');
            $current = defined('CMS_VERSION') ? (string) CMS_VERSION : Version::CURRENT;
            if ($latest !== '' && version_compare($latest, $current, '>')) {
                $count++;
            }
        } catch (\Throwable) {
        }

        try {
            $known = CacheManager::instance()->get(AdminNoticeService::PLUGIN_UPDATES_CACHE_KEY);
            if (is_array($known) && $known !== []) {
                $count += count(AdminNoticeService::pendingPluginUpdates($known, PluginManager::instance()->getAvailablePlugins()));
            }
        } catch (\Throwable) {
        }

        return $count;
    }
}
