<?php
/**
 * JwtService – JWT-Token-Verwaltung für API-Authentifizierung
 *
 * Wrapper um firebase/php-jwt. Generiert und validiert JSON Web Tokens
 * für die API-Authentifizierung (Bearer-Token-Schema).
 *
 * Konfiguration:
 *   JWT_SECRET  (Pflicht)  – HMAC-Schlüssel für HS256. Fallback: gültiger AUTH_KEY
 *   JWT_TTL     (Optional) – Token-Lebensdauer in Sekunden (Standard: 3600)
 *   JWT_ISSUER  (Optional) – iss-Claim (Standard: SITE_URL)
 *
 * @package CMSv2\Core\Services
 */

declare(strict_types=1);

namespace CMS\Services;

if (!defined('ABSPATH')) {
    exit;
}

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\SignatureInvalidException;
use Firebase\JWT\BeforeValidException;

final class JwtService
{
    /** Algorithmus – HS256 (HMAC-SHA256) */
    private const ALGORITHM = 'HS256';

    /** Standard-Token-Lebensdauer: 1 Stunde */
    private const DEFAULT_TTL = 3600;

    private static ?self $instance = null;

    /** HMAC-Geheimschlüssel */
    private readonly string $secret;

    /** Token-Lebensdauer (Sekunden) */
    private readonly int $ttl;

    /** Issuer-Claim */
    private readonly string $issuer;

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    private function __construct()
    {
        $this->secret  = $this->resolveSecret();
        $this->ttl     = defined('JWT_TTL')    ? (int)JWT_TTL    : self::DEFAULT_TTL;
        $this->issuer  = defined('JWT_ISSUER') ? JWT_ISSUER      : SITE_URL;
    }

    private function resolveSecret(): string
    {
        foreach (['JWT_SECRET', 'AUTH_KEY'] as $constant) {
            if (!defined($constant)) {
                continue;
            }

            $value = trim((string) constant($constant));
            if ($value === '' || str_contains($value, 'REPLACE_VIA_INSTALLER') || str_contains($value, 'YOUR_')) {
                continue;
            }

            return $value;
        }

        \CMS\Logger::instance()->withChannel('jwt')->error(
            'JWT_SECRET/AUTH_KEY ist nicht sicher konfiguriert; JWT-Erstellung und -Validierung sind deaktiviert.'
        );

        return '';
    }

    // ── Öffentliche API ──────────────────────────────────────────────────────

    /**
     * Bearer-Authentifizierung für /api/* ist nur aktiv, wenn JWT_SECRET explizit gesetzt ist
     * (der AUTH_KEY-Fallback reicht zum Signieren, schaltet die API-Anmeldung aber nicht frei).
     */
    public static function isApiAuthEnabled(): bool
    {
        if (!defined('JWT_SECRET')) {
            return false;
        }

        $secret = trim((string) constant('JWT_SECRET'));

        return strlen($secret) >= 32 && !str_contains($secret, 'REPLACE_VIA_INSTALLER') && !str_contains($secret, 'YOUR_');
    }

    public function getTtl(): int
    {
        return $this->ttl;
    }

    /**
     * Access-Token für einen User generieren.
     *
     * @param int               $userId         Benutzer-ID (wird als 'sub'-Claim gesetzt)
     * @param array<string,mixed> $extraClaims  Zusätzliche Claims (z. B. 'role', 'scope')
     * @param int|null          $ttlOverride    Token-Lebensdauer überschreiben (Sekunden)
     * @return string Signierter JWT-String
     */
    public function generateToken(int $userId, array $extraClaims = [], ?int $ttlOverride = null): string
    {
        if ($this->secret === '') {
            throw new \RuntimeException('JWT signing secret is not configured.');
        }

        $now = time();
        $expiry = $ttlOverride ?? $this->ttl;

        $payload = array_merge($extraClaims, [
            'iss' => $this->issuer,
            'sub' => $userId,
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + $expiry,
            'jti' => bin2hex(random_bytes(16)),
        ]);

        return JWT::encode($payload, $this->secret, self::ALGORITHM);
    }

    /**
     * Refresh-Token mit längerer Lebensdauer generieren.
     *
     * @return string Signierter JWT-String (Standard: 30 Tage gültig)
     */
    public function generateRefreshToken(int $userId, array $extraClaims = []): string
    {
        return $this->generateToken($userId, array_merge($extraClaims, ['type' => 'refresh']), 86400 * 30);
    }

    /**
     * JWT-Token validieren und Payload zurückgeben.
     *
     * @return \stdClass|null Payload-Objekt bei Erfolg, null bei Fehler
     */
    public function validateToken(string $token): ?\stdClass
    {
        if ($this->secret === '') {
            return null;
        }

        try {
            $key = new Key($this->secret, self::ALGORITHM);
            $payload = JWT::decode($token, $key);

            // Issuer prüfen
            if (isset($payload->iss) && $payload->iss !== $this->issuer) {
                return null;
            }

            return $payload;
        } catch (ExpiredException | SignatureInvalidException | BeforeValidException) {
            return null;
        } catch (\Throwable $e) {
            \CMS\Logger::instance()->withChannel('jwt')->warning('JWT validation failed unexpectedly.', [
                'exception' => $e,
            ]);
            return null;
        }
    }

    /**
     * User-ID aus einem Token extrahieren (mit Validierung).
     */
    public function getUserIdFromToken(string $token): ?int
    {
        $payload = $this->validateToken($token);
        if ($payload === null || !isset($payload->sub)) {
            return null;
        }

        return (int)$payload->sub;
    }

    /**
     * Bearer-Token aus dem Authorization-Header extrahieren.
     *
     * @return string|null Token-String oder null
     */
    public static function extractBearerToken(): ?string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION']
            ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
            ?? '';

        if ($header === '' || !str_starts_with($header, 'Bearer ')) {
            return null;
        }

        $token = substr($header, 7);
        return $token !== '' ? $token : null;
    }

    /**
     * Prüft, ob ein Token ein Refresh-Token ist.
     */
    public function isRefreshToken(string $token): bool
    {
        $payload = $this->validateToken($token);
        return $payload !== null && ($payload->type ?? '') === 'refresh';
    }

    /**
     * Neues Access-Token aus einem gültigen Refresh-Token generieren.
     *
     * @return string|null Neues Access-Token oder null bei ungültigem Refresh-Token
     */
    public function refreshAccessToken(string $refreshToken): ?string
    {
        $payload = $this->validateToken($refreshToken);
        if ($payload === null || ($payload->type ?? '') !== 'refresh') {
            return null;
        }

        return $this->generateToken((int)$payload->sub);
    }
}
