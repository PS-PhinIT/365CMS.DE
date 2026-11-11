<?php
/**
 * Anonyme Suchbegriff-Statistik der Frontend-Suche.
 *
 * Protokolliert werden nur der normalisierte Begriff, die Sprache, die Trefferzahl und der Zeitpunkt –
 * keine IP, keine Benutzer-ID, keine Session, kein User-Agent. Begriffe, die wie E-Mail-Adressen oder
 * lange Ziffernfolgen (Telefon-, Kunden-, Kontonummern) aussehen, werden nicht gespeichert, ebenso wenig
 * Anfragen von Bots und Prefetches.
 *
 * Die Rohdaten (`search_queries`) werden täglich (`cms_cron_daily`, vor der Datenaufbewahrung) je Tag,
 * Sprache und Begriff in `search_term_daily` verdichtet und danach gelöscht. Wie lange die Tageswerte
 * bleiben, regelt die Aufbewahrungsregel `search_terms` (Einstellung `search_index.stats_retention_days`).
 *
 * @package CMSv2\Core\Services
 */

declare(strict_types=1);

namespace CMS\Services\Search;

use CMS\AuditLogger;
use CMS\Database;
use CMS\Logger;
use CMS\Services\Retention\RetentionRule;
use CMS\Services\SettingsService;

if (!defined('ABSPATH')) {
    exit;
}

final class SearchTermStatsService
{
    public const SETTINGS_GROUP = 'search_index';
    public const RETENTION_KEY = 'search_terms';
    public const DEFAULT_RETENTION_DAYS = 365;
    public const MIN_RETENTION_DAYS = 7;
    public const MAX_RETENTION_DAYS = 3650;
    /** @var list<int> */
    public const PERIODS = [7, 30, 90, 365];
    public const LIST_LIMIT = 20;

    private const MAX_TERM_LENGTH = 190;
    private const PRUNE_BLOCK = 1000;
    private const PRUNE_MAX_BLOCKS = 500;
    /** Wie `ThemeRouter::shouldCountPostView()`: Bots, Vorschau-Dienste und Skripte nicht zählen. */
    private const BOT_PATTERN = '/bot|crawl|spider|slurp|scrape|preview|monitor|uptime|headless|lighthouse|curl|wget|python|java\/|go-http|okhttp|httpclient|facebookexternalhit|embedly/i';

    private static ?self $instance = null;

    private ?bool $available = null;

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    private function __construct()
    {
    }

    /**
     * Beide Tabellen vorhanden? Fehlen sie (Migration nicht gelaufen), ist die Statistik deaktiviert.
     */
    public function isAvailable(): bool
    {
        if ($this->available !== null) {
            return $this->available;
        }

        try {
            $db = Database::instance();
            $this->available = $db->tableExists($db->getPrefix() . 'search_queries')
                && $db->tableExists($db->getPrefix() . 'search_term_daily');
        } catch (\Throwable) {
            $this->available = false;
        }

        if (!$this->available) {
            Logger::instance()->withChannel('search')->warning('Suchbegriff-Statistik deaktiviert: Tabellen search_queries/search_term_daily fehlen (Datenbank-Migration ausführen).');
        }

        return $this->available;
    }

    public function isLoggingEnabled(): bool
    {
        return SettingsService::getInstance()->getBool(self::SETTINGS_GROUP, 'log_terms', true);
    }

    /**
     * Aufbewahrung der Tageswerte in Tagen (0 = unbegrenzt).
     */
    public function retentionDays(): int
    {
        return self::clampRetentionDays(
            SettingsService::getInstance()->getInt(self::SETTINGS_GROUP, 'stats_retention_days', self::DEFAULT_RETENTION_DAYS)
        );
    }

    public static function clampRetentionDays(int $days): int
    {
        return $days <= 0 ? 0 : max(self::MIN_RETENTION_DAYS, min(self::MAX_RETENTION_DAYS, $days));
    }

