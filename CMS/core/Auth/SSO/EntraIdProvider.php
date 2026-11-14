<?php
/**
 * Microsoft Entra ID als OpenID-Connect-Anbieter (4.1.0, Bereich 1c).
 *
 * - Endpunkte nur unter `https://login.microsoftonline.com` (fest verdrahtet, Aufrufe über `CMS\Http\Client` mit
 *   SSRF-Schutz und Größenlimit).
 * - ID-Token: nur RS256 mit einem Schlüssel aus dem JWKS des Tenants (`alg=none`, HS* und fremde Algorithmen werden
 *   vor der Prüfung abgelehnt), `iss` exakt `https://login.microsoftonline.com/{tid}/v2.0`, `aud` = Client-ID,
 *   `exp`/`nbf`/`iat` mit 120 s Toleranz, `nonce` aus der Sitzung, `tid`/`oid` im GUID-Format, `ver` = 2.0.
 * - JWKS-Cache 24 h; bei unbekanntem `kid` wird höchstens einmal je 5 Minuten neu geladen.
 * - Tokens werden nie gespeichert oder protokolliert; Fehler von Entra nur gekürzt.
 *
 * Netz, Uhr und Cache sind für `TESTS/sso-entra` austauschbar (Konstruktor-Parameter `$deps`).
 *
 * @package CMSv2\Core\Auth\SSO
 */

declare(strict_types=1);

namespace CMS\Auth\SSO;

use CMS\Http\Client;
use CMS\Services\SettingsService;
use Firebase\JWT\BeforeValidException;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Firebase\JWT\SignatureInvalidException;

if (!defined('ABSPATH')) {
    exit;
}

final class EntraIdProvider implements OidcProviderInterface
{
    public const HOST = 'login.microsoftonline.com';
    public const LEEWAY = 120;
    public const JWKS_TTL = 86400;
    public const JWKS_REFRESH_MIN_INTERVAL = 300;
    public const MAX_RESPONSE_BYTES = 262144;

    /** `amr`-Werte, die einen zweiten Faktor belegen (Entra: MFA, Windows Hello, FIDO2, schlüsselbasierte Anmeldung). */
    public const MFA_AMR_VALUES = ['mfa', 'ngcmfa', 'fido', 'rsa'];

    private const JWKS_CACHE_KEY = 'jwks_cache';

    /** @var array<string, mixed> */
    private array $settings;

    /** @var \Closure(string, string, array<string, string>): array{success:bool,status:int,body:string} */
    private \Closure $http;

    /** @var \Closure(): int */
    private \Closure $clock;

    /** @var \Closure(): ?array<string, mixed> */
    private \Closure $cacheGet;

    /** @var \Closure(array<string, mixed>): void */
    private \Closure $cacheSet;

    /**
     * @param array<string, mixed> $settings Ergebnis von EntraSettings::load()/normalize() plus `client_secret`
     * @param array<string, \Closure> $deps http, clock, cacheGet, cacheSet
     */
    public function __construct(array $settings, array $deps = [])
    {
        $this->settings = $settings;
        $this->http = $deps['http'] ?? static function (string $method, string $url, array $form = []): array {
            $options = [
                'timeout' => 10,
                'connectTimeout' => 5,
                'maxBytes' => self::MAX_RESPONSE_BYTES,
                'userAgent' => '365CMS-SSO/1.0',
                'headers' => ['Accept: application/json'],
            ];

            return $method === 'POST'
                ? Client::getInstance()->postForm($url, $form, $options)
                : Client::getInstance()->get($url, $options);
        };
        $this->clock = $deps['clock'] ?? static fn (): int => time();
        $this->cacheGet = $deps['cacheGet'] ?? static function (): ?array {
            try {
                $raw = SettingsService::getInstance()->get(EntraSettings::GROUP, self::JWKS_CACHE_KEY);
            } catch (\Throwable) {
                return null;
            }
            $decoded = is_string($raw) ? json_decode($raw, true) : $raw;

            return is_array($decoded) ? $decoded : null;
        };
        $this->cacheSet = $deps['cacheSet'] ?? static function (array $entry): void {
            try {
                SettingsService::getInstance()->set(EntraSettings::GROUP, self::JWKS_CACHE_KEY, (string) json_encode($entry, JSON_UNESCAPED_SLASHES));
            } catch (\Throwable) {
            }
        };
    }

