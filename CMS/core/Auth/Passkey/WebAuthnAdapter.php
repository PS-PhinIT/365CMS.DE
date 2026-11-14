<?php
/**
 * WebAuthnAdapter – Passkey / FIDO2 Authentifizierung
 *
 * Wraps lbuchs/webauthn (v2.2.0) und stellt eine CMS-konforme API bereit.
 * Credentials werden in der Tabelle cms_passkey_credentials gespeichert.
 *
 * @package CMSv2\Core\Auth\Passkey
 * @see     https://github.com/lbuchs/WebAuthn
 */

declare(strict_types=1);

namespace CMS\Auth\Passkey;

if (!defined('ABSPATH')) {
    exit;
}

use CMS\Database;
use CMS\AuditLogger;
use lbuchs\WebAuthn\WebAuthn;
use lbuchs\WebAuthn\Binary\ByteBuffer;
use lbuchs\WebAuthn\WebAuthnException;

final class WebAuthnAdapter
{
    private static ?self $instance = null;
    private ?WebAuthn $webAuthn = null;
    private ?int $lastSignatureCounter = null;
    private ?bool $credentialsTableAvailable = null;
    /** @var array<string, bool>|null */
    private ?array $credentialsTableColumns = null;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    private function __construct() {}

    // ── Lazy-Init ────────────────────────────────────────────────────────────

    private function getWebAuthn(): WebAuthn
    {
        if ($this->webAuthn === null) {
            $rpId = parse_url(SITE_URL, PHP_URL_HOST) ?: 'localhost';
            $rpName = defined('SITE_NAME') ? SITE_NAME : '365CMS';
            $this->webAuthn = new WebAuthn($rpName, $rpId, null, true);
        }
        return $this->webAuthn;
    }

    // ── Registrierung ────────────────────────────────────────────────────────

    /**
     * Erstellt Registrierungs-Optionen für navigator.credentials.create().
     *
     * @param int    $userId          CMS-User-ID
     * @param string $userName        Benutzername
     * @param string $userDisplayName Anzeigename
     * @return array{options: \stdClass, challenge: string}
     */
    public function getRegistrationOptions(
        int $userId,
        string $userName,
        string $userDisplayName
    ): array {
        $wa = $this->getWebAuthn();

        // Bereits registrierte Credential-IDs, um doppelte Registrierung zu verhindern
        $existingIds = $this->getCredentialIdsForUser($userId);
        $excludeIds = array_map(
            fn(string $id) => new ByteBuffer(base64_decode($id)),
            $existingIds
        );

        $options = $wa->getCreateArgs(
            (string)$userId,
            $userName,
            $userDisplayName,
            60,                  // Timeout in Sekunden
            'preferred',         // Resident Key
            'preferred',         // User Verification
            null,                // Cross-Platform: beide erlaubt
            $excludeIds
        );

        $challenge = bin2hex($wa->getChallenge()->getBinaryString());

        return [
            'options'   => $options,
            'challenge' => $challenge,
        ];
    }

    /**
     * Verarbeitet die Registrierungsantwort vom Browser.
     *
     * @param int    $userId            CMS-User-ID
     * @param string $clientDataJSON    Roh-JSON vom Client
     * @param string $attestationObject Roh-Attestation vom Client
     * @param string $challenge         Hex-kodierte gespeicherte Challenge
     * @param string $credentialName    Benutzervergebener Name (z. B. "Mein iPhone")
     * @return bool|string true bei Erfolg, Fehlertext sonst
     */
    public function processRegistration(
        int $userId,
        string $clientDataJSON,
        string $attestationObject,
        string $challenge,
        string $credentialName = ''
    ): bool|string {
        try {
            $wa = $this->getWebAuthn();
            $challengeBuffer = new ByteBuffer(hex2bin($challenge));

            $data = $wa->processCreate(
                $clientDataJSON,
                $attestationObject,
                $challengeBuffer,
                false,  // requireUserVerification
                true    // requireUserPresent
            );

            $this->storeCredential($userId, $data, $credentialName);

            AuditLogger::instance()->log(
                AuditLogger::CAT_SECURITY,
                'passkey_registered',
                'Passkey registriert: ' . ($credentialName ?: 'Unbenannt'),
                'user',
                $userId
            );

            return true;

        } catch (WebAuthnException $e) {
            return 'WebAuthn-Fehler: ' . $e->getMessage();
        } catch (\Throwable $e) {
            return 'Passkey konnte nicht gespeichert werden: ' . $e->getMessage();
        }
    }

