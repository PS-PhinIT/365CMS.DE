<?php
/**
 * Zählung und Statistik der Downloads – ohne Personendaten.
 *
 * - Rohzeilen (`downloads_events`) enthalten nur Download, Version, Quellseite (Post-/Seiten-ID aus dem
 *   signierten Block-Parameter) und einen HMAC für die Deduplizierung (wird nach 24 Stunden geleert).
 * - Der tägliche Cron fasst abgeschlossene Tage in `downloads_stats_daily` zusammen; die Aufbewahrungsregel
 *   `downloads.events` löscht aggregierte Rohzeilen nach der eingestellten Frist.
 * - Auswertungen kombinieren Tageswerte mit noch nicht aggregierten Rohzeilen (aktueller Tag).
 *
 * @package CMSv2\Core\Services
 */

declare(strict_types=1);

namespace CMS\Services\Downloads;

use CMS\Logger;

if (!defined('ABSPATH')) {
    exit;
}

final class DownloadStatsService
{
    public const PERIODS = [30, 90, 365];
    private const DEDUPE_HASH_TTL_HOURS = 24;
    private const PRUNE_BATCH = 5000;
    /** Faktor auf `rate_limit_max` für Folgeanfragen (Range-Teilstücke, HEAD). */
    private const RATE_LIMIT_FOLLOW_UP_FACTOR = 10;

    private static ?self $instance = null;

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    private function __construct()
    {
    }

    public static function normalizePeriod(mixed $period): int
    {
        $period = (int) $period;

        return in_array($period, self::PERIODS, true) ? $period : 30;
    }

    // ── Zählen ───────────────────────────────────────────────────────────────

    /**
     * Abruf zählen (nach Bot-, Personal- und Dublettenfilter).
     *
     * @param array{type:string,id:int}|null $source
     * @return bool true = gezählt
     */
    public function recordHit(int $downloadId, int $versionId, ?array $source, string $visitorKey, string $userAgent, bool $isStaff): bool
    {
        $repo = DownloadRepository::getInstance();
        if ($downloadId <= 0 || !$repo->isAvailable()) {
            return false;
        }
        $settings = DownloadSettings::getInstance();
        if ($settings->bool('bot_filter') && DownloadSecurity::isBot($userAgent)) {
            return false;
        }
        if ($isStaff && !$settings->bool('count_staff')) {
            return false;
        }

        $db = $repo->db();
        $now = time();
        $window = $settings->int('dedupe_window_minutes');
        $dedupeHash = $window > 0 ? DownloadSecurity::hmac('dedupe|' . $visitorKey . '|' . $versionId) : null;

        try {
            if ($dedupeHash !== null) {
                $seen = $repo->fetchOne(
                    'SELECT id FROM ' . $repo->table('downloads_events') . ' WHERE dedupe_hash = ? AND created_at >= ? LIMIT 1',
                    [$dedupeHash, date('Y-m-d H:i:s', $now - $window * 60)]
                );
                if ($seen !== null) {
                    return false;
                }
            }

            $db->execute(
                'INSERT INTO ' . $repo->table('downloads_events') . ' (download_id, version_id, source_type, source_id, dedupe_hash, aggregated, created_at) VALUES (?, ?, ?, ?, ?, 0, ?)',
                [$downloadId, max(0, $versionId), (string) ($source['type'] ?? ''), (int) ($source['id'] ?? 0), $dedupeHash, date('Y-m-d H:i:s', $now)]
            );
            $db->execute('UPDATE ' . $repo->table('downloads_items') . ' SET download_count = download_count + 1 WHERE id = ?', [$downloadId]);
            if ($versionId > 0) {
                $db->execute('UPDATE ' . $repo->table('downloads_versions') . ' SET download_count = download_count + 1 WHERE id = ?', [$versionId]);
            }
        } catch (\Throwable $e) {
            Logger::instance()->withChannel('downloads')->warning('Download-Abruf konnte nicht gezählt werden.', ['exception' => $e::class]);

            return false;
        }

        return true;
    }

