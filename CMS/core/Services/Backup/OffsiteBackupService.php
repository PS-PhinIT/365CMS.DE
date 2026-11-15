<?php
/**
 * Offsite-Kopien der Backups auf ein S3-kompatibles Ziel (4.1.0, Bereich 4a).
 *
 * - Nur **verschlüsselte** Backups (Manifest mit `encryption`, jede Datei beginnt mit `CMSENC2\0`) werden kopiert;
 *   Klartext-Backups lehnt der Dienst mit „Verschlüsselung aktivieren“ ab.
 * - Objektschlüssel: `{prefix}{backupName}/{datei}`; `manifest.json` zuletzt, danach HEAD-Prüfung.
 * - Status je Backup in `backup_offsite_uploads` (pending | uploaded | failed | deleted, Versuche, letzter Fehler).
 * - Aufbewahrung offsite: „letzte N“ und/oder „nicht älter als D Tage“; das zuletzt erfolgreich hochgeladene Backup
 *   wird nie gelöscht.
 * - Auslöser: Hook `cms_backup_created` (nach jedem erfolgreichen Backup) und Cron `daily` (Nachholen, höchstens
 *   3 Versuche). Ereignisse: `cms_backup_offsite_uploaded`, `cms_backup_offsite_failed`.
 *
 * Einstellungen in der Gruppe `backup_offsite` (geheimer Schlüssel verschlüsselt über `SettingsService`).
 * Datenbank, Backup-Verzeichnis, Einstellungen, S3-Client und Uhr sind für Tests ersetzbar.
 *
 * @package CMSv2\Core\Services\Backup
 */

declare(strict_types=1);

namespace CMS\Services\Backup;

use CMS\Database;
use CMS\Hooks;
use CMS\Logger;

if (!defined('ABSPATH')) {
    exit;
}

final class OffsiteBackupService
{
    public const SETTINGS_GROUP = 'backup_offsite';
    public const TARGET = 's3';
    public const MAX_ATTEMPTS = 3;
    private const NAME_PATTERN = '/^[a-z0-9][a-z0-9._-]{2,120}$/i';
    private const FILE_MAGIC = "CMSENC2\0";
    private const SETTING_KEYS = [
        'enabled', 'endpoint', 'region', 'bucket', 'prefix', 'access_key', 'path_style', 'part_size_mb', 'storage_class',
        'allow_private_host', 'insecure_http', 'keep_last', 'keep_days',
    ];

    private static ?self $instance = null;

    private Database $db;
    private string $prefix;
    private string $backupRoot;
    /** @var \Closure(): array<string, mixed> */
    private \Closure $settingsLoader;
    /** @var \Closure(S3Config): S3Client */
    private \Closure $clientFactory;
    /** @var \Closure(): int */
    private \Closure $clock;

    public function __construct(
        ?Database $db = null,
        ?string $backupRoot = null,
        ?\Closure $settingsLoader = null,
        ?\Closure $clientFactory = null,
        ?\Closure $clock = null
    ) {
        $this->db = $db ?? Database::instance();
        $this->prefix = $this->db->getPrefix();
        $this->backupRoot = rtrim($backupRoot ?? \CMS\Services\BackupService::getInstance()->getBackupRoot(), '/\\') . '/';
        $this->settingsLoader = $settingsLoader ?? \Closure::fromCallable([self::class, 'loadStoredSettings']);
        $this->clientFactory = $clientFactory ?? static fn (S3Config $config): S3Client => new S3Client($config);
        $this->clock = $clock ?? static fn (): int => time();
    }

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    // ── Einstellungen ───────────────────────────────────────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    public function settings(): array
    {
        $values = ($this->settingsLoader)();

        return [
            'enabled' => !empty($values['enabled']),
            'keep_last' => max(0, min(365, (int) ($values['keep_last'] ?? 7))),
            'keep_days' => max(0, min(3650, (int) ($values['keep_days'] ?? 30))),
        ] + $values;
    }

    public function config(): S3Config
    {
        return S3Config::fromArray($this->settings());
    }

    public function isConfigured(): bool
    {
        return $this->config()->validate() === [];
    }

