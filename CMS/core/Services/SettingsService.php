<?php
/**
 * Zentrale Settings-Abstraktion auf Basis der vorhandenen cms_settings-Tabelle.
 *
 * Nutzt die bestehende Tabelle `{prefix}settings` (physisch meist `cms_settings`)
 * und kapselt Gruppen/Keys sowie optionale Verschlüsselung für Secrets.
 *
 * @package CMSv2\Core\Services
 */

declare(strict_types=1);

namespace CMS\Services;

use CMS\Database;
use CMS\Logger;

if (!defined('ABSPATH')) {
    exit;
}

class SettingsService
{
    /** Altformat vor 4.0.0 (AES-256-CBC ohne MAC); neue Werte nutzen CryptoService::PREFIX. */
    private const LEGACY_ENCRYPTION_PREFIX = 'enc:';
    private const JSON_PREFIX = 'json:';

    private static ?self $instance = null;

    private Database $db;
    private Logger $logger;
    private string $prefix;
    private CryptoService $crypto;

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    private function __construct()
    {
        $this->db = Database::instance();
        $this->logger = Logger::instance()->withChannel('settings');
        $this->prefix = $this->db->getPrefix();
        $this->crypto = CryptoService::getInstance();
    }

    public function get(string $group, string $key, mixed $default = null): mixed
    {
        $optionName = $this->buildOptionName($group, $key);

        // Request-Cache; DB-Fehler liefern dort null → Default.
        $value = OptionStore::getInstance()->get($optionName);

        if ($value === null) {
            return $default;
        }

        try {
            return $this->decodeValue((string) $value, $optionName);
        } catch (\Throwable $e) {
            $this->logger->error('Settings-Wert konnte nicht dekodiert werden: {option}', [
                'option' => $optionName,
                'exception' => $e,
            ]);
            return $default;
        }
    }

    public function getString(string $group, string $key, string $default = ''): string
    {
        $value = $this->get($group, $key, $default);

        if (is_scalar($value)) {
            return trim((string) $value);
        }

        return $default;
    }

    public function getInt(string $group, string $key, int $default = 0): int
    {
        $value = $this->get($group, $key, $default);
        return is_numeric($value) ? (int) $value : $default;
    }

    public function getBool(string $group, string $key, bool $default = false): bool
    {
        $value = $this->get($group, $key, $default);

        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value === 1;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
        }

