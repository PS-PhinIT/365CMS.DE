<?php
/**
 * TotpAdapter – TOTP-MFA über RobThree/TwoFactorAuth
 *
 * Einzige Implementierung für Einrichtung, Prüfung und Abschalten von TOTP
 * (Auth::*Mfa*() delegiert hierher). Nutzt robthree/twofactorauth (v3.0.3) mit
 * QR-Code-Support und Zeitsynchronisierung; ohne Library delegiert an CMS\Totp.
 *
 * Secrets (`mfa_secret`, `mfa_pending_secret`) liegen seit 4.0.0 verschlüsselt in
 * `user_meta` (CryptoService, Kontext „mfa“, an Benutzer und Meta-Key gebunden).
 * Klartext-Altwerte werden beim Lesen bzw. einmal je Schlüsselstand migriert.
 *
 * @package CMSv2\Core\Auth\MFA
 * @see     https://github.com/RobThree/TwoFactorAuth
 */

declare(strict_types=1);

namespace CMS\Auth\MFA;

if (!defined('ABSPATH')) {
    exit;
}

use CMS\AuditLogger;
use CMS\Database;
use CMS\Logger;
use CMS\Services\CryptoService;
use CMS\Totp;

final class TotpAdapter
{
    private const SECRET_KEYS = ['mfa_secret', 'mfa_pending_secret'];

    private static ?self $instance = null;

    /** @var \RobThree\Auth\TwoFactorAuth|null */
    private ?object $tfa = null;
    private bool $libraryAvailable;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    private function __construct()
    {
        $this->libraryAvailable = class_exists(\RobThree\Auth\TwoFactorAuth::class);
    }

    // ── Library-Zugriff ──────────────────────────────────────────────────────

    /**
     * Gibt die RobThree-Instanz zurück (lazy init).
     * QR-Codes werden lokal per BaconQrCode als SVG erzeugt (kein externer QR-Dienst,
     * keine GD/Imagick-Abhängigkeit). Fehlt BaconQrCode, liefert der Provider leer.
     */
    private function getTfa(): ?\RobThree\Auth\TwoFactorAuth
    {
        if (!$this->libraryAvailable) {
            return null;
        }

        if ($this->tfa === null) {
            $issuer = defined('SITE_NAME') ? SITE_NAME : '365CMS';
            $qrProvider = class_exists(\BaconQrCode\Writer::class)
                ? new \RobThree\Auth\Providers\Qr\BaconQrCodeProvider(2, '#ffffff', '#000000', 'svg')
                : new class implements \RobThree\Auth\Providers\Qr\IQRCodeProvider {
                    public function getQRCodeImage(string $qrText, int $size): string
                    {
                        return '';
                    }

                    public function getMimeType(): string
                    {
                        return 'image/svg+xml';
                    }
                };
            $this->tfa = new \RobThree\Auth\TwoFactorAuth($qrProvider, $issuer);
        }

        return $this->tfa;
    }

    /**
     * Lokal erzeugter QR-Code als data:-URI (SVG) oder '' wenn nicht verfügbar.
     */
    public function getQrCodeDataUri(string $secret, string $account, int $size = 200): string
    {
        $tfa = $this->getTfa();
        if ($tfa === null || !class_exists(\BaconQrCode\Writer::class)) {
            return '';
        }

        try {
            $dataUri = $tfa->getQRCodeImageAsDataUri($account, $secret, $size);
        } catch (\Throwable) {
            return '';
        }

        // Nur ein nicht-leeres SVG-Bild akzeptieren (leerer Provider → "data:…;base64,").
        return preg_match('#^data:image/svg\+xml;base64,[A-Za-z0-9+/=]{16,}$#', $dataUri) === 1 ? $dataUri : '';
    }

    // ── Öffentliche API ──────────────────────────────────────────────────────

    /**
     * Neues TOTP-Secret erzeugen (Base32, 160 Bit).
     */
    public function generateSecret(): string
    {
        $tfa = $this->getTfa();
        if ($tfa !== null) {
            return $tfa->createSecret();
        }

        // Fallback auf eingebaute Implementierung
        return Totp::instance()->generateSecret();
    }

    /**
     * TOTP-Code verifizieren.
     *
     * @param int    $userId User-ID (liest Secret aus user_meta)
     * @param string $code   6-stelliger Code
     */
    public function verifyCode(int $userId, string $code): bool
    {
        $secret = $this->getUserSecret($userId);
        if ($secret === null) {
            return false;
        }

        return $this->verifyCodeWithSecret($secret, $code);
    }

