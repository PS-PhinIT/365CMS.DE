<?php
/**
 * Daten-Aufbewahrung: löscht wachsende Telemetrie- und Protokolldaten nach festen Fristen.
 *
 * Läuft über den täglichen Cron (`cms_cron_daily`). Gelöscht wird in Blöcken, damit große
 * Tabellen nicht minutenlang gesperrt werden. Die Fristen lassen sich per Filter
 * `cms_data_retention_days` anpassen (Wert 0 = Aufbewahrung ohne Löschung).
 *
 * Aktivitäts- und Audit-Protokoll werden standardmäßig nicht gelöscht (Nachweispflicht). Eine Frist
 * lässt sich unter Sicherheit → Sicherheits-Audit setzen (Optionen `data_retention_activity_log_days`
 * und `data_retention_audit_log_days`); der Filter hat Vorrang.
 *
 * @package CMSv2\Core\Services
 */

declare(strict_types=1);

namespace CMS\Services;

use CMS\Database;
use CMS\Hooks;
use CMS\Logger;
use CMS\Services\Retention\RetentionRule;

if (!defined('ABSPATH')) {
    exit;
}

final class DataRetentionService
{
    /** Standardfristen in Tagen. */
    public const DEFAULT_RETENTION_DAYS = [
        'page_views' => 365,
        'core_web_vitals' => 90,
        'not_found_logs' => RedirectService::NOT_FOUND_LOG_RETENTION_DAYS,
        'log_files' => 30,
        'activity_log' => 0,
        'audit_log' => 0,
    ];

    /** Fristen, die zusätzlich über Optionen im Admin gesetzt werden können. */
    public const OPTION_KEYS = [
        'activity_log' => 'data_retention_activity_log_days',
        'audit_log' => 'data_retention_audit_log_days',
    ];

    /** Mindestfrist für Protokolle, die als Nachweis dienen. */
    public const MIN_LOG_RETENTION_DAYS = 90;

    /** Spalte mit dem Zeitstempel je Tabelle. */
    private const TABLE_DATE_COLUMNS = [
        'page_views' => 'visited_at',
        'core_web_vitals' => 'recorded_at',
        'activity_log' => 'created_at',
        'audit_log' => 'created_at',
    ];

    private const DELETE_BATCH_SIZE = 5000;
    private const MAX_BATCHES_PER_RUN = 40;

    /** Option mit Zeitpunkt und Ergebnis des letzten Laufs (JSON). */
    public const LAST_RUN_OPTION = 'data_retention_last_run';

    /** Option mit dem letzten Lauf je Regel (JSON: Schlüssel => {at, count, error}), auch für Einzelläufe. */
    public const RULE_RUNS_OPTION = 'data_retention_rule_runs';

    /** Admin-Übersicht aller Regeln. */
    public const ADMIN_PATH = '/admin/data-retention';

    /** @var array<string, RetentionRule> zusätzliche Regeln der Funktionsbereiche */
    private static array $registeredRules = [];

    private static ?self $instance = null;

    private readonly Database $db;
    private readonly string $prefix;

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    private function __construct()
    {
        $this->db = Database::instance();
        $this->prefix = $this->db->getPrefix();
    }

    /** @return array<string, int> */
    public function getRetentionDays(): array
    {
        $defaults = self::DEFAULT_RETENTION_DAYS;
        foreach (self::OPTION_KEYS as $key => $optionName) {
            $defaults[$key] = $this->readOptionDays($optionName, $defaults[$key]);
        }

        $configured = Hooks::applyFilters('cms_data_retention_days', $defaults);
        $configured = is_array($configured) ? $configured : [];

        $days = [];
        foreach ($defaults as $key => $default) {
            $value = isset($configured[$key]) && is_numeric($configured[$key]) ? (int) $configured[$key] : $default;
            // 0 = deaktiviert; sonst mindestens 7 Tage (Protokolle: 90), damit Fehlkonfigurationen keine frischen Daten löschen.
            $minimum = isset(self::OPTION_KEYS[$key]) ? self::MIN_LOG_RETENTION_DAYS : 7;
            $days[$key] = $value <= 0 ? 0 : max($minimum, min(3650, $value));
        }

        return $days;
    }