    /** Autorität im Pfad: fester Tenant oder – nur mit Allowlist – `organizations`. */
    public function authority(): string
    {
        return EntraSettings::isMultiTenant($this->settings) ? 'organizations' : (string) ($this->settings['tenant_id'] ?? '');
    }

    public function authorizationUrl(string $state, string $nonce, string $codeChallenge, string $redirectUri, array $extra = []): string
    {
        $params = [
            'client_id' => (string) ($this->settings['client_id'] ?? ''),
            'response_type' => 'code',
            'redirect_uri' => $redirectUri,
            'response_mode' => 'query',
            'scope' => EntraSettings::SCOPES,
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
        ];
        foreach (['prompt', 'login_hint', 'domain_hint'] as $allowed) {
            if (isset($extra[$allowed]) && $extra[$allowed] !== '') {
                $params[$allowed] = $extra[$allowed];
            }
        }

        return $this->endpoint('oauth2/v2.0/authorize') . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    public function exchangeCode(string $code, string $codeVerifier, string $redirectUri): array
    {
        if ($code === '' || strlen($code) > 4096 || preg_match('/^[A-Za-z0-9._~\-]+$/', $code) !== 1) {
            return ['ok' => false, 'error' => 'invalid_code'];
        }

        $response = $this->call('POST', $this->endpoint('oauth2/v2.0/token'), [
            'client_id' => (string) ($this->settings['client_id'] ?? ''),
            'client_secret' => (string) ($this->settings['client_secret'] ?? ''),
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'code_verifier' => $codeVerifier,
            'scope' => EntraSettings::SCOPES,
        ]);

        $data = json_decode((string) ($response['body'] ?? ''), true);
        if (!is_array($data)) {
            return ['ok' => false, 'error' => 'token_endpoint_unreachable'];
        }
        if (isset($data['error']) || empty($response['success'])) {
            return [
                'ok' => false,
                'error' => self::shortError((string) ($data['error'] ?? 'token_error')),
                'description' => self::shortDescription((string) ($data['error_description'] ?? '')),
            ];
        }

        $idToken = (string) ($data['id_token'] ?? '');
        if ($idToken === '') {
            return ['ok' => false, 'error' => 'id_token_missing'];
        }

        // Access- und Refresh-Token werden bewusst verworfen.
        return ['ok' => true, 'id_token' => $idToken];
    }

    public function verifyIdToken(string $idToken, string $expectedNonce): array
    {
        $parts = explode('.', $idToken);
        if (count($parts) !== 3 || strlen($idToken) > 16384) {
            return ['ok' => false, 'error' => 'malformed'];
        }
        $header = json_decode((string) self::base64UrlDecode($parts[0]), true);
        if (!is_array($header)) {
            return ['ok' => false, 'error' => 'malformed'];
        }
        // Nur RS256 – vor jeder Schlüsselwahl, damit `none`/HS256 mit dem öffentlichen Schlüssel nie geprüft werden.
        if (($header['alg'] ?? null) !== 'RS256') {
            return ['ok' => false, 'error' => 'algorithm'];
        }
        $kid = $header['kid'] ?? null;
        if (!is_string($kid) || $kid === '' || strlen($kid) > 200) {
            return ['ok' => false, 'error' => 'kid_missing'];
        }

        $keys = $this->keysFor($kid);
        if (!isset($keys[$kid])) {
            return ['ok' => false, 'error' => 'kid_unknown'];
        }

        $now = ($this->clock)();
        $previousLeeway = JWT::$leeway;
        $previousTimestamp = JWT::$timestamp;
        JWT::$leeway = self::LEEWAY;
        JWT::$timestamp = $now;
        try {
            $payload = JWT::decode($idToken, [$kid => $keys[$kid]]);
        } catch (ExpiredException) {
            return ['ok' => false, 'error' => 'expired'];
        } catch (BeforeValidException) {
            return ['ok' => false, 'error' => 'not_yet_valid'];
        } catch (SignatureInvalidException) {
            return ['ok' => false, 'error' => 'signature'];
        } catch (\Throwable) {
            return ['ok' => false, 'error' => 'invalid'];
        } finally {
            JWT::$leeway = $previousLeeway;
            JWT::$timestamp = $previousTimestamp;
        }

        $claims = json_decode((string) json_encode($payload), true);
        if (!is_array($claims)) {
            return ['ok' => false, 'error' => 'invalid'];
        }

        return self::validateClaims($claims, $this->settings, $expectedNonce, $now);
    }

    /**
     * Pflicht-Claims nach erfolgreicher Signaturprüfung (rein, auch einzeln testbar).
     *
     * @param array<string, mixed> $claims
     * @param array<string, mixed> $settings
     * @return array{ok:bool, claims?:array<string, mixed>, error?:string}
     */
    public static function validateClaims(array $claims, array $settings, string $expectedNonce, int $now): array
    {
        foreach (['exp', 'iat'] as $timeClaim) {
            if (!isset($claims[$timeClaim]) || !is_numeric($claims[$timeClaim])) {
                return ['ok' => false, 'error' => 'claims_missing'];
            }
        }
        if ((int) $claims['exp'] <= $now - self::LEEWAY) {
            return ['ok' => false, 'error' => 'expired'];
        }
        if ((int) $claims['iat'] > $now + self::LEEWAY || (isset($claims['nbf']) && (int) $claims['nbf'] > $now + self::LEEWAY)) {
            return ['ok' => false, 'error' => 'not_yet_valid'];
        }
        if (($claims['ver'] ?? null) !== '2.0') {
            return ['ok' => false, 'error' => 'version'];
        }

        $tid = $claims['tid'] ?? null;
        $oid = $claims['oid'] ?? null;
        if (!EntraSettings::isGuid($tid) || !EntraSettings::isGuid($oid)) {
            return ['ok' => false, 'error' => 'subject'];
        }

        $tenant = (string) ($settings['tenant_id'] ?? '');
        $allowed = EntraSettings::isMultiTenant($settings) ? (array) $settings['allowed_tenants'] : [$tenant];
        if ($tenant === '' || !in_array($tid, $allowed, true)) {
            return ['ok' => false, 'error' => 'tenant'];
        }
        if (($claims['iss'] ?? null) !== 'https://' . self::HOST . '/' . $tid . '/v2.0') {
            return ['ok' => false, 'error' => 'issuer'];
        }

        $clientId = (string) ($settings['client_id'] ?? '');
        $aud = $claims['aud'] ?? null;
        if (is_array($aud)) {
            $aud = count($aud) === 1 ? reset($aud) : null;
        }
        if ($clientId === '' || !is_string($aud) || !hash_equals($clientId, strtolower($aud))) {
            return ['ok' => false, 'error' => 'audience'];
        }

        $nonce = $claims['nonce'] ?? null;
        if ($expectedNonce === '' || !is_string($nonce) || !hash_equals($expectedNonce, $nonce)) {
            return ['ok' => false, 'error' => 'nonce'];
        }

        return ['ok' => true, 'claims' => $claims];
    }

    /**
     * Belegt das Token einen zweiten Faktor? (`amr` enthält z. B. `mfa`) – nur bei aktivierter Einstellung.
     *
     * @param array<string, mixed> $claims
     */
    public static function mfaFromClaims(array $claims): bool
    {
        $amr = $claims['amr'] ?? [];
        if (!is_array($amr)) {
            return false;
        }
        foreach ($amr as $method) {
            if (is_string($method) && in_array(strtolower($method), self::MFA_AMR_VALUES, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Vom Tenant bestätigte E-Mail-Adresse: Claim `email` und `xms_edov` = true. `preferred_username`/`upn` werden
     * nie verwendet. '' = keine bestätigte Adresse.
     *
     * @param array<string, mixed> $claims
     */
    public static function verifiedEmail(array $claims): string
    {
        $edov = $claims['xms_edov'] ?? null;
        if (!($edov === true || $edov === 1 || $edov === '1' || $edov === 'true')) {
            return '';
        }

        return self::claimEmail($claims);
    }

    /**
     * Claim `email` (unbestätigt), bereinigt – nur für Anlage und Anzeige, nie zur Zuordnung.
     *
     * @param array<string, mixed> $claims
     */
    public static function claimEmail(array $claims): string
    {
        $email = strtolower(trim((string) (is_string($claims['email'] ?? null) ? $claims['email'] : '')));

        return $email !== '' && strlen($email) <= 100 && filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : '';
    }

    /**
     * Claims ohne Token-Rohdaten für Hooks und Audit (`cms_sso_login`).
     *
     * @param array<string, mixed> $claims
     * @return array<string, mixed>
     */
    public static function publicClaims(array $claims): array
    {
        $out = [];
        foreach (['tid', 'oid', 'name', 'email', 'roles', 'groups', 'amr'] as $key) {
            if (array_key_exists($key, $claims)) {
                $out[$key] = $claims[$key];
            }
        }

        return $out;
    }

    /**
     * „Verbindung prüfen“: Discovery-Dokument lesen, dann den Token-Endpunkt mit einem ungültigen Code aufrufen.
     * `invalid_grant` heißt: Client-ID und Secret wurden akzeptiert.
     *
     * @return array{ok:bool, code:string, detail:string}
     */
    public function testConnection(string $redirectUri): array
    {
        $discovery = $this->call('GET', $this->endpoint('v2.0/.well-known/openid-configuration'));
        $doc = json_decode((string) ($discovery['body'] ?? ''), true);
        if (empty($discovery['success']) || !is_array($doc)) {
            return ['ok' => false, 'code' => 'discovery_failed', 'detail' => ''];
        }
        $tokenEndpoint = (string) ($doc['token_endpoint'] ?? '');
        if ($tokenEndpoint !== $this->endpoint('oauth2/v2.0/token')) {
            return ['ok' => false, 'code' => 'discovery_mismatch', 'detail' => ''];
        }

        $result = $this->exchangeCode('365cms-connection-test', 'connection-test-verifier-0000000000000000000000', $redirectUri);
        $error = (string) ($result['error'] ?? '');

        return match ($error) {
            'invalid_grant' => ['ok' => true, 'code' => 'ok', 'detail' => ''],
            'invalid_client' => ['ok' => false, 'code' => 'invalid_client', 'detail' => (string) ($result['description'] ?? '')],
            'unauthorized_client' => ['ok' => false, 'code' => 'unauthorized_client', 'detail' => (string) ($result['description'] ?? '')],
            default => ['ok' => false, 'code' => $error !== '' ? $error : 'unexpected', 'detail' => (string) ($result['description'] ?? '')],
        };
    }

    /**
     * Schlüssel aus dem Cache; bei fehlendem `kid` (Schlüsselrotation) einmal neu laden.
     *
     * @return array<string, \Firebase\JWT\Key>
     */
    private function keysFor(string $kid): array
    {
        $now = ($this->clock)();
        $authority = $this->authority();
        $cached = ($this->cacheGet)();
        $cacheValid = is_array($cached)
            && ($cached['authority'] ?? null) === $authority
            && is_array($cached['jwks'] ?? null)
            && (int) ($cached['fetched_at'] ?? 0) > $now - self::JWKS_TTL;

        $keys = $cacheValid ? $this->parseKeys((array) $cached['jwks']) : [];
        $mayRefresh = !$cacheValid || (int) ($cached['fetched_at'] ?? 0) <= $now - self::JWKS_REFRESH_MIN_INTERVAL;
        if (isset($keys[$kid]) || !$mayRefresh) {
            return $keys;
        }

        $response = $this->call('GET', $this->endpoint('discovery/v2.0/keys'));
        $jwks = json_decode((string) ($response['body'] ?? ''), true);
        if (empty($response['success']) || !is_array($jwks) || !is_array($jwks['keys'] ?? null)) {
            return $keys;
        }

        ($this->cacheSet)(['authority' => $authority, 'fetched_at' => $now, 'jwks' => ['keys' => array_values($jwks['keys'])]]);

        return $this->parseKeys($jwks);
    }

    /**
     * Nur RSA-Signaturschlüssel; der Algorithmus wird fest auf RS256 gesetzt.
     *
     * @param array<string, mixed> $jwks
     * @return array<string, \Firebase\JWT\Key>
     */
    private function parseKeys(array $jwks): array
    {
        $clean = [];
        foreach ((array) ($jwks['keys'] ?? []) as $key) {
            if (!is_array($key) || ($key['kty'] ?? '') !== 'RSA' || !is_string($key['kid'] ?? null) || $key['kid'] === '') {
                continue;
            }
            if (isset($key['use']) && $key['use'] !== 'sig') {
                continue;
            }
            $key['alg'] = 'RS256';
            $clean[] = array_intersect_key($key, array_flip(['kty', 'kid', 'use', 'n', 'e', 'alg']));
        }
        if ($clean === []) {
            return [];
        }

        try {
            return JWK::parseKeySet(['keys' => $clean], 'RS256');
        } catch (\Throwable) {
            return [];
        }
    }

    private function endpoint(string $path): string
    {
        return 'https://' . self::HOST . '/' . rawurlencode($this->authority()) . '/' . $path;
    }

    /**
     * @param array<string, string> $form
     * @return array{success:bool,status:int,body:string}
     */
    private function call(string $method, string $url, array $form = []): array
    {
        if (strtolower((string) parse_url($url, PHP_URL_HOST)) !== self::HOST || $this->authority() === '') {
            return ['success' => false, 'status' => 0, 'body' => ''];
        }

        try {
            $response = ($this->http)($method, $url, $form);
        } catch (\Throwable) {
            return ['success' => false, 'status' => 0, 'body' => ''];
        }

        return [
            'success' => !empty($response['success']),
            'status' => (int) ($response['status'] ?? 0),
            'body' => substr((string) ($response['body'] ?? ''), 0, self::MAX_RESPONSE_BYTES),
        ];
    }

    private static function base64UrlDecode(string $value): string|false
    {
        $remainder = strlen($value) % 4;
        if ($remainder !== 0) {
            $value .= str_repeat('=', 4 - $remainder);
        }

        return base64_decode(strtr($value, '-_', '+/'), true);
    }

    private static function shortError(string $error): string
    {
        $error = strtolower(trim($error));

        return preg_match('/^[a-z_]{1,60}$/', $error) === 1 ? $error : 'token_error';
    }

    /** Nur der AADSTS-Code und ein kurzer, bereinigter Text (keine Trace-/Korrelations-IDs). */
    private static function shortDescription(string $description): string
    {
        $firstLine = trim((string) strtok($description, "\r\n"));
        $firstLine = (string) preg_replace('/[^\p{L}\p{N} .,:;\'"()\/_-]/u', '', $firstLine);

        return mb_substr($firstLine, 0, 200);
    }
}