    /**
     * TOTP-Code direkt gegen ein Secret prüfen (z. B. während Setup).
     */
    public function verifyCodeWithSecret(string $secret, string $code): bool
    {
        // Nur Ziffern, exakt 6 Stellen
        if (!preg_match('/^\d{6}$/', $code)) {
            return false;
        }

        $tfa = $this->getTfa();
        if ($tfa !== null) {
            return $tfa->verifyCode($secret, $code, 1);
        }

        return Totp::instance()->verifyCode($secret, $code);
    }

    /**
     * OTP-Auth-URI für QR-Code-Generierung im Frontend.
     */
    public function getOtpAuthUri(string $secret, string $account): string
    {
        $tfa = $this->getTfa();
        if ($tfa !== null) {
            return $tfa->getQRText($account, $secret);
        }

        $issuer = defined('SITE_NAME') ? SITE_NAME : '365CMS';
        return Totp::instance()->getOtpAuthUri($secret, $account, $issuer);
    }

    // ── MFA-Setup-Flow ───────────────────────────────────────────────────────

    /**
     * Beginnt den MFA-Einrichtungsflow: Erzeugt ein Pending-Secret.
     *
     * @return array{secret: string, otp_uri: string, qr_url: string, qr_data_uri: string}
     */
    public function startSetup(int $userId, string $accountLabel): array
    {
        $secret = $this->generateSecret();
        $this->storeSecret($userId, 'mfa_pending_secret', $secret);

        $otpUri = $this->getOtpAuthUri($secret, $accountLabel);
        $qrDataUri = $this->getQrCodeDataUri($secret, $accountLabel);

        return [
            'secret'      => $secret,
            'otp_uri'     => $otpUri,
            // Nie die otpauth://-URI als Bildquelle ausgeben (führt zu kaputtem <img>).
            'qr_url'      => $qrDataUri,
            'qr_data_uri' => $qrDataUri,
        ];
    }

    /**
     * Bestätigt MFA-Einrichtung: Prüft Code gegen Pending-Secret.
     * Ein bereits aktiver Authenticator wird nicht überschrieben (Wechsel nur nach Deaktivierung).
     * Optional muss das Pending-Secret dem im Setup-Schritt ausgegebenen Secret entsprechen.
     */
    public function confirmSetup(int $userId, string $code, ?string $expectedSecret = null): bool
    {
        if ($this->isEnabled($userId)) {
            return false;
        }

        $pendingSecret = $this->readSecret($userId, 'mfa_pending_secret');
        if ($pendingSecret === null) {
            return false;
        }

        if ($expectedSecret !== null && !hash_equals($pendingSecret, $expectedSecret)) {
            return false;
        }

        if (!$this->verifyCodeWithSecret($pendingSecret, $code)) {
            return false;
        }

        $this->storeSecret($userId, 'mfa_secret', $pendingSecret);
        $this->setUserMeta($userId, 'mfa_enabled', '1');
        $this->deleteUserMeta($userId, 'mfa_pending_secret');

        AuditLogger::instance()->log(
            AuditLogger::CAT_SECURITY,
            'mfa_enabled',
            'MFA (TOTP) aktiviert.',
            'user',
            $userId,
            [],
            'info'
        );

        return true;
    }

    /**
     * MFA deaktivieren: Secrets, Status und Backup-Codes entfernen.
     */
    public function disable(int $userId): void
    {
        $wasEnabled = $this->isEnabled($userId);

        $this->deleteUserMeta($userId, 'mfa_secret');
        $this->deleteUserMeta($userId, 'mfa_enabled');
        $this->deleteUserMeta($userId, 'mfa_pending_secret');
        BackupCodesManager::instance()->deleteAll($userId);

        if ($wasEnabled) {
            AuditLogger::instance()->log(
                AuditLogger::CAT_SECURITY,
                'mfa_disabled',
                'MFA (TOTP) deaktiviert.',
                'user',
                $userId,
                [],
                'warning'
            );
        }
    }

    /**
     * Prüft ob MFA für den User aktiv ist.
     */
    public function isEnabled(int $userId): bool
    {
        return $this->getUserMeta($userId, 'mfa_enabled') === '1';
    }