    /**
     * @return array<string, int> Anzahl gelöschter Zeilen/Dateien je Bereich
     */
    public function run(): array
    {
        $days = $this->getRetentionDays();
        $result = [];
        $errors = [];

        foreach (self::TABLE_DATE_COLUMNS as $table => $column) {
            $result[$table] = $days[$table] > 0 ? $this->pruneTable($table, $column, $days[$table]) : 0;
        }

        $result['not_found_logs'] = 0;
        if ($days['not_found_logs'] > 0) {
            try {
                $result['not_found_logs'] = RedirectService::getInstance()->pruneNotFoundLogs($days['not_found_logs']);
            } catch (\Throwable $e) {
                $this->logFailure('not_found_logs', $e);
                $errors['not_found_logs'] = true;
            }
        }

        $result['log_files'] = $days['log_files'] > 0 ? $this->pruneLogFiles($days['log_files']) : 0;

        foreach (self::$registeredRules as $key => $rule) {
            try {
                $result[$key] = $rule->prune();
            } catch (\Throwable $e) {
                $this->logFailure($key, $e);
                $result[$key] = 0;
                $errors[$key] = true;
            }
        }

        if (array_sum($result) > 0) {
            Logger::instance()->withChannel('retention')->info('Datenaufbewahrung: alte Einträge entfernt.', $result);
        }

        $this->storeLastRun($result, array_keys($errors));
        $this->storeRuleRuns($result, array_keys($errors));

        return $result;
    }

    /**
     * Führt eine einzelne Regel sofort aus (Übersicht „Jetzt ausführen“) und speichert ihren letzten Lauf.
     *
     * @return array{count:int,error:bool}|null null = unbekannte Regel
     */
    public function runRule(string $key): ?array
    {
        $rules = $this->getRules();
        if (!isset($rules[$key])) {
            return null;
        }

        $count = 0;
        $error = false;
        try {
            $count = isset(self::DEFAULT_RETENTION_DAYS[$key])
                ? $this->runBuiltInRule($key, (int) ($this->getRetentionDays()[$key] ?? 0))
                : $rules[$key]->prune();
        } catch (\Throwable $e) {
            $this->logFailure($key, $e);
            $error = true;
        }

        if ($count > 0) {
            Logger::instance()->withChannel('retention')->info('Datenaufbewahrung: Regel manuell ausgeführt.', [$key => $count]);
        }

        $this->storeRuleRuns([$key => $count], $error ? [$key] : []);

        return ['count' => $count, 'error' => $error];
    }

    /** Core-Regel (feste Frist dieses Dienstes) statt einer registrierten Regel eines Funktionsbereichs. */
    public function isCoreRule(string $key): bool
    {
        return isset(self::DEFAULT_RETENTION_DAYS[$key]);
    }

    /**
     * Letzter Lauf je Regel. Regeln ohne eigenen Eintrag übernehmen den letzten Gesamtlauf.
     *
     * @return array<string, array{at:string,count:int,error:bool}>
     */
    public function getRuleRuns(): array
    {
        $raw = $this->readOption(self::RULE_RUNS_OPTION);
        $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        $runs = [];
        foreach (is_array($decoded) ? $decoded : [] as $key => $entry) {
            if (!is_string($key) || !is_array($entry) || !is_string($entry['at'] ?? null)) {
                continue;
            }
            $runs[$key] = [
                'at' => $entry['at'],
                'count' => max(0, (int) ($entry['count'] ?? 0)),
                'error' => !empty($entry['error']),
            ];
        }

        $lastRun = $this->getLastRun();
        foreach ($lastRun['results'] ?? [] as $key => $count) {
            if (is_string($key) && !isset($runs[$key])) {
                $runs[$key] = [
                    'at' => $lastRun['at'],
                    'count' => max(0, (int) $count),
                    'error' => in_array($key, $lastRun['errors'], true),
                ];
            }
        }

        return $runs;
    }

    private function runBuiltInRule(string $key, int $retentionDays): int
    {
        if ($retentionDays <= 0) {
            return 0;
        }

        if (isset(self::TABLE_DATE_COLUMNS[$key])) {
            return $this->pruneTable($key, self::TABLE_DATE_COLUMNS[$key], $retentionDays);
        }

        return match ($key) {
            'not_found_logs' => RedirectService::getInstance()->pruneNotFoundLogs($retentionDays),
            'log_files' => $this->pruneLogFiles($retentionDays),
            default => 0,
        };
    }

