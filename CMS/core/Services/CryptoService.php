<?php
/**
 * Verschlüsselung gespeicherter Geheimnisse (AEAD mit Schlüsselring).
 *
 * Werte:  enc:v2:<alg>:<keyId>:<base64url(nonce|ciphertext)>
 *         alg x = XChaCha20-Poly1305 (libsodium), g = AES-256-GCM (OpenSSL)
 * Dateien: Magic „CMSENC2“, Schlüssel-ID, libsodium-secretstream (XChaCha20-Poly1305) in 64-KiB-Blöcken.
 *
 * Die Schlüssel liegen nie in der Datenbank, sondern in config/app.php:
 *   CMS_ENCRYPTION_KEY            optional: eigener Hauptschlüssel (z. B. 64 Hex-Zeichen)
 *   CMS_ENCRYPTION_KEYS_PREVIOUS  optional: frühere Schlüssel, durch Komma getrennt (Rotation)
 * Ohne CMS_ENCRYPTION_KEY wird der Hauptschlüssel aus AUTH_KEY und SECURE_AUTH_KEY abgeleitet.
 * Ist CMS_ENCRYPTION_KEY gesetzt, bleibt dieser abgeleitete Schlüssel zum Entschlüsseln im Ring.
 * Werte mit älterer Schlüssel-ID werden beim Lesen bzw. einmal je Schlüsselstand neu verschlüsselt.
 *
 * @package CMSv2\Core\Services
 */

declare(strict_types=1);

namespace CMS\Services;

if (!defined('ABSPATH')) {
    exit;
}

final class CryptoService
{
    public const PREFIX = 'enc:v2:';
    public const CONTEXT_SETTINGS = 'settings';
    public const CONTEXT_MFA = 'mfa';
    public const CONTEXT_BACKUP = 'backup';

    private const ALG_XCHACHA = 'x';
    private const ALG_AES_GCM = 'g';
    private const GCM_IV_LENGTH = 12;
    private const GCM_TAG_LENGTH = 16;
    private const FILE_MAGIC = "CMSENC2\0";
    private const FILE_CHUNK_SIZE = 65536;

    private static ?self $instance = null;

    /** @var array<string, string> Schlüssel-ID => 32-Byte-Hauptschlüssel, erster Eintrag = aktueller Schlüssel */
    private array $keys = [];

    private string $currentKeyId;

    /** Schlüssel des Altformats der Einstellungen: sha256(AUTH_KEY|SECURE_AUTH_KEY), exakt wie vor 4.0.0. */
    private string $legacySettingsKey;

    /** @var list<string> Schlüssel aus CMS_ENCRYPTION_KEYS_PREVIOUS (für Altwerte nach einem AUTH_KEY-Wechsel) */
    private array $previousKeys = [];

    private bool $dedicatedKey;

    private bool $weakKey;

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    private function __construct()
    {
        $dedicated = $this->constantString('CMS_ENCRYPTION_KEY');
        $this->dedicatedKey = $dedicated !== '' && !$this->isPlaceholder($dedicated);

        $authKey = $this->constantString('AUTH_KEY');
        $secureAuthKey = $this->constantString('SECURE_AUTH_KEY');
        $this->weakKey = !$this->dedicatedKey
            && ($authKey === '' || $secureAuthKey === '' || $this->isPlaceholder($authKey) || $this->isPlaceholder($secureAuthKey));

        $materials = [];
        if ($this->dedicatedKey) {
            $materials[] = $dedicated;
        }
        // Bisheriger, aus AUTH_KEY/SECURE_AUTH_KEY abgeleiteter Schlüssel.
        $materials[] = $authKey . '|' . $secureAuthKey;
        foreach ($this->previousMaterials() as $material) {
            $materials[] = $material;
            $this->previousKeys[] = hash('sha256', $material, true);
        }

        foreach ($materials as $material) {
            $master = hash('sha256', $material, true);
            $this->keys[$this->keyIdFor($master)] ??= $master;
        }

        $this->currentKeyId = (string) array_key_first($this->keys);
        $this->legacySettingsKey = hash(
            'sha256',
            (defined('AUTH_KEY') ? (string) constant('AUTH_KEY') : '') . '|' . (defined('SECURE_AUTH_KEY') ? (string) constant('SECURE_AUTH_KEY') : ''),
            true
        );
    }

    public function currentKeyId(): string
    {
        return $this->currentKeyId;
    }