    /**
     * Grenzwert je Besucher (HMAC über die IP, keine Klartext-IP).
     *
     * Folgeanfragen desselben Abrufs (Range-Teilstücke, HEAD) laufen über einen eigenen, großzügigeren Zähler:
     * segmentierte Downloads sollen nicht am Abruf-Limit scheitern, bleiben aber begrenzt.
     *
     * @return int 0 = erlaubt, sonst Sekunden bis zum nächsten Fenster
     */
    public function rateLimitRetryAfter(string $ip, bool $followUp = false): int
    {
        $settings = DownloadSettings::getInstance();
        $repo = DownloadRepository::getInstance();
        if (!$settings->bool('rate_limit_enabled') || !$repo->isAvailable()) {
            return 0;
        }
        $max = $settings->int('rate_limit_max') * ($followUp ? self::RATE_LIMIT_FOLLOW_UP_FACTOR : 1);
        $window = $settings->int('rate_limit_window');
        $key = DownloadSecurity::hmac(($followUp ? 'rate-part|' : 'rate|') . $ip);
        $now = time();
        $cutoff = $now - $window;
        $table = $repo->table('downloads_rate_limits');

        try {
            $repo->db()->execute(
                "INSERT INTO {$table} (key_hash, request_count, window_started_at) VALUES (?, 1, ?)
                 ON DUPLICATE KEY UPDATE
                    request_count = IF(window_started_at <= ?, 1, request_count + 1),
                    window_started_at = IF(window_started_at <= ?, VALUES(window_started_at), window_started_at)",
                [$key, $now, $cutoff, $cutoff]
            );
            $row = $repo->fetchOne("SELECT request_count, window_started_at FROM {$table} WHERE key_hash = ?", [$key]);
        } catch (\Throwable $e) {
            Logger::instance()->withChannel('downloads')->warning('Download-Grenzwert konnte nicht geprüft werden.', ['exception' => $e::class]);

            return 0;
        }
        if ($row === null || (int) $row['request_count'] <= $max) {
            return 0;
        }

        return max(1, (int) $row['window_started_at'] + $window - $now);
    }

    // ── Cron und Aufbewahrung ────────────────────────────────────────────────

    /**
     * Abgeschlossene Tage aus den Rohzeilen in Tageswerte übernehmen, Dubletten-HMACs nach 24 h leeren,
     * abgelaufene Grenzwert-Fenster entfernen.
     *
     * @return int Anzahl aggregierter Rohzeilen
     */
    public function aggregate(?int $now = null): int
    {
        $repo = DownloadRepository::getInstance();
        if (!$repo->isAvailable()) {
            return 0;
        }
        $now ??= time();
        $db = $repo->db();
        $events = $repo->table('downloads_events');
        $daily = $repo->table('downloads_stats_daily');
        $cutoff = date('Y-m-d 00:00:00', $now);
        $aggregated = 0;

        $pdo = $db->getPdo();
        try {
            $maxRow = $repo->fetchOne("SELECT MAX(id) AS max_id, COUNT(*) AS total FROM {$events} WHERE aggregated = 0 AND created_at < ?", [$cutoff]);
            $maxId = (int) ($maxRow['max_id'] ?? 0);
            if ($maxId > 0) {
                $pdo->beginTransaction();
                $db->execute(
                    "INSERT INTO {$daily} (stat_date, download_id, version_id, source_type, source_id, hits)
                     SELECT DATE(created_at), download_id, version_id, source_type, source_id, COUNT(*)
                     FROM {$events} WHERE aggregated = 0 AND created_at < ? AND id <= ?
                     GROUP BY DATE(created_at), download_id, version_id, source_type, source_id
                     ON DUPLICATE KEY UPDATE hits = hits + VALUES(hits)",
                    [$cutoff, $maxId]
                );
                $db->execute("UPDATE {$events} SET aggregated = 1 WHERE aggregated = 0 AND created_at < ? AND id <= ?", [$cutoff, $maxId]);
                $pdo->commit();
                $aggregated = (int) ($maxRow['total'] ?? 0);
            }

            $db->execute(
                "UPDATE {$events} SET dedupe_hash = NULL WHERE dedupe_hash IS NOT NULL AND created_at < ?",
                [date('Y-m-d H:i:s', $now - self::DEDUPE_HASH_TTL_HOURS * 3600)]
            );
            $db->execute(
                'DELETE FROM ' . $repo->table('downloads_rate_limits') . ' WHERE window_started_at < ?',
                [$now - max(3600, DownloadSettings::getInstance()->int('rate_limit_window'))]
            );
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Logger::instance()->withChannel('downloads')->error('Download-Statistik konnte nicht aggregiert werden.', ['exception' => $e::class, 'message' => $e->getMessage()]);

            return 0;
        }

        return $aggregated;
    }