    /**
     * Speichert die Einstellungen; ein leeres Feld „geheimer Schlüssel“ behält den gespeicherten Wert.
     *
     * @param array<string, mixed> $input
     * @return array{success: bool, error?: string, message?: string, details?: list<string>}
     */
    public static function saveSettings(array $input): array
    {
        $current = self::loadStoredSettings();
        $text = static fn (string $key): string => trim(is_scalar($input[$key] ?? null) ? (string) $input[$key] : '');
        $secret = $text('secret_key') !== '' ? $text('secret_key') : (string) ($current['secret_key'] ?? '');
        $values = [
            'enabled' => !empty($input['enabled']) ? '1' : '0',
            'endpoint' => $text('endpoint'),
            'region' => $text('region'),
            'bucket' => $text('bucket'),
            'prefix' => $text('prefix'),
            'access_key' => $text('access_key'),
            'path_style' => !empty($input['path_style']) ? '1' : '0',
            'part_size_mb' => (string) max(S3Config::MIN_PART_MB, min(S3Config::MAX_PART_MB, (int) ($input['part_size_mb'] ?? 16) ?: 16)),
            'storage_class' => strtoupper($text('storage_class')),
            'allow_private_host' => !empty($input['allow_private_host']) ? '1' : '0',
            'insecure_http' => !empty($input['insecure_http']) ? '1' : '0',
            'keep_last' => (string) max(0, min(365, (int) ($input['keep_last'] ?? 7))),
            'keep_days' => (string) max(0, min(3650, (int) ($input['keep_days'] ?? 30))),
        ];
        if ($text('prefix') !== '' && S3Config::normalizePrefix($text('prefix')) === '') {
            return ['success' => false, 'error' => 'Präfix: nur Buchstaben, Ziffern, Punkt, Unterstrich, Bindestrich und „/“, ohne „..“.'];
        }
        $config = S3Config::fromArray($values + ['secret_key' => $secret]);
        $errors = $values['enabled'] === '1' ? $config->validate() : [];
        if ($errors !== []) {
            return ['success' => false, 'error' => 'Offsite-Ziel unvollständig.', 'details' => $errors];
        }
        $settings = \CMS\Services\SettingsService::getInstance();
        foreach ($values as $key => $value) {
            $settings->set(self::SETTINGS_GROUP, $key, $value);
        }
        if ($text('secret_key') !== '') {
            $settings->set(self::SETTINGS_GROUP, 'secret_key', $secret, true);
        }

        return ['success' => true, 'message' => 'Offsite-Einstellungen gespeichert.'];
    }

    /**
     * @return array<string, mixed>
     */
    private static function loadStoredSettings(): array
    {
        $settings = \CMS\Services\SettingsService::getInstance();
        $values = [];
        foreach (array_merge(self::SETTING_KEYS, ['secret_key']) as $key) {
            $values[$key] = $settings->get(self::SETTINGS_GROUP, $key, '');
        }

        return $values;
    }

