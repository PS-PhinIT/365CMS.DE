<?php
declare(strict_types=1);

/**
 * AntispamModule – Spam-Filter, Honeypot, Blacklists
 */

if (!defined('ABSPATH')) {
    exit;
}

use CMS\AuditLogger;

class AntispamModule
{
    private readonly \CMS\Database $db;
    private readonly string $prefix;

    public function __construct()
    {
        $this->db     = \CMS\Database::instance();
        $this->prefix = $this->db->getPrefix();
        $this->db->runSchemaOnce('antispam_blacklist', __FILE__, fn (): bool => $this->ensureTable());
    }

    private function ensureTable(): bool
    {
        $this->db->getPdo()->exec(
            "CREATE TABLE IF NOT EXISTS {$this->prefix}spam_blacklist (
                id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                type        VARCHAR(20) NOT NULL DEFAULT 'word',
                value       VARCHAR(255) NOT NULL,
                created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_type (type),
                UNIQUE KEY uniq_type_value (type, value)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        try {
            $this->db->getPdo()->exec(
                "ALTER TABLE {$this->prefix}spam_blacklist ADD UNIQUE KEY uniq_type_value (type, value)"
            );
        } catch (\Throwable $e) {
            // Index existiert bereits oder Altbestand enthält Duplikate; Laufzeitprüfung fängt Duplikate trotzdem ab.
        }

        return true;
    }

    public function getData(): array
    {
        $blacklist = $this->db->get_results(
            "SELECT * FROM {$this->prefix}spam_blacklist ORDER BY type, value"
        ) ?: [];

        $settings = $this->loadSettings();

        // Spam-Statistiken
        $spamCount = 0;
        try {
            $spamCount = (int)$this->db->get_var(
                "SELECT COUNT(*) FROM {$this->prefix}comments WHERE status = 'spam'"
            );
        } catch (\Exception $e) {}

        $wordCount = 0;
        $emailCount = 0;
        $ipCount = 0;
        foreach ($blacklist as $b) {
            if ($b->type === 'word')  $wordCount++;
            if ($b->type === 'email') $emailCount++;
            if ($b->type === 'ip')    $ipCount++;
        }

        return [
            'blacklist' => array_map(fn($b) => (array)$b, $blacklist),
            'settings'  => $settings,
            'stats'     => [
                'spam_comments' => $spamCount,
                'words'         => $wordCount,
                'emails'        => $emailCount,
                'ips'           => $ipCount,
            ],
        ];
    }

    public function saveSettings(array $post): array
    {
        $keys = [
            'antispam_enabled'         => isset($post['antispam_enabled']) ? '1' : '0',
            'antispam_honeypot'        => isset($post['antispam_honeypot']) ? '1' : '0',
            'antispam_min_time'        => (string)max(0, min(60, (int)($post['antispam_min_time'] ?? 3))),
            'antispam_max_links'       => (string)max(0, min(50, (int)($post['antispam_max_links'] ?? 3))),
            'antispam_block_empty_ua'  => isset($post['antispam_block_empty_ua']) ? '1' : '0',
        ];

        try {
            foreach ($keys as $key => $value) {
                $exists = $this->db->get_var("SELECT COUNT(*) FROM {$this->prefix}settings WHERE option_name = ?", [$key]);
                if ($exists) {
                    $this->db->update('settings', ['option_value' => $value], ['option_name' => $key]);
                } else {
                    $this->db->insert('settings', ['option_name' => $key, 'option_value' => $value]);
                }
            }
            AuditLogger::instance()->log(
                AuditLogger::CAT_SECURITY,
                'antispam.settings.save',
                'AntiSpam-Einstellungen gespeichert',
                'setting',
                null,
                $keys,
                'warning'
            );
            return ['success' => true, 'message' => 'AntiSpam-Einstellungen gespeichert.'];
        } catch (\Throwable $e) {
            AuditLogger::instance()->log(
                AuditLogger::CAT_SECURITY,
                'antispam.settings.save_failed',
                'AntiSpam-Einstellungen konnten nicht gespeichert werden',
                'setting',
                null,
                ['exception' => $e::class],
                'error'
            );

            return ['success' => false, 'error' => 'AntiSpam-Einstellungen konnten nicht gespeichert werden.'];
        }
    }

    public function addBlacklist(array $post): array
    {
        $type  = in_array($post['bl_type'] ?? '', ['word', 'email', 'ip', 'domain'], true)
            ? (string)$post['bl_type'] : 'word';
        $value = $this->sanitizeText((string)($post['bl_value'] ?? ''), 255);
        if ($value === '') {
            return ['success' => false, 'error' => 'Wert ist erforderlich.'];
        }
        if ($type === 'ip' && !filter_var($value, FILTER_VALIDATE_IP)) {
            return ['success' => false, 'error' => 'Ungültige IP-Adresse.'];
        }
        if ($type === 'email' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'error' => 'Ungültige E-Mail-Adresse.'];
        }
        if ($type === 'domain') {
            $value = strtolower($value);
            if (preg_match('/^(?:[a-z0-9-]+\.)+[a-z]{2,}$/', $value) !== 1) {
                return ['success' => false, 'error' => 'Ungültige Domain.'];
            }
        }

        try {
            $exists = (int)$this->db->get_var(
                "SELECT COUNT(*) FROM {$this->prefix}spam_blacklist WHERE type = ? AND value = ?",
                [$type, $value]
            );
            if ($exists > 0) {
                return ['success' => true, 'message' => 'Blacklist-Eintrag existiert bereits.'];
            }

            $this->db->insert('spam_blacklist', [
                'type'  => $type,
                'value' => $value,
            ]);
            AuditLogger::instance()->log(
                AuditLogger::CAT_SECURITY,
                'antispam.blacklist.add',
                'AntiSpam-Blacklist-Eintrag hinzugefügt',
                'spam_blacklist',
                null,
                ['type' => $type, 'value' => $value],
                'warning'
            );
            return ['success' => true, 'message' => 'Blacklist-Eintrag hinzugefügt.'];
        } catch (\Throwable $e) {
            AuditLogger::instance()->log(
                AuditLogger::CAT_SECURITY,
                'antispam.blacklist.add_failed',
                'AntiSpam-Blacklist-Eintrag konnte nicht hinzugefügt werden',
                'spam_blacklist',
                null,
                ['type' => $type, 'exception' => $e::class],
                'error'
            );

            return ['success' => false, 'error' => 'Blacklist-Eintrag konnte nicht hinzugefügt werden.'];
        }
    }