    public function retentionDays(): int
    {
        return DownloadSettings::getInstance()->int('stats_retention_days');
    }

    /**
     * Aggregierte Rohzeilen älter als die Frist (Trockenlauf).
     */
    public function countPrunable(?int $now = null): int
    {
        $repo = DownloadRepository::getInstance();
        if (!$repo->isAvailable() || $this->retentionDays() <= 0) {
            return 0;
        }
        $row = $repo->fetchOne(
            'SELECT COUNT(*) AS total FROM ' . $repo->table('downloads_events') . ' WHERE aggregated = 1 AND created_at < ?',
            [$this->retentionCutoff($now)]
        );

        return (int) ($row['total'] ?? 0);
    }

    /**
     * Aggregierte Rohzeilen älter als die Frist löschen (blockweise). Nicht aggregierte Zeilen bleiben stehen,
     * damit keine Abrufe aus der Statistik verschwinden.
     */
    public function prune(?int $now = null): int
    {
        $repo = DownloadRepository::getInstance();
        if (!$repo->isAvailable() || $this->retentionDays() <= 0) {
            return 0;
        }
        $this->aggregate($now);
        $cutoff = $this->retentionCutoff($now);
        $deleted = 0;
        for ($i = 0; $i < 200; $i++) {
            $stmt = $repo->db()->execute(
                'DELETE FROM ' . $repo->table('downloads_events') . ' WHERE aggregated = 1 AND created_at < ? ORDER BY id LIMIT ' . self::PRUNE_BATCH,
                [$cutoff]
            );
            $count = $stmt instanceof \PDOStatement ? $stmt->rowCount() : 0;
            $deleted += $count;
            if ($count < self::PRUNE_BATCH) {
                break;
            }
        }

        return $deleted;
    }

    private function retentionCutoff(?int $now): string
    {
        return date('Y-m-d H:i:s', ($now ?? time()) - $this->retentionDays() * 86400);
    }

    // ── Auswertung ───────────────────────────────────────────────────────────

    /**
     * Abrufe je Tag (lückenlos, ältester Tag zuerst).
     *
     * @return list<array{date:string,hits:int}>
     */
    public function dailySeries(int $days, ?int $downloadId = null, ?int $now = null): array
    {
        $days = self::normalizePeriod($days);
        $now ??= time();
        $start = date('Y-m-d', $now - ($days - 1) * 86400);
        $map = [];
        foreach ($this->periodRows($start, $downloadId, ['date']) as $row) {
            $map[$row['date']] = ($map[$row['date']] ?? 0) + $row['hits'];
        }
        $series = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = date('Y-m-d', $now - $i * 86400);
            $series[] = ['date' => $date, 'hits' => (int) ($map[$date] ?? 0)];
        }