    // ── Hochladen ───────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Dateien eines lokalen Backups (validierter Name, innerhalb des Backup-Verzeichnisses), manifest.json zuletzt.
     *
     * @return array{files: list<string>, error?: string}
     */
    public function backupFiles(string $backupName): array
    {
        if (preg_match(self::NAME_PATTERN, $backupName) !== 1 || str_contains($backupName, '..')) {
            return ['files' => [], 'error' => 'Ungültiger Backup-Name.'];
        }
        $dir = $this->backupRoot . $backupName . '/';
        $realRoot = realpath($this->backupRoot);
        $realDir = realpath($dir);
        if ($realRoot === false || $realDir === false || !str_starts_with($realDir . '/', rtrim($realRoot, '/') . '/') || !is_file($dir . 'manifest.json')) {
            return ['files' => [], 'error' => 'Backup nicht gefunden.'];
        }
        $manifest = json_decode((string) file_get_contents($dir . 'manifest.json'), true);
        if (!is_array($manifest) || empty($manifest['encryption'])) {
            return ['files' => [], 'error' => 'Nur verschlüsselte Backups werden offsite kopiert – bitte unter Backup-Einstellungen die Verschlüsselung aktivieren.'];
        }
        $files = [];
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || $entry === 'manifest.json' || !is_file($dir . $entry) || is_link($dir . $entry)) {
                continue;
            }
            $handle = @fopen($dir . $entry, 'rb');
            $magic = $handle !== false ? (string) fread($handle, strlen(self::FILE_MAGIC)) : '';
            if ($handle !== false) {
                fclose($handle);
            }
            if ($magic !== self::FILE_MAGIC) {
                return ['files' => [], 'error' => sprintf('Datei „%s“ ist nicht verschlüsselt – bitte die Verschlüsselung aktivieren und ein neues Backup erstellen.', $entry)];
            }
            $files[] = $entry;
        }
        sort($files);
        $files[] = 'manifest.json';

        return ['files' => $files];
    }

    /**
     * @return array{success: bool, error?: string, bytes?: int, files?: int}
     */
    public function uploadBackup(string $backupName): array
    {
        $config = $this->config();
        $errors = $config->validate();
        if ($errors !== []) {
            return $this->fail($backupName, 'Offsite-Ziel unvollständig: ' . implode(' ', $errors), false);
        }
        $list = $this->backupFiles($backupName);
        if (isset($list['error'])) {
            return $this->fail($backupName, $list['error']);
        }
        $this->markPending($backupName, $config->prefix);
        $client = ($this->clientFactory)($config);
        $bytes = 0;
        foreach ($list['files'] as $file) {
            $path = $this->backupRoot . $backupName . '/' . $file;
            $result = $client->uploadFile($config->prefix . $backupName . '/' . $file, $path, $file === 'manifest.json' ? 'application/json' : 'application/octet-stream');
            if (!$result['success']) {
                return $this->fail($backupName, sprintf('%s: %s', $file, $result['error'] ?? 'Upload fehlgeschlagen.'));
            }
            $bytes += (int) @filesize($path);
        }
        $check = $client->headObject($config->prefix . $backupName . '/manifest.json');
        if (!$check['exists']) {
            return $this->fail($backupName, 'Prüfung nach dem Upload fehlgeschlagen: ' . ($check['error'] ?? 'manifest.json fehlt.'));
        }
        $now = $this->now();
        $this->db->execute(
            "UPDATE {$this->prefix}backup_offsite_uploads SET status = 'uploaded', bytes = ?, files = ?, last_error = NULL, uploaded_at = ?, updated_at = ?
             WHERE backup_name = ? AND target = ?",
            [$bytes, count($list['files']), $now, $now, $backupName, self::TARGET]
        );
        $this->fire('cms_backup_offsite_uploaded', $backupName, $bytes);

        return ['success' => true, 'bytes' => $bytes, 'files' => count($list['files'])];
    }

    /**
     * Nach einem neuen Backup: hochladen (falls aktiviert) und Aufbewahrung anwenden.
     *
     * @return array{success: bool, skipped?: bool, error?: string}
     */
    public function handleBackupCreated(string $backupName): array
    {
        if (empty($this->settings()['enabled'])) {
            return ['success' => true, 'skipped' => true];
        }
        $result = $this->uploadBackup($backupName);
        if (!empty($result['success'])) {
            $this->applyRetention();
        }

        return $result;
    }

    /**
     * Cron `daily`: fehlgeschlagene/ausstehende Uploads nachholen (höchstens 3 Versuche), danach Aufbewahrung.
     *
     * @return array{retried: int, uploaded: int}
     */
    public function retryPending(): array
    {
        if (empty($this->settings()['enabled'])) {
            return ['retried' => 0, 'uploaded' => 0];
        }
        $rows = $this->db->get_results(
            "SELECT backup_name FROM {$this->prefix}backup_offsite_uploads WHERE target = ? AND status IN ('pending', 'failed') AND attempts < ? ORDER BY updated_at ASC LIMIT 10",
            [self::TARGET, self::MAX_ATTEMPTS]
        ) ?: [];
        $uploaded = 0;
        foreach ($rows as $row) {
            if (!empty($this->uploadBackup((string) $row->backup_name)['success'])) {
                $uploaded++;
            }
        }
        $this->applyRetention();

        return ['retried' => count($rows), 'uploaded' => $uploaded];
    }

    // ── Aufbewahrung ────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Welche Backups (Namen) sollen offsite gelöscht werden? Rein: neuestes zuerst sortiert, $protected nie.
     *
     * @param array<string, int> $backups Name → Zeitstempel (neueste Änderung)
     * @return list<string>
     */
    public static function retentionVictims(array $backups, int $keepLast, int $keepDays, int $now, string $protected): array
    {
        arsort($backups);
        $victims = [];
        $index = 0;
        foreach ($backups as $name => $timestamp) {
            $index++;
            if ($name === $protected) {
                continue;
            }
            $tooMany = $keepLast > 0 && $index > $keepLast;
            $tooOld = $keepDays > 0 && $timestamp < $now - $keepDays * 86400;
            if ($tooMany || $tooOld) {
                $victims[] = (string) $name;
            }
        }

        return $victims;
    }

    /**
     * @return array{success: bool, deleted: list<string>, error?: string}
     */
    public function applyRetention(): array
    {
        $settings = $this->settings();
        if ((int) $settings['keep_last'] === 0 && (int) $settings['keep_days'] === 0) {
            return ['success' => true, 'deleted' => []];
        }
        $config = $this->config();
        if ($config->validate() !== []) {
            return ['success' => false, 'deleted' => [], 'error' => 'Offsite-Ziel unvollständig.'];
        }
        $client = ($this->clientFactory)($config);
        $listing = $client->listAll($config->prefix);
        if (!$listing['success']) {
            return ['success' => false, 'deleted' => [], 'error' => $listing['error'] ?? 'Auflisten fehlgeschlagen.'];
        }
        $groups = [];
        $keysByBackup = [];
        foreach ($listing['keys'] as $object) {
            $relative = substr($object['key'], strlen($config->prefix));
            $name = (string) strtok($relative, '/');
            if ($name === '' || preg_match(self::NAME_PATTERN, $name) !== 1) {
                continue;
            }
            $timestamp = strtotime($object['modified']) ?: 0;
            $groups[$name] = max($groups[$name] ?? 0, $timestamp);
            $keysByBackup[$name][] = $object['key'];
        }
        $protected = (string) ($this->db->get_var(
            "SELECT backup_name FROM {$this->prefix}backup_offsite_uploads WHERE target = ? AND status = 'uploaded' ORDER BY uploaded_at DESC LIMIT 1",
            [self::TARGET]
        ) ?? '');
        $deleted = [];
        foreach (self::retentionVictims($groups, (int) $settings['keep_last'], (int) $settings['keep_days'], ($this->clock)(), $protected) as $name) {
            $ok = true;
            foreach ($keysByBackup[$name] ?? [] as $key) {
                $ok = $client->deleteObject($key) && $ok;
            }
            if ($ok) {
                $deleted[] = $name;
                $this->db->execute(
                    "UPDATE {$this->prefix}backup_offsite_uploads SET status = 'deleted', updated_at = ? WHERE backup_name = ? AND target = ?",
                    [$this->now(), $name, self::TARGET]
                );
            }
        }

        return ['success' => true, 'deleted' => $deleted];
    }

    // ── Prüfen, Status ──────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Schreibt, liest (HEAD) und löscht ein Testobjekt.
     *
     * @return array{success: bool, message?: string, error?: string}
     */
    public function testConnection(): array
    {
        $config = $this->config();
        $errors = $config->validate();
        if ($errors !== []) {
            return ['success' => false, 'error' => implode(' ', $errors)];
        }
        $client = ($this->clientFactory)($config);
        $key = $config->prefix . '.365cms-verbindungstest-' . bin2hex(random_bytes(4)) . '.txt';
        $temp = tempnam(sys_get_temp_dir(), 'cms-s3-');
        if ($temp === false) {
            return ['success' => false, 'error' => 'Temporäre Datei konnte nicht angelegt werden.'];
        }
        try {
            file_put_contents($temp, '365CMS Verbindungstest ' . $this->now());
            $put = $client->putObject($key, $temp, 'text/plain');
            if (!$put['success']) {
                return ['success' => false, 'error' => 'Schreiben fehlgeschlagen: ' . ($put['error'] ?? '')];
            }
            $head = $client->headObject($key);
            $deleted = $client->deleteObject($key);
            if (!$head['exists']) {
                return ['success' => false, 'error' => 'Lesen fehlgeschlagen: ' . ($head['error'] ?? '')];
            }

            return $deleted
                ? ['success' => true, 'message' => 'Verbindung in Ordnung: Testobjekt geschrieben, gelesen und gelöscht.']
                : ['success' => false, 'error' => 'Schreiben und Lesen klappen, Löschen nicht – für die Aufbewahrung ist das Löschrecht nötig.'];
        } finally {
            @unlink($temp);
        }
    }

    /**
     * Status je Backup-Name.
     *
     * @return array<string, array{status: string, bytes: int, attempts: int, last_error: string, uploaded_at: string}>
     */
    public function statusMap(): array
    {
        try {
            $rows = $this->db->get_results(
                "SELECT backup_name, status, bytes, attempts, last_error, uploaded_at FROM {$this->prefix}backup_offsite_uploads WHERE target = ? ORDER BY updated_at DESC LIMIT 200",
                [self::TARGET]
            ) ?: [];
        } catch (\Throwable) {
            return [];
        }
        $map = [];
        foreach ($rows as $row) {
            $map[(string) $row->backup_name] = [
                'status' => (string) $row->status,
                'bytes' => (int) $row->bytes,
                'attempts' => (int) $row->attempts,
                'last_error' => (string) ($row->last_error ?? ''),
                'uploaded_at' => (string) ($row->uploaded_at ?? ''),
            ];
        }

        return $map;
    }

    // ── Intern ──────────────────────────────────────────────────────────────────────────────────────────────────

    private function markPending(string $backupName, string $objectPrefix): void
    {
        $now = $this->now();
        $exists = (int) $this->db->get_var(
            "SELECT COUNT(*) FROM {$this->prefix}backup_offsite_uploads WHERE backup_name = ? AND target = ?",
            [$backupName, self::TARGET]
        );
        if ($exists > 0) {
            $this->db->execute(
                "UPDATE {$this->prefix}backup_offsite_uploads SET status = 'pending', attempts = attempts + 1, object_prefix = ?, updated_at = ? WHERE backup_name = ? AND target = ?",
                [$objectPrefix . $backupName . '/', $now, $backupName, self::TARGET]
            );

            return;
        }
        $this->db->execute(
            "INSERT INTO {$this->prefix}backup_offsite_uploads (backup_name, target, object_prefix, status, bytes, files, attempts, created_at, updated_at)
             VALUES (?, ?, ?, 'pending', 0, 0, 1, ?, ?)",
            [$backupName, self::TARGET, $objectPrefix . $backupName . '/', $now, $now]
        );
    }

    /**
     * @return array{success: false, error: string}
     */
    private function fail(string $backupName, string $error, bool $record = true): array
    {
        $error = mb_substr($error, 0, 500);
        if ($record && preg_match(self::NAME_PATTERN, $backupName) === 1) {
            try {
                $now = $this->now();
                $exists = (int) $this->db->get_var(
                    "SELECT COUNT(*) FROM {$this->prefix}backup_offsite_uploads WHERE backup_name = ? AND target = ?",
                    [$backupName, self::TARGET]
                );
                if ($exists > 0) {
                    $this->db->execute(
                        "UPDATE {$this->prefix}backup_offsite_uploads SET status = 'failed', last_error = ?, updated_at = ? WHERE backup_name = ? AND target = ?",
                        [$error, $now, $backupName, self::TARGET]
                    );
                } else {
                    $this->db->execute(
                        "INSERT INTO {$this->prefix}backup_offsite_uploads (backup_name, target, object_prefix, status, bytes, files, attempts, last_error, created_at, updated_at)
                         VALUES (?, ?, '', 'failed', 0, 0, ?, ?, ?, ?)",
                        // Klartext-Backups werden nie offsite kopiert: gleich als „ausgeschöpft“ markieren.
                        [$backupName, self::TARGET, self::MAX_ATTEMPTS, $error, $now, $now]
                    );
                }
            } catch (\Throwable) {
            }
        }
        $this->fire('cms_backup_offsite_failed', $backupName, $error);
        try {
            Logger::instance()->withChannel('backup.offsite')->warning('Offsite-Upload fehlgeschlagen.', ['backup' => $backupName, 'error' => $error]);
        } catch (\Throwable) {
        }

        return ['success' => false, 'error' => $error];
    }

    private function fire(string $hook, mixed ...$args): void
    {
        try {
            Hooks::doAction($hook, ...$args);
        } catch (\Throwable) {
        }
    }

    private function now(): string
    {
        return date('Y-m-d H:i:s', ($this->clock)());
    }
}
