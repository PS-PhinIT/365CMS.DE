<?php
/**
 * AuditLogger – Zentrales Sicherheits-Audit-Log
 *
 * Protokolliert sicherheitsrelevante Aktionen (Theme-Wechsel, Plugin-Aktivierung,
 * Admin-Login, Rollenwechsel, Code-Änderungen …) in der Tabelle {prefix}audit_log.
 *
 * Roadmap H-01: Zentrales Audit-Log-System
 * Roadmap C-15: Theme-Aktionen (Aktivieren, Löschen, Code-Änderung) protokollieren
 *
 * @package CMSv2\Core
 */

declare(strict_types=1);

namespace CMS;

if (!defined('ABSPATH')) {
    exit;
}

class AuditLogger
{
    private static ?self $instance = null;

    // ── Kategorien ────────────────────────────────────────────────────────────
    public const CAT_AUTH    = 'auth';       // Login, Logout, PW-Reset
    public const CAT_CONTENT = 'content';    // Seiten, Beiträge, Rechtstexte
    public const CAT_THEME   = 'theme';      // Aktivieren, Löschen, Code-Edit
    public const CAT_PLUGIN  = 'plugin';     // Aktivieren, Deaktivieren, Install
    public const CAT_USER    = 'user';       // Erstellen, Rollen, Löschen
    public const CAT_SETTING = 'setting';    // Admin-Einstellungen
    public const CAT_MEDIA   = 'media';      // Upload, Löschen
    public const CAT_SYSTEM  = 'system';     // Backup, Updates, Cache-Flush
    public const CAT_SECURITY = 'security';  // CSP, Firewall, Sperr-IPs