    /**
     * Kennung des gesamten Schlüsselbunds (aktueller und frühere Schlüssel, verfügbares Verfahren).
     * Ändert sie sich, werden gespeicherte Geheimnisse erneut auf Migrationsbedarf geprüft.
     */
    public function keyRingId(): string
    {
        $algorithm = $this->hasSodiumAead() ? self::ALG_XCHACHA : self::ALG_AES_GCM;

        return substr(hash('sha256', implode(',', array_keys($this->keys)) . '|' . $algorithm), 0, 12);
    }

    /**
     * true, wenn ein eigener CMS_ENCRYPTION_KEY gesetzt ist (statt der Ableitung aus AUTH_KEY/SECURE_AUTH_KEY).
     */
    public function usesDedicatedKey(): bool
    {
        return $this->dedicatedKey;
    }

    /**
     * true, wenn weder CMS_ENCRYPTION_KEY noch echte AUTH_KEY/SECURE_AUTH_KEY-Werte konfiguriert sind.
     */
    public function hasWeakKey(): bool
    {
        return $this->weakKey;
    }

    public function isAvailable(): bool
    {
        return $this->hasSodiumAead() || $this->hasOpenSslGcm();
    }

    public function isEncrypted(?string $value): bool
    {
        return is_string($value) && str_starts_with($value, self::PREFIX);
    }

    /**
     * true, wenn ein gespeicherter Wert nicht mit dem aktuellen Schlüssel im aktuellen Format vorliegt.
     */
    public function needsReencryption(string $value): bool
    {
        $parts = $this->parse($value);
        if ($parts === null) {
            return true;
        }

        return $parts['key_id'] !== $this->currentKeyId
            || ($parts['alg'] === self::ALG_AES_GCM && $this->hasSodiumAead());
    }

    /**
     * Verschlüsselt einen Wert. $binding (z. B. Optionsname oder Benutzer-ID) wird als
     * Associated Data authentifiziert: ein Chiffrat lässt sich nicht in eine andere Zeile kopieren.
     */
    public function encrypt(string $plainText, string $context, string $binding = ''): string
    {
        $keyId = $this->currentKeyId;
        $key = $this->subKey($this->keys[$keyId], $context);

        if ($this->hasSodiumAead()) {
            $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
            $cipherText = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
                $plainText,
                $this->associatedData(self::ALG_XCHACHA, $keyId, $context, $binding),
                $nonce,
                $key
            );
            sodium_memzero($key);

            return self::PREFIX . self::ALG_XCHACHA . ':' . $keyId . ':' . $this->base64UrlEncode($nonce . $cipherText);
        }

        if (!$this->hasOpenSslGcm()) {
            throw new \RuntimeException('Für die Verschlüsselung wird libsodium oder OpenSSL (AES-256-GCM) benötigt.');
        }

        $iv = random_bytes(self::GCM_IV_LENGTH);
        $tag = '';
        $cipherText = openssl_encrypt(
            $plainText,
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            $this->associatedData(self::ALG_AES_GCM, $keyId, $context, $binding),
            self::GCM_TAG_LENGTH
        );
        if (!is_string($cipherText) || strlen($tag) !== self::GCM_TAG_LENGTH) {
            throw new \RuntimeException('Wert konnte nicht verschlüsselt werden.');
        }