        return $series;
    }

    /**
     * Abrufe je Version eines Downloads im Zeitraum (plus Gesamtzähler der Version).
     *
     * @return list<array{version_id:int,version:string,is_current:bool,period_hits:int,total_hits:int}>
     */
    public function versionBreakdown(int $downloadId, int $days, ?int $now = null): array
    {
        $repo = DownloadRepository::getInstance();
        if (!$repo->isAvailable()) {
            return [];
        }
        $start = date('Y-m-d', ($now ?? time()) - (self::normalizePeriod($days) - 1) * 86400);
        $period = [];
        foreach ($this->periodRows($start, $downloadId, ['version']) as $row) {
            $period[$row['version_id']] = ($period[$row['version_id']] ?? 0) + $row['hits'];
        }
        $result = [];
        foreach ($repo->versionsFor($downloadId) as $version) {
            $id = (int) $version['id'];
            $result[] = [
                'version_id' => $id,
                'version' => (string) $version['version'],
                'is_current' => (int) ($version['current_flag'] ?? 0) === 1,
                'period_hits' => (int) ($period[$id] ?? 0),
                'total_hits' => (int) $version['download_count'],
            ];
        }

        return $result;
    }

    /**
     * @return list<array{download_id:int,title:string,slug:string,hits:int,total:int}>
     */
    public function topDownloads(int $days, int $limit = 10, ?int $now = null): array
    {
        $repo = DownloadRepository::getInstance();
        if (!$repo->isAvailable()) {
            return [];
        }
        $start = date('Y-m-d', ($now ?? time()) - (self::normalizePeriod($days) - 1) * 86400);
        $hits = [];
        foreach ($this->periodRows($start, null, ['download']) as $row) {
            $hits[$row['download_id']] = ($hits[$row['download_id']] ?? 0) + $row['hits'];
        }
        arsort($hits);
        $hits = array_slice($hits, 0, max(1, $limit), true);
        $items = $repo->findItemsByIds(array_keys($hits));
        $result = [];
        foreach ($hits as $id => $count) {
            $item = $items[$id] ?? null;
            $result[] = [
                'download_id' => (int) $id,
                'title' => $item !== null ? (string) $item['title'] : '#' . $id,
                'slug' => $item !== null ? (string) $item['slug'] : '',
                'hits' => (int) $count,
                'total' => $item !== null ? (int) $item['download_count'] : 0,
            ];
        }

        return $result;
    }

    /**
     * @return list<array{source_type:string,source_id:int,title:string,url:string,hits:int}>
     */
    public function topSources(int $days, ?int $downloadId = null, int $limit = 10, ?int $now = null): array
    {
        $repo = DownloadRepository::getInstance();
        if (!$repo->isAvailable()) {
            return [];
        }
        $start = date('Y-m-d', ($now ?? time()) - (self::normalizePeriod($days) - 1) * 86400);
        $hits = [];
        foreach ($this->periodRows($start, $downloadId, ['source']) as $row) {
            if ($row['source_id'] <= 0 || !in_array($row['source_type'], DownloadSecurity::SOURCE_TYPES, true)) {
                continue;
            }
            $key = $row['source_type'] . ':' . $row['source_id'];
            $hits[$key] = ($hits[$key] ?? 0) + $row['hits'];
        }
        arsort($hits);
        $hits = array_slice($hits, 0, max(1, $limit), true);

        $titles = $this->sourceTitles(array_keys($hits));
        $result = [];
        foreach ($hits as $key => $count) {
            [$type, $id] = explode(':', (string) $key, 2);
            $result[] = [
                'source_type' => $type,
                'source_id' => (int) $id,
                'title' => $titles[$key] ?? sprintf($type === 'page' ? __('downloads.stats.page_fallback') : __('downloads.stats.post_fallback'), $id),
                'url' => ($type === 'page' ? '/admin/pages?action=edit&id=' : '/admin/posts?action=edit&id=') . (int) $id,
                'hits' => (int) $count,
            ];
        }

        return $result;
    }

    /**
     * @return array{period_hits:int,total_hits:int,downloads:int,published:int}
     */
    public function summary(int $days, ?int $downloadId = null, ?int $now = null): array
    {
        $repo = DownloadRepository::getInstance();
        if (!$repo->isAvailable()) {
            return ['period_hits' => 0, 'total_hits' => 0, 'downloads' => 0, 'published' => 0];
        }
        $series = $this->dailySeries($days, $downloadId, $now);
        $periodHits = array_sum(array_column($series, 'hits'));
        if ($downloadId !== null) {
            $item = $repo->findItem($downloadId);

            return [
                'period_hits' => $periodHits,
                'total_hits' => (int) ($item['download_count'] ?? 0),
                'downloads' => $item !== null ? 1 : 0,
                'published' => $item !== null && (string) $item['status'] === 'published' ? 1 : 0,
            ];
        }
        $row = $repo->fetchOne(
            "SELECT COALESCE(SUM(download_count), 0) AS total, COUNT(*) AS downloads, SUM(status = 'published') AS published
             FROM " . $repo->table('downloads_items') . " WHERE status <> 'trash'"
        );

        return [
            'period_hits' => $periodHits,
            'total_hits' => (int) ($row['total'] ?? 0),
            'downloads' => (int) ($row['downloads'] ?? 0),
            'published' => (int) ($row['published'] ?? 0),
        ];
    }

    /**
     * CSV-Zeilen (Kopfzeile + Werte) für den Zeitraum: Tag, Download, Version, Quelle, Abrufe.
     *
     * @return list<list<string|int>>
     */
    public function csvRows(int $days, ?int $downloadId = null, ?int $now = null): array
    {
        $repo = DownloadRepository::getInstance();
        $rows = [['date', 'download_id', 'download', 'slug', 'version', 'source_type', 'source_id', 'source', 'hits']];
        if (!$repo->isAvailable()) {
            return $rows;
        }
        $start = date('Y-m-d', ($now ?? time()) - (self::normalizePeriod($days) - 1) * 86400);
        $grouped = [];
        foreach ($this->periodRows($start, $downloadId, ['date', 'download', 'version', 'source']) as $row) {
            $key = implode('|', [$row['date'], $row['download_id'], $row['version_id'], $row['source_type'], $row['source_id']]);
            if (!isset($grouped[$key])) {
                $grouped[$key] = $row;
            } else {
                $grouped[$key]['hits'] += $row['hits'];
            }
        }
        ksort($grouped);

        $items = $repo->findItemsByIds(array_unique(array_column($grouped, 'download_id')));
        $versionIds = DownloadRepository::intList(array_unique(array_column($grouped, 'version_id')));
        $versions = [];
        if ($versionIds !== []) {
            $placeholders = implode(',', array_fill(0, count($versionIds), '?'));
            foreach ($repo->fetchAll('SELECT id, version FROM ' . $repo->table('downloads_versions') . " WHERE id IN ({$placeholders})", $versionIds) as $version) {
                $versions[(int) $version['id']] = (string) $version['version'];
            }
        }
        $sourceKeys = [];
        foreach ($grouped as $row) {
            if ($row['source_id'] > 0) {
                $sourceKeys[] = $row['source_type'] . ':' . $row['source_id'];
            }
        }
        $titles = $this->sourceTitles(array_values(array_unique($sourceKeys)));

        foreach ($grouped as $row) {
            $item = $items[$row['download_id']] ?? null;
            $rows[] = [
                $row['date'],
                $row['download_id'],
                self::csvCell($item !== null ? (string) $item['title'] : ''),
                $item !== null ? (string) $item['slug'] : '',
                self::csvCell($versions[$row['version_id']] ?? ''),
                $row['source_type'],
                $row['source_id'],
                self::csvCell($titles[$row['source_type'] . ':' . $row['source_id']] ?? ''),
                $row['hits'],
            ];
        }

        return $rows;
    }

    /**
     * Schutz vor Formel-Injektion in Tabellenkalkulationen.
     */
    public static function csvCell(string $value): string
    {
        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'" . $value : $value;
    }

    /**
     * Tageswerte und nicht aggregierte Rohzeilen ab einem Datum, gruppiert nach den gewünschten Dimensionen.
     *
     * @param list<string> $dimensions date|download|version|source
     * @return list<array{date:string,download_id:int,version_id:int,source_type:string,source_id:int,hits:int}>
     */
    private function periodRows(string $startDate, ?int $downloadId, array $dimensions): array
    {
        $repo = DownloadRepository::getInstance();
        if (!$repo->isAvailable()) {
            return [];
        }
        $dailyCols = [];
        $eventCols = [];
        $map = [
            'date' => ['stat_date', 'DATE(created_at)', 'date'],
            'download' => ['download_id', 'download_id', 'download_id'],
            'version' => ['version_id', 'version_id', 'version_id'],
            'source' => ['source_type, source_id', 'source_type, source_id', null],
        ];
        foreach ($dimensions as $dimension) {
            if (!isset($map[$dimension])) {
                continue;
            }
            [$daily, $event] = $map[$dimension];
            $dailyCols[] = $daily;
            $eventCols[] = $event;
        }
        $dailyGroup = $dailyCols !== [] ? implode(', ', $dailyCols) : '';
        $eventGroup = $eventCols !== [] ? implode(', ', $eventCols) : '';

        $selectDaily = $this->selectList($dimensions, false);
        $selectEvent = $this->selectList($dimensions, true);
        $filterDaily = $downloadId !== null ? ' AND download_id = ?' : '';
        $params = [$startDate];
        if ($downloadId !== null) {
            $params[] = $downloadId;
        }

        $result = [];
        try {
            $dailyRows = $repo->fetchAll(
                "SELECT {$selectDaily}, SUM(hits) AS hits FROM " . $repo->table('downloads_stats_daily') . " WHERE stat_date >= ?{$filterDaily}"
                . ($dailyGroup !== '' ? " GROUP BY {$dailyGroup}" : ''),
                $params
            );
            $eventRows = $repo->fetchAll(
                "SELECT {$selectEvent}, COUNT(*) AS hits FROM " . $repo->table('downloads_events') . " WHERE aggregated = 0 AND created_at >= ?{$filterDaily}"
                . ($eventGroup !== '' ? " GROUP BY {$eventGroup}" : ''),
                [$startDate . ' 00:00:00', ...array_slice($params, 1)]
            );
        } catch (\Throwable $e) {
            Logger::instance()->withChannel('downloads')->warning('Download-Statistik konnte nicht gelesen werden.', ['exception' => $e::class]);

            return [];
        }
        foreach (array_merge($dailyRows, $eventRows) as $row) {
            $result[] = [
                'date' => (string) ($row['d'] ?? ''),
                'download_id' => (int) ($row['download_id'] ?? 0),
                'version_id' => (int) ($row['version_id'] ?? 0),
                'source_type' => (string) ($row['source_type'] ?? ''),
                'source_id' => (int) ($row['source_id'] ?? 0),
                'hits' => (int) ($row['hits'] ?? 0),
            ];
        }

        return $result;
    }

    /**
     * @param list<string> $dimensions
     */
    private function selectList(array $dimensions, bool $events): string
    {
        $columns = [];
        foreach ($dimensions as $dimension) {
            $columns[] = match ($dimension) {
                'date' => $events ? 'DATE(created_at) AS d' : 'stat_date AS d',
                'download' => 'download_id',
                'version' => 'version_id',
                'source' => 'source_type, source_id',
                default => null,
            };
        }
        $columns = array_values(array_filter($columns));

        return $columns !== [] ? implode(', ', $columns) : '1 AS one';
    }

    /**
     * @param list<string> $keys type:id
     * @return array<string,string>
     */
    private function sourceTitles(array $keys): array
    {
        $byType = [];
        foreach ($keys as $key) {
            [$type, $id] = array_pad(explode(':', (string) $key, 2), 2, '0');
            if (in_array($type, DownloadSecurity::SOURCE_TYPES, true) && (int) $id > 0) {
                $byType[$type][] = (int) $id;
            }
        }
        $repo = DownloadRepository::getInstance();
        $prefix = $repo->db()->getPrefix();
        $titles = [];
        foreach ($byType as $type => $ids) {
            $table = $prefix . ($type === 'page' ? 'pages' : 'posts');
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            try {
                foreach ($repo->fetchAll("SELECT id, title FROM {$table} WHERE id IN ({$placeholders})", $ids) as $row) {
                    $titles[$type . ':' . (int) $row['id']] = (string) $row['title'];
                }
            } catch (\Throwable) {
            }
        }

        return $titles;
    }
}
