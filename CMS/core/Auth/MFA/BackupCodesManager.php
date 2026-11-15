<?php
/**
 * BackupCodesManager – Einmal-Backup-Codes für MFA-Recovery
 *
 * Generiert 10 kryptographisch sichere Backup-Codes (je 8 Zeichen alphanumerisch).
 * Codes werden Bcrypt-gehasht in cms_user_meta gespeichert.
 * Jeder Code kann nur einmal verwendet werden.
 *
 * @package CMSv2\Core\Auth\MFA
 */

declare(strict_types=1);

namespace CMS\Auth\MFA;

if (!defined('ABSPATH')) {
    exit;
}

use CMS\Database;
use CMS\Json;
use CMS\Security;

final class BackupCodesManager
{
    /** Anzahl der zu generierenden Codes */
    private const CODE_COUNT = 10;

    /** Länge eines einzelnen Codes (Zeichen) */
    private const CODE_LENGTH = 8;

    /** user_meta-Key für die gehashten Codes */
    private const META_KEY = 'mfa_backup_codes';

    private static ?self $instance = null;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    private function __construct() {}

    // ── Öffentliche API ──────────────────────────────────────────────────────

    /**
     * Generiert neue Backup-Codes und speichert die gehashten Versionen.
     * Gibt die Klartext-Codes zurück (nur einmal anzeigbar!).
     *
     * @return array<string> Klartext-Codes (z. B. ['A3K9-M2X7', ...])
     */
    public function generate(int $userId): array
    {
        $plainCodes = [];
        $hashedCodes = [];

        for ($i = 0; $i < self::CODE_COUNT; $i++) {
            $code = $this->generateSingleCode();
            $plainCodes[] = $code;
            $hashedCodes[] = Security::instance()->hashPassword($code);
        }

        $this->saveHashedCodes($userId, $hashedCodes);

        return $plainCodes;
    }

    /**
     * Regeneriert Backup-Codes (löscht alte, erstellt neue).
     *
     * @return array<string> Neue Klartext-Codes
     */
    public function regenerate(int $userId): array
    {
        return $this->generate($userId);
    }

    /**
     * Prüft einen Backup-Code. Bei Gültigkeit wird er verbraucht (gelöscht).
     */
    public function verify(int $userId, string $code): bool
    {
        $code = $this->normalizeCode($code);
        if (strlen($code) !== self::CODE_LENGTH) {
            return false;
        }

        $raw = $this->getRawCodes($userId);
        $hashedCodes = $raw !== '' ? Json::decodeArray($raw, []) : [];
        if (!is_array($hashedCodes) || $hashedCodes === []) {
            return false;
        }

        foreach ($hashedCodes as $index => $hash) {
            if (is_string($hash) && Security::instance()->verifyPassword($code, $hash)) {
                // SEC-410-22: Code atomar verbrauchen (Vergleich-und-Tausch) – zwei gleichzeitige Anfragen mit demselben
                // Code dürfen nicht beide durchkommen.
                unset($hashedCodes[$index]);
                $db = Database::instance();

                return $db->execute(
                    "UPDATE {$db->getPrefix()}user_meta SET meta_value = ? WHERE user_id = ? AND meta_key = ? AND meta_value = ?",
                    [json_encode(array_values($hashedCodes), JSON_THROW_ON_ERROR), $userId, self::META_KEY, $raw]
                )->rowCount() === 1;
            }
        }

        return false;
    }

    /**
     * Anzahl verbleibender Backup-Codes.
     */
    public function getRemainingCount(int $userId): int
    {
        return count($this->getHashedCodes($userId));
    }

    /**
     * Prüft ob der User Backup-Codes hat.
     */
    public function hasBackupCodes(int $userId): bool
    {
        return $this->getRemainingCount($userId) > 0;
    }

    /**
     * Alle Backup-Codes eines Users löschen (z. B. bei MFA-Deaktivierung).
     */
    public function deleteAll(int $userId): void
    {
        $this->deleteUserMeta($userId);
    }

    // ── Interne Methoden ─────────────────────────────────────────────────────

    /**
     * Einzelnen alphanumerischen Code generieren (A-Z, 0-9, ohne Verwechslungszeichen).
     */
    private function generateSingleCode(): string
    {
        // Verwechslungsfreier Zeichensatz (kein 0/O, 1/I/L)
        $chars = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
        $charsLen = strlen($chars);
        $code = '';

        $bytes = random_bytes(self::CODE_LENGTH);
        for ($i = 0; $i < self::CODE_LENGTH; $i++) {
            $code .= $chars[ord($bytes[$i]) % $charsLen];
        }

        return $code;
    }

    /**
     * Code normalisieren: Uppercase, Bindestriche/Leerzeichen entfernen.
     */
    private function normalizeCode(string $code): string
    {
        return strtoupper(str_replace(['-', ' '], '', trim($code)));
    }

    /**
     * Formatiert einen Code zur Anzeige mit Bindestrich (z. B. 'A3K9-M2X7').
     */
    public static function formatCode(string $code): string
    {
        $code = strtoupper(str_replace(['-', ' '], '', $code));
        if (strlen($code) === 8) {
            return substr($code, 0, 4) . '-' . substr($code, 4);
        }
        return $code;
    }

    // ── user_meta-Zugriff ────────────────────────────────────────────────────

    /**
     * @return array<string> Gehashte Codes
     */
    private function getRawCodes(int $userId): string
    {
        $db = Database::instance();
        $value = $db->get_var(
            "SELECT meta_value FROM {$db->getPrefix()}user_meta WHERE user_id = ? AND meta_key = ? LIMIT 1",
            [$userId, self::META_KEY]
        );

        return is_string($value) ? $value : '';
    }

    private function getHashedCodes(int $userId): array
    {
        $db = Database::instance();
        $stmt = $db->prepare(
            "SELECT meta_value FROM {$db->getPrefix()}user_meta WHERE user_id = ? AND meta_key = ? LIMIT 1"
        );
        $stmt->execute([$userId, self::META_KEY]);
        $row = $stmt->fetch();

        if (!$row || empty($row->meta_value)) {
            return [];
        }

        $decoded = Json::decodeArray($row->meta_value ?? null, []);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string> $hashedCodes
     */
    private function saveHashedCodes(int $userId, array $hashedCodes): void
    {
        $db = Database::instance();
        $json = json_encode($hashedCodes, JSON_THROW_ON_ERROR);

        $db->execute(
            "INSERT INTO {$db->getPrefix()}user_meta (user_id, meta_key, meta_value)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)",
            [$userId, self::META_KEY, $json]
        );
    }

    private function deleteUserMeta(int $userId): void
    {
        $db = Database::instance();
        $db->execute(
            "DELETE FROM {$db->getPrefix()}user_meta WHERE user_id = ? AND meta_key = ?",
            [$userId, self::META_KEY]
        );
    }
}