        return $default;
    }

    /**
     * @return array<string, mixed>
     */
    public function getGroup(string $group): array
    {
        $group = $this->sanitizeSegment($group);
        $prefix = $group . '.';

        try {
            $rows = $this->db->get_results(
                "SELECT option_name, option_value FROM {$this->prefix}settings WHERE option_name LIKE ? ORDER BY option_name ASC",
                [$prefix . '%']
            ) ?: [];
        } catch (\Throwable $e) {
            $this->logger->warning('Settings-Gruppe konnte nicht geladen werden: {group}', [
                'group' => $group,
                'exception' => $e,
            ]);
            return [];
        }

        $result = [];
        foreach ($rows as $row) {
            $optionName = (string) ($row->option_name ?? '');
            if (!str_starts_with($optionName, $prefix)) {
                continue;
            }

            $key = substr($optionName, strlen($prefix));
            try {
                $result[$key] = $this->decodeValue((string) ($row->option_value ?? ''), $optionName);
            } catch (\Throwable $e) {
                $this->logger->error('Settings-Wert konnte nicht dekodiert werden: {option}', [
                    'option' => $optionName,
                    'exception' => $e,
                ]);
            }
        }

        return $result;
    }

    public function set(string $group, string $key, mixed $value, bool $encrypted = false, int $autoload = 0): bool
    {
        $optionName = $this->buildOptionName($group, $key);
        $storedValue = $this->encodeValue($value, $encrypted, $optionName);
        $autoload = $autoload === 1 ? 1 : 0;

        try {
            $this->db->execute(
                "INSERT INTO {$this->prefix}settings (option_name, option_value, autoload)
                 VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE option_value = VALUES(option_value), autoload = VALUES(autoload)",
                [$optionName, $storedValue, $autoload]
            );
            return true;
        } catch (\Throwable $e) {
            $this->logger->error('Setting konnte nicht gespeichert werden: {option}', [
                'option' => $optionName,
                'exception' => $e,
            ]);
            return false;
        }
    }

    /**
     * @param array<string, mixed> $values
     * @param list<string> $encryptedKeys
     */
    public function setMany(string $group, array $values, array $encryptedKeys = [], int $autoload = 0): bool
    {
        foreach ($values as $key => $value) {
            $isEncrypted = in_array((string) $key, $encryptedKeys, true);
            $rowAutoload = $isEncrypted ? 0 : $autoload;
            if (!$this->set($group, (string) $key, $value, $isEncrypted, $rowAutoload)) {
                return false;
            }
        }

        return true;
    }

    public function forget(string $group, string $key): bool
    {
        $optionName = $this->buildOptionName($group, $key);

        try {
            $this->db->execute(
                "DELETE FROM {$this->prefix}settings WHERE option_name = ?",
                [$optionName]
            );
            return true;
        } catch (\Throwable $e) {
            $this->logger->warning('Setting konnte nicht gelöscht werden: {option}', [
                'option' => $optionName,
                'exception' => $e,
            ]);
            return false;
        }
    }

    private function buildOptionName(string $group, string $key): string
    {
        return $this->sanitizeSegment($group) . '.' . $this->sanitizeSegment($key);
    }

    private function sanitizeSegment(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9._-]/', '', $value) ?? '';

        if ($value === '') {
            throw new \InvalidArgumentException('Ungültiger Settings-Key oder Gruppenname.');
        }

        return $value;
    }

    /**
     * Bringt verschlüsselte Werte im Altformat (AES-256-CBC) oder mit älterer Schlüssel-ID
     * auf das aktuelle Format. Verarbeitet höchstens $limit Werte je Aufruf.
     *
     * @return bool true, wenn keine weiteren Werte offen sind
     */
    public function migrateEncryptedValues(int $limit = 500): bool
    {
        $rows = $this->db->get_results(
            "SELECT option_name, option_value FROM {$this->prefix}settings WHERE option_value LIKE 'enc:%'"
        ) ?: [];

        $migrated = 0;
        foreach ($rows as $row) {
            $optionName = (string) ($row->option_name ?? '');
            $storedValue = (string) ($row->option_value ?? '');
            if ($optionName === '' || !$this->needsMigration($storedValue)) {
                continue;
            }

            if ($migrated >= $limit) {
                return false;
            }

            try {
                $this->migrateStoredValue($optionName, $storedValue, $this->decryptStoredValue($storedValue, $optionName));
                $migrated++;
            } catch (\Throwable $e) {
                $this->logger->error('Verschlüsselter Settings-Wert konnte nicht migriert werden: {option}', [
                    'option' => $optionName,
                    'exception' => $e,
                ]);
            }
        }

        return true;
    }

    private function encodeValue(mixed $value, bool $encrypted, string $optionName): string
    {
        $normalized = $this->normalizeValue($value);

        if (!$encrypted) {
            return $normalized;
        }

        return $this->crypto->encrypt($normalized, CryptoService::CONTEXT_SETTINGS, $optionName);
    }

    private function decodeValue(string $value, string $optionName): mixed
    {
        $decoded = $value;
        if (str_starts_with($decoded, self::LEGACY_ENCRYPTION_PREFIX)) {
            $decoded = $this->decryptStoredValue($value, $optionName);
            if ($this->needsMigration($value)) {
                $this->migrateStoredValue($optionName, $value, $decoded);
            }
        }

        if (str_starts_with($decoded, self::JSON_PREFIX)) {
            return json_decode(substr($decoded, strlen(self::JSON_PREFIX)), true, 512, JSON_THROW_ON_ERROR);
        }

        return $decoded;
    }

    private function decryptStoredValue(string $storedValue, string $optionName): string
    {
        if ($this->crypto->isEncrypted($storedValue)) {
            return $this->crypto->decrypt($storedValue, CryptoService::CONTEXT_SETTINGS, $optionName);
        }

        return $this->crypto->decryptLegacySettingsValue(substr($storedValue, strlen(self::LEGACY_ENCRYPTION_PREFIX)));
    }

    private function needsMigration(string $storedValue): bool
    {
        return str_starts_with($storedValue, self::LEGACY_ENCRYPTION_PREFIX)
            && (!$this->crypto->isEncrypted($storedValue) || $this->crypto->needsReencryption($storedValue));
    }

    /**
     * Schreibt einen entschlüsselten Altwert im aktuellen Format zurück – nur, wenn die Zeile
     * unverändert ist (paralleles Speichern gewinnt).
     */
    private function migrateStoredValue(string $optionName, string $previousValue, string $plainText): void
    {
        try {
            $this->db->execute(
                "UPDATE {$this->prefix}settings SET option_value = ? WHERE option_name = ? AND option_value = ?",
                [$this->crypto->encrypt($plainText, CryptoService::CONTEXT_SETTINGS, $optionName), $optionName, $previousValue]
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Settings-Wert konnte nicht neu verschlüsselt werden: {option}', [
                'option' => $optionName,
                'exception' => $e,
            ]);
        }
    }

    private function normalizeValue(mixed $value): string
    {
        if (is_array($value) || is_bool($value) || is_int($value) || is_float($value) || $value === null) {
            return self::JSON_PREFIX . json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        if (is_object($value)) {
            return self::JSON_PREFIX . json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return (string) $value;
    }
}