    public function saveSettings(bool $logTerms, int $retentionDays): bool
    {
        return SettingsService::getInstance()->setMany(self::SETTINGS_GROUP, [
            'log_terms' => $logTerms ? '1' : '0',
            'stats_retention_days' => (string) self::clampRetentionDays($retentionDays),
        ]);
    }

    /**
     * Normalisiert eine Suchanfrage für die Statistik; '' = nicht protokollieren.
     */
    public static function normalizeTerm(string $query): string
    {
        $term = mb_strtolower(trim($query), 'UTF-8');
        $term = (string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $term);
        $term = (string) preg_replace('/\s+/u', ' ', $term);
        // Anführungszeichen (Phrasensuche) und Satzzeichen am Rand gehören nicht zum Begriff.
        $term = (string) preg_replace('/^[\s"\'„“”«»‚‘’.,;:!?¿¡()\[\]{}<>*~|]+|[\s"\'„“”«»‚‘’.,;:!?¿¡()\[\]{}<>*~|]+$/u', '', $term);
        $term = (string) preg_replace('/["„“”«»‚‘’]/u', '', $term);
        $term = trim((string) preg_replace('/\s+/u', ' ', $term));

        if ($term === '' || mb_strlen($term, 'UTF-8') < 2) {
            return '';
        }

        // Mögliche personenbezogene Eingaben nicht speichern.
        if (preg_match('/[^\s@]+@[^\s@]+\.[^\s@]+/u', $term) === 1) {
            return '';
        }
        $digitsJoined = (string) preg_replace('/(?<=\d)[\s\-\/.()+]+(?=\d)/u', '', $term);
        if (preg_match('/\d{6,}/u', $digitsJoined) === 1) {
            return '';
        }

        return mb_substr($term, 0, self::MAX_TERM_LENGTH, 'UTF-8');
    }

    /**
     * Echte Besucheranfrage? (GET, kein Prefetch, kein Bot). Der User-Agent wird nur geprüft, nie gespeichert.
     *
     * @param array<string, mixed> $server
     */
    public static function isCountableRequest(array $server): bool
    {
        $method = strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET'));
        if ($method !== 'GET') {
            return false;
        }

        $purpose = strtolower((string) ($server['HTTP_SEC_PURPOSE'] ?? $server['HTTP_PURPOSE'] ?? $server['HTTP_X_MOZ'] ?? ''));
        if ($purpose !== '' && str_contains($purpose, 'prefetch')) {
            return false;
        }

        $userAgent = (string) ($server['HTTP_USER_AGENT'] ?? '');

        return $userAgent !== '' && preg_match(self::BOT_PATTERN, $userAgent) !== 1;
    }

    public static function normalizeLocale(string $locale): string
    {
        $locale = strtolower(trim($locale));

        return preg_match('/^[a-z]{2,3}(?:[-_][a-z0-9]{2,8})?$/', $locale) === 1 ? substr($locale, 0, 10) : 'de';
    }