    // ── Authentifizierung ────────────────────────────────────────────────────

    /**
     * Erstellt Authentifizierungs-Optionen für navigator.credentials.get().
     *
     * @param array<string> $credentialIds Base64-kodierte Credential-IDs (leer = alle)
     * @return array{options: \stdClass, challenge: string}
     */
    public function getAuthenticationOptions(array $credentialIds = []): array
    {
        $wa = $this->getWebAuthn();

        $bufferIds = array_map(
            fn(string $id) => new ByteBuffer(base64_decode($id)),
            $credentialIds
        );

        $options = $wa->getGetArgs($bufferIds, 60);
        $challenge = bin2hex($wa->getChallenge()->getBinaryString());

        return [
            'options'   => $options,
            'challenge' => $challenge,
        ];
    }

    /**
     * Verarbeitet die Authentifizierungsantwort vom Browser.
     *
     * @return bool|string true bei Erfolg, Fehlertext sonst
     */
    public function processAuthentication(
        string $clientDataJSON,
        string $authenticatorData,
        string $signature,
        string $credentialPublicKey,
        string $challenge,
        int $prevSignCount
    ): bool|string {
        try {
            $wa = $this->getWebAuthn();
            $challengeBuffer = new ByteBuffer(hex2bin($challenge));

            $wa->processGet(
                $clientDataJSON,
                $authenticatorData,
                $signature,
                $credentialPublicKey,
                $challengeBuffer,
                $prevSignCount,
                false,  // requireUserVerification
                true    // requireUserPresent
            );

            $this->lastSignatureCounter = $wa->getSignatureCounter();

            return true;

        } catch (WebAuthnException $e) {
            return 'WebAuthn-Fehler: ' . $e->getMessage();
        } catch (\Throwable $e) {
            return 'Passkey-Authentifizierung fehlgeschlagen: ' . $e->getMessage();
        }
    }

    public function getLastSignatureCounter(): ?int
    {
        return $this->lastSignatureCounter;
    }

    // ── Credential-Verwaltung (DB) ───────────────────────────────────────────