    /**
     * @param array<string, int> $result
     * @param list<string>       $errors
     */
    private function storeRuleRuns(array $result, array $errors): void
    {
        try {
            $raw = $this->readOption(self::RULE_RUNS_OPTION);
            $runs = is_string($raw) && $raw !== '' ? json_decode($raw, true) : [];
            $runs = is_array($runs) ? $runs : [];
            $at = date('c');
            foreach ($result as $key => $count) {
                $runs[$key] = ['at' => $at, 'count' => (int) $count, 'error' => in_array($key, $errors, true)];
            }

            $this->writeOption(self::RULE_RUNS_OPTION, (string) json_encode($runs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } catch (\Throwable $e) {
            $this->logFailure('rule_runs', $e);
        }
    }

    /**
     * Registriert eine zusätzliche Regel (Formulareingänge, Kommentar-IPs, Papierkorb …).
     * Ein späterer Aufruf mit gleichem Schlüssel ersetzt die Regel.
     */
    public static function registerRule(RetentionRule $rule): void
    {
        if (isset(self::DEFAULT_RETENTION_DAYS[$rule->key])) {
            throw new \InvalidArgumentException('Der Schlüssel ist für eine Core-Regel reserviert: ' . $rule->key);
        }

        self::$registeredRules[$rule->key] = $rule;
    }

    /**
     * Alle Regeln (Core-Fristen und registrierte Regeln) für Übersicht und Trockenlauf.
     *
     * @return array<string, RetentionRule>
     */
    public function getRules(): array
    {
        $days = $this->getRetentionDays();
        $translate = static fn (string $text): string => function_exists('__') ? (string) __($text) : $text;
        $securityAuditUrl = '/admin/security-audit';
        $rules = [];

        $builtIn = [
            'page_views' => ['Seitenaufrufe (Statistik)', ''],
            'core_web_vitals' => ['Core Web Vitals', ''],
            'not_found_logs' => ['404-Monitor', ''],
            'log_files' => ['Tages-Logdateien', ''],
            'activity_log' => ['Aktivitätsprotokoll', $securityAuditUrl],
            'audit_log' => ['Audit-Protokoll', $securityAuditUrl],
        ];

        foreach ($builtIn as $key => [$label, $settingsUrl]) {
            $ruleDays = (int) ($days[$key] ?? 0);
            $rules[$key] = new RetentionRule(
                $key,
                $translate($label),
                isset(self::OPTION_KEYS[$key]) ? '' : $translate('Frist per Filter cms_data_retention_days anpassbar.'),
                static fn (): int => $ruleDays,
                fn (): int => $this->countBuiltInDue($key, $ruleDays),
                static fn (): int => 0,
                $settingsUrl,
            );
        }

        return $rules + self::$registeredRules;
    }

    /**
     * Trockenlauf: Anzahl der Einträge je Regel, die der nächste Lauf entfernen würde.
     *
     * @return array<string, int|null> null = Zählung fehlgeschlagen
     */
    public function dryRun(): array
    {
        $counts = [];
        foreach ($this->getRules() as $key => $rule) {
            try {
                $counts[$key] = $rule->countDue();
            } catch (\Throwable $e) {
                $this->logFailure($key . '.dry_run', $e);
                $counts[$key] = null;
            }
        }

        return $counts;
    }

    /**
     * @return array{at:string,results:array<string,int>,errors:list<string>}|null
     */
    public function getLastRun(): ?array
    {
        $raw = $this->readOption(self::LAST_RUN_OPTION);
        $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        if (!is_array($decoded) || !is_string($decoded['at'] ?? null)) {
            return null;
        }

        return [
            'at' => $decoded['at'],
            'results' => array_map('intval', is_array($decoded['results'] ?? null) ? $decoded['results'] : []),
            'errors' => array_values(array_map('strval', is_array($decoded['errors'] ?? null) ? $decoded['errors'] : [])),
        ];
    }

    /**
     * @param array<string, int> $result
     * @param list<string>       $errors
     */
    private function storeLastRun(array $result, array $errors): void
    {
        try {
            $payload = json_encode([
                'at' => date('c'),
                'results' => $result,
                'errors' => $errors,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $this->writeOption(self::LAST_RUN_OPTION, (string) $payload);
        } catch (\Throwable $e) {
            $this->logFailure('last_run', $e);
        }
    }

    private function countBuiltInDue(string $key, int $retentionDays): int
    {
        if ($retentionDays <= 0) {
            return 0;
        }

        if ($key === 'log_files') {
            return $this->pruneLogFiles($retentionDays, true);
        }

        $table = $key === 'not_found_logs' ? 'not_found_logs' : $key;
        $column = $key === 'not_found_logs' ? 'last_seen_at' : (self::TABLE_DATE_COLUMNS[$key] ?? null);
        if ($column === null) {
            return 0;
        }

        try {
            return (int) $this->db->get_var(
                "SELECT COUNT(*) FROM {$this->prefix}{$table} WHERE {$column} < (NOW() - INTERVAL ? DAY)",
                [$retentionDays]
            );
        } catch (\Throwable) {
            return 0;
        }
    }

    private function readOption(string $optionName): ?string
    {
        try {
            $value = $this->db->get_var(
                "SELECT option_value FROM {$this->prefix}settings WHERE option_name = ? LIMIT 1",
                [$optionName]
            );
        } catch (\Throwable) {
            return null;
        }

        return is_string($value) ? $value : null;
    }

    private function writeOption(string $optionName, string $value): void
    {
        $existing = $this->db->get_var(
            "SELECT COUNT(*) FROM {$this->prefix}settings WHERE option_name = ?",
            [$optionName]
        );
        if ((int) $existing > 0) {
            $this->db->execute(
                "UPDATE {$this->prefix}settings SET option_value = ? WHERE option_name = ?",
                [$value, $optionName]
            );
            return;
        }

        $this->db->execute(
            "INSERT INTO {$this->prefix}settings (option_name, option_value) VALUES (?, ?)",
            [$optionName, $value]
        );
    }

    /**
     * Speichert die im Admin gepflegten Protokollfristen (0 = nie löschen).
     *
     * @param array<string, int> $days
     * @return array<string, int> gespeicherte Werte
     */
    public function saveLogRetentionDays(array $days): array
    {
        $saved = [];
        foreach (self::OPTION_KEYS as $key => $optionName) {
            $value = (int) ($days[$key] ?? 0);
            $value = $value <= 0 ? 0 : max(self::MIN_LOG_RETENTION_DAYS, min(3650, $value));
            $existing = $this->db->get_var(
                "SELECT COUNT(*) FROM {$this->prefix}settings WHERE option_name = ?",
                [$optionName]
            );
            if ((int) $existing > 0) {
                $this->db->execute(
                    "UPDATE {$this->prefix}settings SET option_value = ? WHERE option_name = ?",
                    [(string) $value, $optionName]
                );
            } else {
                $this->db->execute(
                    "INSERT INTO {$this->prefix}settings (option_name, option_value) VALUES (?, ?)",
                    [$optionName, (string) $value]
                );
            }
            $saved[$key] = $value;
        }

        return $saved;
    }

    private function readOptionDays(string $optionName, int $default): int
    {
        try {
            $value = $this->db->get_var(
                "SELECT option_value FROM {$this->prefix}settings WHERE option_name = ? LIMIT 1",
                [$optionName]
            );
        } catch (\Throwable) {
            return $default;
        }

        return is_numeric($value) ? (int) $value : $default;
    }

    private function pruneTable(string $table, string $column, int $retentionDays): int
    {
        $deleted = 0;

        try {
            for ($batch = 0; $batch < self::MAX_BATCHES_PER_RUN; $batch++) {
                $stmt = $this->db->execute(
                    "DELETE FROM {$this->prefix}{$table} WHERE {$column} < (NOW() - INTERVAL ? DAY) LIMIT " . self::DELETE_BATCH_SIZE,
                    [$retentionDays]
                );
                $affected = $stmt->rowCount();
                $deleted += $affected;
                if ($affected < self::DELETE_BATCH_SIZE) {
                    break;
                }
            }
        } catch (\Throwable $e) {
            // Tabelle fehlt (Modul nie aktiv) oder ist gesperrt – beim nächsten Lauf erneut versuchen.
            $this->logFailure($table, $e);
        }

        return $deleted;
    }

    /**
     * Entfernt tagesweise Logdateien (`<kanal>-JJJJ-MM-TT.log`) älter als die Frist.
     * Mit `$countOnly` wird nur gezählt (Trockenlauf).
     */
    private function pruneLogFiles(int $retentionDays, bool $countOnly = false): int
    {
        $logPath = defined('LOG_PATH') ? (string) LOG_PATH : (ABSPATH . 'logs/');
        $logPath = rtrim($logPath, '/\\') . DIRECTORY_SEPARATOR;
        if (!is_dir($logPath)) {
            return 0;
        }

        $files = glob($logPath . '*.log');
        if (!is_array($files)) {
            return 0;
        }

        $cutoff = (new \DateTimeImmutable('today'))->modify('-' . $retentionDays . ' days')->format('Y-m-d');
        $deleted = 0;
        foreach ($files as $file) {
            if (preg_match('/^[a-z0-9_.-]+-(\d{4}-\d{2}-\d{2})\.log$/', basename($file), $matches) !== 1) {
                continue;
            }

            if ($matches[1] < $cutoff && is_file($file) && ($countOnly || @unlink($file))) {
                $deleted++;
            }
        }

        return $deleted;
    }

    private function logFailure(string $area, \Throwable $e): void
    {
        Logger::instance()->withChannel('retention')->warning('Datenaufbewahrung fehlgeschlagen.', [
            'area' => $area,
            'exception' => $e,
        ]);
    }
}