    /**
     * Protokolliert eine Frontend-Suche anonym.
     */
    public function record(string $query, int $hits, string $locale): bool
    {
        if (!$this->isLoggingEnabled() || !$this->isAvailable()) {
            return false;
        }

        $term = self::normalizeTerm($query);
        if ($term === '') {
            return false;
        }

        try {
            $id = Database::instance()->insert('search_queries', [
                'term' => $term,
                'locale' => self::normalizeLocale($locale),
                'result_count' => max(0, min($hits, 1000000)),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            Logger::instance()->withChannel('search')->warning('Suchbegriff konnte nicht protokolliert werden.', ['exception' => $e]);

            return false;
        }

        return is_int($id) && $id > 0;
    }

    /**
     * Verdichtet Rohdaten vor `$until` (Standard: heute 00:00) zu Tageswerten und löscht sie.
     *
     * @return int Anzahl verdichteter Rohzeilen
     */
    public function aggregate(?string $until = null): int
    {
        if (!$this->isAvailable()) {
            return 0;
        }

        $until ??= date('Y-m-d 00:00:00');
        $db = Database::instance();
        $p = $db->getPrefix();
        $pdo = $db->getPdo();

        $pdo->beginTransaction();
        try {
            $count = (int) $db->get_var("SELECT COUNT(*) FROM {$p}search_queries WHERE created_at < ?", [$until]);
            if ($count > 0) {
                $db->execute(
                    "INSERT INTO {$p}search_term_daily (day, locale, term, searches, zero_hits, result_sum)
                     SELECT DATE(created_at), locale, term, COUNT(*), SUM(result_count = 0), SUM(result_count)
                       FROM {$p}search_queries
                      WHERE created_at < ?
                      GROUP BY DATE(created_at), locale, term
                     ON DUPLICATE KEY UPDATE
                        searches = searches + VALUES(searches),
                        zero_hits = zero_hits + VALUES(zero_hits),
                        result_sum = result_sum + VALUES(result_sum)",
                    [$until]
                );
                $db->execute("DELETE FROM {$p}search_queries WHERE created_at < ?", [$until]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Logger::instance()->withChannel('search')->error('Suchbegriff-Statistik konnte nicht verdichtet werden.', ['exception' => $e]);

            throw $e;
        }

        return $count;
    }

    /**
     * Auswertung eines Zeitraums (Tageswerte plus noch nicht verdichtete Rohdaten).
     *
     * @return array{days:int, locale:string, from:string, searches:int, terms:int, zero_hit_searches:int, top:list<array<string, mixed>>, zero:list<array<string, mixed>>}
     */
    public function stats(int $days = 30, string $locale = ''): array
    {
        $days = in_array($days, self::PERIODS, true) ? $days : 30;
        $locale = $locale !== '' ? self::normalizeLocale($locale) : '';
        $from = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));
        $result = [
            'days' => $days,
            'locale' => $locale,
            'from' => $from,
            'searches' => 0,
            'terms' => 0,
            'zero_hit_searches' => 0,
            'top' => [],
            'zero' => [],
        ];
        if (!$this->isAvailable()) {
            return $result;
        }

        $db = Database::instance();
        $p = $db->getPrefix();
        $localeSql = $locale !== '' ? ' AND locale = ?' : '';
        $union = "SELECT term, searches, zero_hits, result_sum, day AS last_day
                    FROM {$p}search_term_daily WHERE day >= ?{$localeSql}
                  UNION ALL
                  SELECT term, 1, IF(result_count = 0, 1, 0), result_count, DATE(created_at)
                    FROM {$p}search_queries WHERE created_at >= ?{$localeSql}";
        $params = $locale !== '' ? [$from, $locale, $from . ' 00:00:00', $locale] : [$from, $from . ' 00:00:00'];

        $totals = $db->get_row(
            "SELECT COUNT(DISTINCT term) AS terms, COALESCE(SUM(searches), 0) AS searches, COALESCE(SUM(zero_hits), 0) AS zero_hits
               FROM ({$union}) s",
            $params
        );
        $result['terms'] = (int) ($totals->terms ?? 0);
        $result['searches'] = (int) ($totals->searches ?? 0);
        $result['zero_hit_searches'] = (int) ($totals->zero_hits ?? 0);

        $limit = self::LIST_LIMIT;
        $map = static fn (object $row): array => [
            'term' => (string) $row->term,
            'searches' => (int) $row->searches,
            'zero_hits' => (int) $row->zero_hits,
            'avg_results' => (int) $row->searches > 0 ? round((int) $row->result_sum / (int) $row->searches, 1) : 0.0,
            'last_day' => (string) $row->last_day,
        ];

        $result['top'] = array_map($map, $db->get_results(
            "SELECT term, SUM(searches) AS searches, SUM(zero_hits) AS zero_hits, SUM(result_sum) AS result_sum, MAX(last_day) AS last_day
               FROM ({$union}) s GROUP BY term ORDER BY searches DESC, term ASC LIMIT {$limit}",
            $params
        ));
        $result['zero'] = array_map($map, $db->get_results(
            "SELECT term, SUM(searches) AS searches, SUM(zero_hits) AS zero_hits, SUM(result_sum) AS result_sum, MAX(last_day) AS last_day
               FROM ({$union}) s GROUP BY term HAVING SUM(zero_hits) > 0
              ORDER BY zero_hits DESC, last_day DESC, term ASC LIMIT {$limit}",
            $params
        ));

        return $result;
    }

    /**
     * Löscht die gesamte Statistik (Roh- und Tageswerte).
     */
    public function deleteAll(): int
    {
        if (!$this->isAvailable()) {
            return 0;
        }

        $db = Database::instance();
        $p = $db->getPrefix();
        $deleted = $db->execute("DELETE FROM {$p}search_queries")->rowCount()
            + $db->execute("DELETE FROM {$p}search_term_daily")->rowCount();

        AuditLogger::instance()->log(
            AuditLogger::CAT_SYSTEM,
            'search.stats.delete',
            'Suchbegriff-Statistik gelöscht',
            'search_stats',
            null,
            ['rows' => $deleted],
            'warning'
        );

        return $deleted;
    }

    public function countDue(): int
    {
        $days = $this->retentionDays();
        if ($days <= 0 || !$this->isAvailable()) {
            return 0;
        }

        $db = Database::instance();
        $p = $db->getPrefix();
        [$day, $timestamp] = $this->cutoff($days);

        return (int) $db->get_var("SELECT COUNT(*) FROM {$p}search_term_daily WHERE day < ?", [$day])
            + (int) $db->get_var("SELECT COUNT(*) FROM {$p}search_queries WHERE created_at < ?", [$timestamp]);
    }

    /**
     * Entfernt Werte außerhalb der Aufbewahrungsfrist (blockweise).
     */
    public function prune(): int
    {
        $days = $this->retentionDays();
        if ($days <= 0 || !$this->isAvailable()) {
            return 0;
        }

        $db = Database::instance();
        $p = $db->getPrefix();
        [$day, $timestamp] = $this->cutoff($days);
        $block = self::PRUNE_BLOCK;
        $deleted = 0;
        foreach (["DELETE FROM {$p}search_term_daily WHERE day < ? LIMIT {$block}" => $day,
                  "DELETE FROM {$p}search_queries WHERE created_at < ? LIMIT {$block}" => $timestamp] as $sql => $boundary) {
            for ($i = 0; $i < self::PRUNE_MAX_BLOCKS; $i++) {
                $rows = $db->execute($sql, [$boundary])->rowCount();
                $deleted += $rows;
                if ($rows < $block) {
                    break;
                }
            }
        }

        return $deleted;
    }

    public function retentionRule(): RetentionRule
    {
        $translate = static function (string $key, string $fallback): string {
            $text = function_exists('__') ? (string) __($key) : '';

            return $text !== '' && $text !== $key ? $text : $fallback;
        };

        return new RetentionRule(
            self::RETENTION_KEY,
            $translate('search_index.retention_label', 'Suchbegriff-Statistik'),
            $translate('search_index.retention_description', 'Anonyme Tageswerte der Frontend-Suche (Begriff, Anzahl, Treffer).'),
            fn (): int => $this->retentionDays(),
            fn (): int => $this->countDue(),
            fn (): int => $this->prune(),
            '/admin/search-index?tab=settings'
        );
    }

    /**
     * @return array{0:string, 1:string} Stichtag (Datum) und Zeitstempel
     */
    private function cutoff(int $days): array
    {
        $time = strtotime('-' . $days . ' days');

        return [date('Y-m-d', $time), date('Y-m-d 00:00:00', $time)];
    }
}