        return self::PREFIX . self::ALG_AES_GCM . ':' . $keyId . ':' . $this->base64UrlEncode($iv . $tag . $cipherText);
    }

    /**
     * @throws \RuntimeException wenn Format, Schlüssel oder Authentifizierung nicht passen
     */
    public function decrypt(string $value, string $context, string $binding = ''): string
    {
        $parts = $this->parse($value);
        if ($parts === null) {
            throw new \RuntimeException('Verschlüsselter Wert hat ein ungültiges Format.');
        }

        $master = $this->keys[$parts['key_id']] ?? null;
        if ($master === null) {
            throw new \RuntimeException('Schlüssel ' . $parts['key_id'] . ' ist nicht konfiguriert (CMS_ENCRYPTION_KEY / CMS_ENCRYPTION_KEYS_PREVIOUS).');
        }

        $key = $this->subKey($master, $context);
        $associatedData = $this->associatedData($parts['alg'], $parts['key_id'], $context, $binding);
        $raw = $parts['payload'];

        if ($parts['alg'] === self::ALG_XCHACHA) {
            if (!$this->hasSodiumAead()) {
                throw new \RuntimeException('libsodium ist zum Entschlüsseln dieses Werts erforderlich.');
            }
            $nonceLength = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
            if (strlen($raw) <= $nonceLength) {
                throw new \RuntimeException('Verschlüsselter Wert ist unvollständig.');
            }
            $plainText = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
                substr($raw, $nonceLength),
                $associatedData,
                substr($raw, 0, $nonceLength),
                $key
            );
            sodium_memzero($key);
        } else {
            if (!$this->hasOpenSslGcm()) {
                throw new \RuntimeException('OpenSSL (AES-256-GCM) ist zum Entschlüsseln dieses Werts erforderlich.');
            }
            if (strlen($raw) < self::GCM_IV_LENGTH + self::GCM_TAG_LENGTH) {
                throw new \RuntimeException('Verschlüsselter Wert ist unvollständig.');
            }
            $plainText = openssl_decrypt(
                substr($raw, self::GCM_IV_LENGTH + self::GCM_TAG_LENGTH),
                'aes-256-gcm',
                $key,
                OPENSSL_RAW_DATA,
                substr($raw, 0, self::GCM_IV_LENGTH),
                substr($raw, self::GCM_IV_LENGTH, self::GCM_TAG_LENGTH),
                $associatedData
            );
        }

        if (!is_string($plainText)) {
            throw new \RuntimeException('Wert konnte nicht entschlüsselt werden (falscher Schlüssel oder veränderte Daten).');
        }

        return $plainText;
    }

    /**
     * Entschlüsselt das vor 4.0.0 genutzte Einstellungsformat (enc:<base64(iv|AES-256-CBC)>, ohne MAC).
     * Nur noch zum Migrieren alter Werte; neue Werte werden ausschließlich mit encrypt() geschrieben.
     */
    public function decryptLegacySettingsValue(string $payload): string
    {
        $raw = base64_decode($payload, true);
        $ivLength = 16;
        if (!is_string($raw) || strlen($raw) <= $ivLength || !function_exists('openssl_decrypt')) {
            throw new \RuntimeException('Alter verschlüsselter Settings-Wert ist ungültig.');
        }

        $iv = substr($raw, 0, $ivLength);
        $cipherText = substr($raw, $ivLength);

        // CBC ohne MAC: Ein falscher Schlüssel ergibt in rund 0,4 % der Fälle gültiges Padding und damit
        // Datenmüll, der beim Migrieren dauerhaft gespeichert würde. Deshalb nie CMS_ENCRYPTION_KEY
        // (damit wurde das Altformat nie geschrieben) und frühere Schlüssel nur bei eindeutigem Treffer.
        $primary = $this->decryptLegacyCbc($cipherText, $this->legacySettingsKey, $iv);
        if ($primary !== null && ($this->previousKeys === [] || $this->isPlausibleLegacyPlainText($primary))) {
            return $primary;
        }

        $candidates = [];
        foreach ($this->previousKeys as $key) {
            $plainText = $this->decryptLegacyCbc($cipherText, $key, $iv);
            if ($plainText !== null && $this->isPlausibleLegacyPlainText($plainText) && !in_array($plainText, $candidates, true)) {
                $candidates[] = $plainText;
            }
        }

        if (count($candidates) === 1) {
            return $candidates[0];
        }
        if ($candidates === [] && $primary !== null) {
            return $primary;
        }

        throw new \RuntimeException('Alter verschlüsselter Settings-Wert konnte nicht eindeutig entschlüsselt werden.');
    }

    private function decryptLegacyCbc(string $cipherText, string $key, string $iv): ?string
    {
        $plainText = openssl_decrypt($cipherText, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);

        return is_string($plainText) ? $plainText : null;
    }

    /**
     * Einstellungswerte sind Text bzw. „json:“-kodiert; Datenmüll aus einem falschen Schlüssel
     * ist fast nie gültiges UTF-8 ohne Steuerzeichen.
     */
    private function isPlausibleLegacyPlainText(string $plainText): bool
    {
        return preg_match('//u', $plainText) === 1
            && preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $plainText) !== 1;
    }

    // ── Dateien (Backups) ────────────────────────────────────────────────────

    public function canEncryptFiles(): bool
    {
        return function_exists('sodium_crypto_secretstream_xchacha20poly1305_init_push');
    }

    public function isEncryptedFile(string $path): bool
    {
        return $this->fileKeyId($path) !== null;
    }

    /**
     * Schlüssel-ID einer mit encryptFile() erzeugten Datei, null bei unverschlüsselten oder unlesbaren Dateien.
     */
    public function fileKeyId(string $path): ?string
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return null;
        }
        $prefix = (string) fread($handle, strlen(self::FILE_MAGIC) + 8);
        fclose($handle);

        if (!str_starts_with($prefix, self::FILE_MAGIC)) {
            return null;
        }

        $keyId = substr($prefix, strlen(self::FILE_MAGIC));

        return preg_match('/^[a-f0-9]{8}$/', $keyId) === 1 ? $keyId : null;
    }

    /**
     * true, wenn der Schlüssel mit dieser ID im Schlüsselring (aktuell oder CMS_ENCRYPTION_KEYS_PREVIOUS) liegt.
     */
    public function hasKey(string $keyId): bool
    {
        return isset($this->keys[$keyId]);
    }

    /**
     * Verschlüsselt $source nach $target (Stream, auch für große Archive).
     */
    public function encryptFile(string $source, string $target, string $context = self::CONTEXT_BACKUP): void
    {
        if (!$this->canEncryptFiles()) {
            throw new \RuntimeException('libsodium (secretstream) ist für verschlüsselte Backups erforderlich.');
        }

        $in = @fopen($source, 'rb');
        if ($in === false) {
            throw new \RuntimeException('Quelldatei kann nicht gelesen werden.');
        }
        $out = @fopen($target, 'wb');
        if ($out === false) {
            fclose($in);
            throw new \RuntimeException('Zieldatei kann nicht geschrieben werden.');
        }

        $keyId = $this->currentKeyId;
        $key = $this->subKey($this->keys[$keyId], 'file:' . $context);
        $associatedData = $this->associatedData('s', $keyId, 'file:' . $context, '');

        try {
            [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
            $this->writeAll($out, self::FILE_MAGIC . $keyId . $header);

            $chunk = (string) fread($in, self::FILE_CHUNK_SIZE);
            while (true) {
                $next = feof($in) ? '' : (string) fread($in, self::FILE_CHUNK_SIZE);
                $isLast = $next === '' && feof($in);
                $encrypted = sodium_crypto_secretstream_xchacha20poly1305_push(
                    $state,
                    $chunk,
                    $associatedData,
                    $isLast ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE
                );
                $this->writeAll($out, pack('N', strlen($encrypted)) . $encrypted);
                if ($isLast) {
                    break;
                }
                $chunk = $next;
            }
        } finally {
            sodium_memzero($key);
            fclose($in);
            fclose($out);
        }
    }

    /**
     * Entschlüsselt eine mit encryptFile() erzeugte Datei; bricht bei Manipulation oder Kürzung ab.
     */
    public function decryptFile(string $source, string $target, string $context = self::CONTEXT_BACKUP): void
    {
        if (!$this->canEncryptFiles()) {
            throw new \RuntimeException('libsodium (secretstream) ist zum Entschlüsseln von Backups erforderlich.');
        }

        $in = @fopen($source, 'rb');
        if ($in === false) {
            throw new \RuntimeException('Verschlüsselte Datei kann nicht gelesen werden.');
        }

        $headerLength = strlen(self::FILE_MAGIC) + 8 + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES;
        $prefix = (string) fread($in, $headerLength);
        if (strlen($prefix) !== $headerLength || !str_starts_with($prefix, self::FILE_MAGIC)) {
            fclose($in);
            throw new \RuntimeException('Datei ist kein verschlüsseltes 365CMS-Backup.');
        }

        $keyId = substr($prefix, strlen(self::FILE_MAGIC), 8);
        $master = $this->keys[$keyId] ?? null;
        if ($master === null) {
            fclose($in);
            throw new \RuntimeException('Schlüssel ' . $keyId . ' für dieses Backup ist nicht konfiguriert.');
        }

        $out = @fopen($target, 'wb');
        if ($out === false) {
            fclose($in);
            throw new \RuntimeException('Zieldatei kann nicht geschrieben werden.');
        }

        $key = $this->subKey($master, 'file:' . $context);
        $associatedData = $this->associatedData('s', $keyId, 'file:' . $context, '');
        $complete = false;

        try {
            $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull(substr($prefix, strlen(self::FILE_MAGIC) + 8), $key);
            $maxChunk = self::FILE_CHUNK_SIZE + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES;

            while (!$complete) {
                $lengthBytes = (string) fread($in, 4);
                if (strlen($lengthBytes) !== 4) {
                    break;
                }
                $length = (int) (unpack('N', $lengthBytes)[1] ?? 0);
                if ($length < SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES || $length > $maxChunk) {
                    break;
                }
                $encrypted = $this->readExactly($in, $length);
                $result = $encrypted !== null
                    ? sodium_crypto_secretstream_xchacha20poly1305_pull($state, $encrypted, $associatedData)
                    : false;
                if (!is_array($result)) {
                    break;
                }
                [$plain, $tag] = $result;
                $this->writeAll($out, (string) $plain);
                $complete = $tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL;
            }

            $trailing = $complete ? fread($in, 1) : '';
            if (is_string($trailing) && $trailing !== '') {
                $complete = false;
            }
        } finally {
            sodium_memzero($key);
            fclose($in);
            fclose($out);
        }

        if (!$complete) {
            @unlink($target);
            throw new \RuntimeException('Verschlüsseltes Backup ist beschädigt, gekürzt oder wurde verändert.');
        }
    }

    // ── intern ───────────────────────────────────────────────────────────────

    /**
     * @return array{alg: string, key_id: string, payload: string}|null
     */
    private function parse(string $value): ?array
    {
        if (!str_starts_with($value, self::PREFIX)) {
            return null;
        }

        $parts = explode(':', substr($value, strlen(self::PREFIX)), 3);
        if (count($parts) !== 3
            || !in_array($parts[0], [self::ALG_XCHACHA, self::ALG_AES_GCM], true)
            || preg_match('/^[a-f0-9]{8}$/', $parts[1]) !== 1
        ) {
            return null;
        }

        $payload = $this->base64UrlDecode($parts[2]);
        if ($payload === null || $payload === '') {
            return null;
        }

        return ['alg' => $parts[0], 'key_id' => $parts[1], 'payload' => $payload];
    }

    private function subKey(string $master, string $context): string
    {
        return hash_hkdf('sha256', $master, 32, '365cms:' . $context, '365cms-secrets-v2');
    }

    private function keyIdFor(string $master): string
    {
        return substr(hash('sha256', '365cms-key-id|' . $master), 0, 8);
    }

    private function associatedData(string $alg, string $keyId, string $context, string $binding): string
    {
        return '365cms:v2:' . $alg . ':' . $keyId . ':' . $context . ':' . $binding;
    }

    private function hasSodiumAead(): bool
    {
        return function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt');
    }

    private function hasOpenSslGcm(): bool
    {
        return function_exists('openssl_encrypt') && in_array('aes-256-gcm', openssl_get_cipher_methods(), true);
    }

    /**
     * @return list<string>
     */
    private function previousMaterials(): array
    {
        if (!defined('CMS_ENCRYPTION_KEYS_PREVIOUS')) {
            return [];
        }

        $raw = constant('CMS_ENCRYPTION_KEYS_PREVIOUS');
        $values = is_array($raw) ? $raw : explode(',', (string) $raw);
        $materials = [];
        foreach ($values as $value) {
            $value = trim((string) $value);
            if ($value !== '' && !$this->isPlaceholder($value)) {
                $materials[] = $value;
            }
        }

        return $materials;
    }

    private function constantString(string $name): string
    {
        if (!defined($name)) {
            return '';
        }

        $value = constant($name);

        return is_scalar($value) ? trim((string) $value) : '';
    }

    private function isPlaceholder(string $value): bool
    {
        return str_contains($value, 'REPLACE_VIA_INSTALLER') || str_starts_with($value, 'YOUR_');
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): ?string
    {
        if (preg_match('/^[A-Za-z0-9_-]+$/', $value) !== 1) {
            return null;
        }

        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return is_string($decoded) ? $decoded : null;
    }

    /**
     * @param resource $handle
     */
    private function writeAll($handle, string $data): void
    {
        $length = strlen($data);
        $written = 0;
        while ($written < $length) {
            $bytes = fwrite($handle, substr($data, $written));
            if ($bytes === false || $bytes === 0) {
                throw new \RuntimeException('Verschlüsselte Daten konnten nicht vollständig geschrieben werden.');
            }
            $written += $bytes;
        }
    }

    /**
     * @param resource $handle
     */
    private function readExactly($handle, int $length): ?string
    {
        $data = '';
        while (strlen($data) < $length) {
            $chunk = fread($handle, $length - strlen($data));
            if ($chunk === false || $chunk === '') {
                return null;
            }
            $data .= $chunk;
        }

        return $data;
    }
}