    public function deleteBlacklist(int $id): array
    {
        if ($id <= 0) {
            return ['success' => false, 'error' => 'Ungültige ID.'];
        }
        try {
            $deleted = $this->db->delete('spam_blacklist', ['id' => $id]);
            if (!$deleted) {
                return ['success' => false, 'error' => 'Blacklist-Eintrag konnte nicht gelöscht werden.'];
            }

            AuditLogger::instance()->log(
                AuditLogger::CAT_SECURITY,
                'antispam.blacklist.delete',
                'AntiSpam-Blacklist-Eintrag gelöscht',
                'spam_blacklist',
                $id,
                [],
                'warning'
            );

            return ['success' => true, 'message' => 'Blacklist-Eintrag gelöscht.'];
        } catch (\Throwable $e) {
            AuditLogger::instance()->log(
                AuditLogger::CAT_SECURITY,
                'antispam.blacklist.delete_failed',
                'AntiSpam-Blacklist-Eintrag konnte nicht gelöscht werden',
                'spam_blacklist',
                $id,
                ['exception' => $e::class],
                'error'
            );

            return ['success' => false, 'error' => 'Blacklist-Eintrag konnte nicht gelöscht werden.'];
        }
    }

    /**
     * Dieselben Standardwerte wie die Laufzeitprüfung (AntispamService), damit das Formular
     * den tatsächlich wirksamen Zustand zeigt und ein Speichern ihn nicht verändert.
     *
     * @return array<string,string>
     */
    private function loadSettings(): array
    {
        return \CMS\Services\AntispamService::getInstance()->getSettings();
    }

    private function sanitizeText(string $value, int $maxLength): string
    {
        $value = preg_replace('/[\x00-\x1F\x7F]/u', '', trim($value)) ?? '';

        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $maxLength);
        }

        return substr($value, 0, $maxLength);
    }
}