    /**
     * Verschlüsselt Klartext-Secrets bzw. Secrets mit älterer Schlüssel-ID neu.
     * Verarbeitet höchstens $limit Einträge je Aufruf.
     *
     * @return bool true, wenn keine weiteren Einträge offen sind
     */
    public function migrateStoredSecrets(int $limit = 500): bool
    {
        $db = Database::instance();
        $placeholders = implode(', ', array_fill(0, count(self::SECRET_KEYS), '?'));
        $rows = $db->get_results(
            "SELECT user_id, meta_key, meta_value FROM {$db->getPrefix()}user_meta WHERE meta_key IN ({$placeholders})",
            self::SECRET_KEYS
        ) ?: [];

        $crypto = CryptoService::getInstance();
        $migrated = 0;
        foreach ($rows as $row) {
            $storedValue = (string) ($row->meta_value ?? '');
            if ($storedValue === '' || ($crypto->isEncrypted($storedValue) && !$crypto->needsReencryption($storedValue))) {
                continue;
            }

            if ($migrated >= $limit) {
                return false;
            }

            // Nicht entschlüsselbare Einträge (Schlüssel fehlt) werden protokolliert und nicht gezählt.
            if ($this->readSecret((int) $row->user_id, (string) $row->meta_key, $storedValue) !== null) {
                $migrated++;
            }
        }

        return true;
    }

    // ── Secret-Speicher ──────────────────────────────────────────────────────

    private function getUserSecret(int $userId): ?string
    {
        return $this->readSecret($userId, 'mfa_secret');
    }

    private function storeSecret(int $userId, string $metaKey, string $secret): void
    {
        $this->setUserMeta(
            $userId,
            $metaKey,
            CryptoService::getInstance()->encrypt($secret, CryptoService::CONTEXT_MFA, $userId . ':' . $metaKey)
        );
    }

    /**
     * Liest und entschlüsselt ein Secret. Klartext-Altwerte und Werte mit älterer
     * Schlüssel-ID werden dabei neu verschlüsselt (nur wenn der Eintrag unverändert ist).
     */
    private function readSecret(int $userId, string $metaKey, ?string $storedValue = null): ?string
    {
        $storedValue ??= $this->getUserMeta($userId, $metaKey);
        if ($storedValue === null || $storedValue === '') {
            return null;
        }

        $crypto = CryptoService::getInstance();
        $binding = $userId . ':' . $metaKey;

        if ($crypto->isEncrypted($storedValue)) {
            try {
                $secret = $crypto->decrypt($storedValue, CryptoService::CONTEXT_MFA, $binding);
            } catch (\Throwable $e) {
                Logger::instance()->withChannel('auth.mfa')->error('TOTP-Secret konnte nicht entschlüsselt werden.', [
                    'user_id' => $userId,
                    'meta_key' => $metaKey,
                    'exception' => $e,
                ]);
                return null;
            }

            if (!$crypto->needsReencryption($storedValue)) {
                return $secret;
            }
        } else {
            $secret = strtoupper(trim($storedValue));
            if (preg_match('/^[A-Z2-7]{16,128}=*$/', $secret) !== 1) {
                return null;
            }
        }

        try {
            $db = Database::instance();
            $db->execute(
                "UPDATE {$db->getPrefix()}user_meta SET meta_value = ? WHERE user_id = ? AND meta_key = ? AND meta_value = ?",
                [$crypto->encrypt($secret, CryptoService::CONTEXT_MFA, $binding), $userId, $metaKey, $storedValue]
            );
        } catch (\Throwable $e) {
            Logger::instance()->withChannel('auth.mfa')->warning('TOTP-Secret konnte nicht neu verschlüsselt werden.', [
                'user_id' => $userId,
                'meta_key' => $metaKey,
                'exception' => $e,
            ]);
        }

        return $secret;
    }

    // ── user_meta-Zugriff ────────────────────────────────────────────────────

    private function getUserMeta(int $userId, string $key): ?string
    {
        $db = Database::instance();
        $stmt = $db->prepare(
            "SELECT meta_value FROM {$db->getPrefix()}user_meta WHERE user_id = ? AND meta_key = ? LIMIT 1"
        );
        $stmt->execute([$userId, $key]);
        $row = $stmt->fetch();
        return $row ? (string)$row->meta_value : null;
    }

    private function setUserMeta(int $userId, string $key, string $value): void
    {
        $db = Database::instance();
        $db->execute(
            "INSERT INTO {$db->getPrefix()}user_meta (user_id, meta_key, meta_value)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)",
            [$userId, $key, $value]
        );
    }

    private function deleteUserMeta(int $userId, string $key): void
    {
        $db = Database::instance();
        $db->execute(
            "DELETE FROM {$db->getPrefix()}user_meta WHERE user_id = ? AND meta_key = ?",
            [$userId, $key]
        );
    }
}
