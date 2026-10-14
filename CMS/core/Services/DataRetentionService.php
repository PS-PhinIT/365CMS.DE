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

        foreach (self::TABLE_DATE_COLUMNS as $table => $column) {
            $result[$table] = $days[$table] > 0 ? $this->pruneTable($table, $column, $days[$table]) : 0;
        }

        $result['not_found_logs'] = 0;
        if ($days['not_found_logs'] > 0) {
            try {
                $result['not_found_logs'] = RedirectService::getInstance()->pruneNotFoundLogs($days['not_found_logs']);
            } catch (\Throwable $e) {
                $this->logFailure('not_found_logs', $e);
            }
        }

        $result['log_files'] = $days['log_files'] > 0 ? $this->pruneLogFiles($days['log_files']) : 0;

        if (array_sum($result) > 0) {
            Logger::instance()->withChannel('retention')->info('Datenaufbewahrung: alte Einträge entfernt.', $result);
        }

        return $result;
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
     */
    private function pruneLogFiles(int $retentionDays): int
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

            if ($matches[1] < $cutoff && is_file($file) && @unlink($file)) {
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