    /**
     * Singleton
     */
    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        // Tabelle wird durch install.php / Database::createTables() angelegt
    }

    /**
     * Sicherheitsrelevante Aktion protokollieren
     *
     * @param string      $category   Kategorie (nutze die CAT_* Konstanten)
     * @param string      $action     Kurzbezeichnung der Aktion, z. B. 'theme.switch'
     * @param string      $description Lesbarer Text für das Protokoll
     * @param string      $entityType  Betroffener Typ (theme|plugin|user …)
     * @param int|null    $entityId    Betroffene ID (optional)
     * @param array       $metadata    Zusatzdaten als assoziatives Array
     * @param string      $severity   'info'|'warning'|'critical'
     */
    public function log(
        string  $category,
        string  $action,
        string  $description,
        string  $entityType  = '',
        ?int    $entityId    = null,
        array   $metadata    = [],
        string  $severity    = 'info'
    ): void {
        try {
            $db = Database::instance();

            $userId    = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
            $ipAddress = $this->getClientIp();
            $userAgent = $this->sanitizeLogText((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 500);
            $category = $this->sanitizeIdentifier($category, 40, self::CAT_SYSTEM);
            $action = $this->sanitizeIdentifier($action, 100, 'unknown');
            $description = $this->sanitizeLogText($description, 1000);
            $entityType = $this->sanitizeIdentifier($entityType, 100, '');
            $severity = $this->sanitizeSeverity($severity);
            $metadata = $this->sanitizeMetadata($metadata);

            $db->execute(
                "INSERT INTO {$db->getPrefix()}audit_log
                    (user_id, category, action, entity_type, entity_id,
                     description, ip_address, user_agent, metadata, severity, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())",
                [
                    $userId,
                    $category,
                    $action,
                    $entityType ?: null,
                    $entityId,
                    $description,
                    $ipAddress,
                    $userAgent,
                    !empty($metadata) ? json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR) : null,
                    $severity,
                ]
            );
        } catch (\Throwable $e) {
            // Audit-Logging darf die Anwendung nie zum Absturz bringen
            error_log('AuditLogger::log() failed: ' . $this->sanitizeLogText($this->maskInlineSecrets($e->getMessage()), 500));
        }
    }

    // ── Convenience-Methoden ─────────────────────────────────────────────────

    /** Theme wurde aktiviert */
    public function themeSwitch(string $from, string $to): void
    {
        $this->log(
            self::CAT_THEME,
            'theme.switch',
            "Theme gewechselt von \"{$from}\" zu \"{$to}\"",
            'theme',
            null,
            ['from' => $from, 'to' => $to],
            'info'
        );
    }

    /** Theme wurde gelöscht */
    public function themeDelete(string $folder): void
    {
        $this->log(
            self::CAT_THEME,
            'theme.delete',
            "Theme \"{$folder}\" wurde gelöscht",
            'theme',
            null,
            ['folder' => $folder],
            'warning'
        );
    }

    /** Theme-Datei wurde im Editor gespeichert */
    public function themeFileEdit(string $theme, string $file): void
    {
        $this->log(
            self::CAT_THEME,
            'theme.file.edit',
            "Theme-Datei bearbeitet: {$theme}/{$file}",
            'theme',
            null,
            ['theme' => $theme, 'file' => $file],
            'warning'
        );
    }

    /** Plugin aktiviert oder deaktiviert */
    public function pluginAction(string $action, string $slug): void
    {
        $severity = ($action === 'activate') ? 'info' : 'warning';
        $this->log(
            self::CAT_PLUGIN,
            "plugin.{$action}",
            "Plugin \"{$slug}\" wurde " . ($action === 'activate' ? 'aktiviert' : 'deaktiviert'),
            'plugin',
            null,
            ['slug' => $slug, 'action' => $action],
            $severity
        );
    }

    /** Admin-Login erfolgreich */
    public function loginSuccess(string $username): void
    {
        $this->log(
            self::CAT_AUTH,
            'auth.login',
            "Erfolgreicher Login: {$username}",
            'user',
            null,
            ['username' => $username],
            'info'
        );
    }

    /** Login-Fehlschlag (z. B. falsches Passwort) */
    public function loginFailed(string $username): void
    {
        $this->log(
            self::CAT_AUTH,
            'auth.login.failed',
            "Fehlgeschlagener Login-Versuch: {$username}",
            'user',
            null,
            ['username' => $username],
            'warning'
        );
    }

    /** Benutzer-Rolle geändert */
    public function userRoleChange(int $userId, string $oldRole, string $newRole): void
    {
        $this->log(
            self::CAT_USER,
            'user.role.change',
            "Rolle von User #{$userId} geändert: {$oldRole} → {$newRole}",
            'user',
            $userId,
            ['old_role' => $oldRole, 'new_role' => $newRole],
            'warning'
        );
    }

    /** Backup erstellt oder wiederhergestellt */
    public function backupAction(string $action, string $file): void
    {
        $this->log(
            self::CAT_SYSTEM,
            "backup.{$action}",
            "Backup-Aktion \"{$action}\": {$file}",
            'system',
            null,
            ['file' => $file, 'action' => $action],
            'warning'
        );
    }

    // ── Hilfsmethoden ─────────────────────────────────────────────────────────

    /**
     * Letzte Einträge abrufen (für Admin-Ansicht)
     */
    public function getRecent(int $limit = 50, string $category = ''): array
    {
        try {
            $db = Database::instance();
            $limit = max(1, min(200, $limit));
            $category = $this->sanitizeIdentifier($category, 40, '');

            if ($category !== '') {
                $stmt = $db->prepare(
                    "SELECT * FROM {$db->getPrefix()}audit_log
                      WHERE category = ?
                      ORDER BY created_at DESC LIMIT {$limit}"
                );
                $stmt->execute([$category]);
            } else {
                $stmt = $db->prepare(
                    "SELECT * FROM {$db->getPrefix()}audit_log
                      ORDER BY created_at DESC LIMIT {$limit}"
                );
                $stmt->execute();
            }

            return $stmt->fetchAll(\PDO::FETCH_OBJ) ?: [];
        } catch (\Throwable $e) {
            error_log('AuditLogger::getRecent() failed: ' . $this->sanitizeLogText($this->maskInlineSecrets($e->getMessage()), 500));
            return [];
        }
    }

    /**
     * Client-IP-Adresse – delegiert an Security::getClientIp() (keine Code-Duplizierung)
     */
    private function getClientIp(): string
    {
        return Security::getClientIp();
    }

    private function sanitizeIdentifier(string $value, int $maxLength, string $fallback): string
    {
        $value = strtolower($this->sanitizeLogText($value, $maxLength));
        $value = preg_replace('/[^a-z0-9_.:-]/', '_', $value) ?? '';
        $value = trim($value, '_.:-');

        return $value !== '' ? substr($value, 0, $maxLength) : $fallback;
    }

    private function sanitizeSeverity(string $severity): string
    {
        $severity = strtolower(trim($severity));

        return in_array($severity, ['info', 'warning', 'error', 'critical'], true) ? $severity : 'info';
    }

    private function sanitizeLogText(string $value, int $maxLength): string
    {
        // Ungültiges UTF-8 (z. B. im User-Agent) ersetzen statt den ganzen Text zu verlieren.
        if (function_exists('mb_scrub')) {
            $value = mb_scrub($value, 'UTF-8');
        }
        $value = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? (preg_replace('/[\x00-\x1F\x7F]+/', ' ', $value) ?? $value));

        return function_exists('mb_substr') ? mb_substr($value, 0, $maxLength, 'UTF-8') : substr($value, 0, $maxLength);
    }

    /** @param array<string,mixed> $metadata @return array<string,mixed> */
    private function sanitizeMetadata(array $metadata): array
    {
        foreach ($metadata as $key => $value) {
            // Gleicher Schlüsselfilter wie im Logger, damit beide nicht auseinanderlaufen.
            if (Logger::isSensitiveKey($key)) {
                $metadata[$key] = '***';
                continue;
            }

            if (is_string($value)) {
                $metadata[$key] = $this->sanitizeLogText($this->maskInlineSecrets($value), 500);
            } elseif (is_array($value)) {
                $metadata[$key] = $this->sanitizeMetadata($value);
            } elseif ($value instanceof \Throwable) {
                $metadata[$key] = $value::class;
            }
        }

        return $metadata;
    }

    private function maskInlineSecrets(string $value): string
    {
        $value = preg_replace('/([?&](?:token|csrf_token|nonce|key|secret|password|pass)=)[^&\s]+/i', '$1***', $value) ?? $value;
        $value = preg_replace('/\b(Bearer\s+)[A-Za-z0-9._~+\/-]+=*/i', '$1***', $value) ?? $value;
        $value = preg_replace('/\b(password|passwd|secret|token|credential|api[_-]?key)(\s*[=:]\s*)[^\s&]+/i', '$1$2***', $value) ?? $value;

        return $value;
    }
}