    /**
     * Erstellt die passkey_credentials-Tabelle, falls nicht vorhanden.
     */
    public function createTable(): void
    {
        $db  = Database::instance();
        $pdo = $db->getPdo();
        $pfx = $db->getPrefix();

        $pdo->exec("CREATE TABLE IF NOT EXISTS {$pfx}passkey_credentials (
            id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id         INT UNSIGNED NOT NULL,
            credential_id   VARCHAR(512) NOT NULL,
            public_key      TEXT         NOT NULL,
            sign_count      INT UNSIGNED NOT NULL DEFAULT 0,
            aaguid          VARCHAR(64)  DEFAULT NULL,
            attestation_fmt VARCHAR(32)  DEFAULT NULL,
            name            VARCHAR(128) DEFAULT '',
            created_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
            last_used_at    TIMESTAMP    NULL DEFAULT NULL,
            user_verified   TINYINT(1)   NULL DEFAULT NULL,
            INDEX idx_user      (user_id),
            UNIQUE INDEX idx_cred_id (credential_id(255))
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    private function storeCredential(int $userId, \stdClass $data, string $name): void
    {
        if (!$this->ensureCredentialsTable()) {
            throw new \RuntimeException('Die Passkey-Speichertabelle ist nicht verfügbar.');
        }

        $db = Database::instance();
        $credentialId = $this->extractBinaryString($data->credentialId ?? null, 'credentialId');
        $aaguid = $this->normalizeAaguid($data->AAGUID ?? null);

        $row = [
            'user_id'         => $userId,
            'credential_id'   => base64_encode($credentialId),
            'public_key'      => $data->credentialPublicKey,
            'sign_count'      => $data->signatureCounter ?? 0,
            'aaguid'          => $aaguid,
            'attestation_fmt' => $data->attestationFormat ?? null,
            'name'            => $name,
        ];
        // 4.1.0: User Verification bei der Registrierung (lbuchs/WebAuthn: $data->userVerified) für den MFA-Zwang.
        if (isset($this->credentialsTableColumns['user_verified'])) {
            $row['user_verified'] = !empty($data->userVerified) ? 1 : 0;
        }
        $db->insert('passkey_credentials', $row);
    }

    /**
     * Credential anhand der Credential-ID finden (Base64-kodiert).
     *
     * @return array<string, mixed>|null
     */
    public function findCredentialById(string $credentialId): ?array
    {
        if (!$this->ensureCredentialsTable()) {
            return null;
        }

        $db = Database::instance();
        $normalizedId = $this->normalizeCredentialId($credentialId);
        $stmt = $db->prepare(
            "SELECT * FROM {$db->getPrefix()}passkey_credentials WHERE credential_id = ? LIMIT 1"
        );
        $stmt->execute([$normalizedId]);
        $row = $stmt->fetch();
        return $row ? (array)$row : null;
    }

    /**
     * Alle Credential-IDs eines Users (Base64-kodiert).
     *
     * @return array<string>
     */
    public function getCredentialIdsForUser(int $userId): array
    {
        if (!$this->ensureCredentialsTable()) {
            return [];
        }

        $db = Database::instance();
        $stmt = $db->prepare(
            "SELECT credential_id FROM {$db->getPrefix()}passkey_credentials WHERE user_id = ?"
        );
        $stmt->execute([$userId]);
        return array_column($stmt->fetchAll(\PDO::FETCH_ASSOC), 'credential_id');
    }

    /**
     * Alle Passkeys eines Users für die Übersicht.
     *
     * @return array<array<string, mixed>>
     */
    public function getCredentialsForUser(int $userId): array
    {
        if (!$this->ensureCredentialsTable()) {
            return [];
        }

        $db = Database::instance();
        $stmt = $db->prepare(
            "SELECT id, name, attestation_fmt, created_at, last_used_at
             FROM {$db->getPrefix()}passkey_credentials
             WHERE user_id = ?
             ORDER BY created_at DESC"
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Sign-Counter nach erfolgreicher Authentifizierung aktualisieren.
     */
    public function updateSignCount(int $credentialDbId, int $newCount): void
    {
        if (!$this->ensureCredentialsTable()) {
            return;
        }

        $db = Database::instance();
        $db->update('passkey_credentials', [
            'sign_count'   => $newCount,
            'last_used_at' => date('Y-m-d H:i:s'),
        ], ['id' => $credentialDbId]);
    }

    /**
     * Einzelnen Passkey löschen.
     */
    public function deleteCredential(int $credentialDbId, int $userId): bool
    {
        if (!$this->ensureCredentialsTable()) {
            return false;
        }

        $db = Database::instance();
        $stmt = $db->prepare(
            "DELETE FROM {$db->getPrefix()}passkey_credentials WHERE id = ? AND user_id = ?"
        );
        $stmt->execute([$credentialDbId, $userId]);
        return $stmt->rowCount() > 0;
    }

    public function isAvailable(): bool
    {
        return $this->ensureCredentialsTable();
    }

    /**
     * 4.1.0: Nach einer Anmeldung mit gesetztem UV-Bit (Signatur bereits geprüft) gilt der Passkey als
     * Faktor mit User Verification – so zählen auch Bestandspasskeys (user_verified = NULL) beim MFA-Zwang.
     */
    public function markUserVerified(int $credentialDbId): void
    {
        if ($credentialDbId <= 0 || !$this->ensureCredentialsTable() || !isset($this->credentialsTableColumns['user_verified'])) {
            return;
        }

        Database::instance()->update('passkey_credentials', ['user_verified' => 1], ['id' => $credentialDbId]);
    }

    /**
     * Anzahl der Passkeys mit User Verification eines Kontos (MFA-Zwang).
     */
    public function countUserVerifiedCredentials(int $userId): int
    {
        if ($userId <= 0 || !$this->ensureCredentialsTable() || !isset($this->credentialsTableColumns['user_verified'])) {
            return 0;
        }
        $db = Database::instance();

        return (int) $db->get_var(
            "SELECT COUNT(*) FROM {$db->getPrefix()}passkey_credentials WHERE user_id = ? AND user_verified = 1",
            [$userId]
        );
    }

    private function ensureCredentialsTable(): bool
    {
        if ($this->credentialsTableAvailable !== null) {
            return $this->credentialsTableAvailable;
        }

        $db = Database::instance();
        $table = $db->getPrefix() . 'passkey_credentials';

        try {
            $stmt = $db->getPdo()->query("SHOW TABLES LIKE " . $db->getPdo()->quote($table));
            if ($stmt !== false && $stmt->fetchColumn() !== false) {
                $this->credentialsTableAvailable = $this->synchronizeCredentialsSchema($table);
                return $this->credentialsTableAvailable;
            }

            $this->createTable();

            $stmt = $db->getPdo()->query("SHOW TABLES LIKE " . $db->getPdo()->quote($table));
            $tableExists = $stmt !== false && $stmt->fetchColumn() !== false;
            $this->credentialsTableAvailable = $tableExists && $this->synchronizeCredentialsSchema($table);

            if (!$this->credentialsTableAvailable) {
                \CMS\Logger::instance()->withChannel('passkey')->warning('Passkey credentials table could not be verified after creation.', [
                    'table' => $table,
                ]);
            }
        } catch (\Throwable $e) {
            $this->credentialsTableAvailable = false;
            \CMS\Logger::instance()->withChannel('passkey')->warning('Passkey credentials table is unavailable.', [
                'table' => $table,
                'exception' => $e,
            ]);
        }

        return $this->credentialsTableAvailable;
    }

    private function synchronizeCredentialsSchema(string $table): bool
    {
        try {
            $columns = $this->getCredentialsTableColumns($table);
            $alterStatements = [];

            $requiredColumns = [
                'credential_id' => "ADD COLUMN credential_id VARCHAR(512) NOT NULL AFTER user_id",
                'public_key' => "ADD COLUMN public_key TEXT NOT NULL AFTER credential_id",
                'sign_count' => "ADD COLUMN sign_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER public_key",
                'aaguid' => "ADD COLUMN aaguid VARCHAR(64) DEFAULT NULL AFTER sign_count",
                'attestation_fmt' => "ADD COLUMN attestation_fmt VARCHAR(32) DEFAULT NULL AFTER aaguid",
                'name' => "ADD COLUMN name VARCHAR(128) DEFAULT '' AFTER attestation_fmt",
                'created_at' => "ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER name",
                'last_used_at' => "ADD COLUMN last_used_at TIMESTAMP NULL DEFAULT NULL AFTER created_at",
                // 4.1.0: UV-Flag (PIN/Biometrie) – zählt beim MFA-Zwang nur mit 1.
                'user_verified' => "ADD COLUMN user_verified TINYINT(1) NULL DEFAULT NULL AFTER last_used_at",
            ];

            foreach ($requiredColumns as $column => $ddl) {
                if (!isset($columns[$column])) {
                    $alterStatements[] = $ddl;
                }
            }

            if ($alterStatements !== []) {
                $db = Database::instance();
                $db->getPdo()->exec(
                    'ALTER TABLE ' . $table . "\n    " . implode(",\n    ", $alterStatements)
                );
                $columns = $this->getCredentialsTableColumns($table);
            }

            $this->credentialsTableColumns = $columns;

            return isset($columns['credential_id'], $columns['public_key'], $columns['sign_count']);
        } catch (\Throwable $e) {
            \CMS\Logger::instance()->withChannel('passkey')->warning('Passkey credentials schema synchronization failed.', [
                'table' => $table,
                'exception' => $e,
            ]);
            $this->credentialsTableColumns = null;
            return false;
        }
    }

    /**
     * @return array<string, bool>
     */
    private function getCredentialsTableColumns(string $table): array
    {
        $db = Database::instance();
        $stmt = $db->getPdo()->query('SHOW COLUMNS FROM ' . $table);
        if ($stmt === false) {
            return [];
        }

        $columns = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $column) {
            $name = (string)($column['Field'] ?? '');
            if ($name !== '') {
                $columns[$name] = true;
            }
        }

        return $columns;
    }

    private function normalizeCredentialId(string $credentialId): string
    {
        $credentialId = trim($credentialId);
        if ($credentialId === '') {
            return '';
        }

        $binary = base64_decode(strtr($credentialId, '-_', '+/') . str_repeat('=', (4 - strlen($credentialId) % 4) % 4), true);
        if ($binary === false) {
            return $credentialId;
        }

        return base64_encode($binary);
    }

    private function extractBinaryString(mixed $value, string $fieldName): string
    {
        if ($value instanceof ByteBuffer) {
            return $value->getBinaryString();
        }

        if (is_string($value) && $value !== '') {
            return $value;
        }

        throw new \RuntimeException('Ungültiger WebAuthn-Wert für ' . $fieldName . '.');
    }

    private function normalizeAaguid(mixed $value): ?string
    {
        if ($value instanceof ByteBuffer) {
            $value = $value->getBinaryString();
        }

        if (!is_string($value) || $value === '') {
            return null;
        }

        if (preg_match('/^[a-f0-9-]{16,64}$/i', $value)) {
            return strtolower($value);
        }

        return strtolower(bin2hex($value));
    }
}
