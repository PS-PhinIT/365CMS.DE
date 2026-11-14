<?php
/**
 * Entra-ID-Anmeldung über OpenID Connect (Release 4.1.0, Bereich 1c).
 *
 * Ohne Netzwerk und ohne Datenbank: lokal erzeugter RSA-Schlüssel, Fake-JWKS und Fake-Token-Endpunkt (HTTP-Closure),
 * Konten in einem In-Memory-`IdentityStoreInterface`. Geprüft werden jede Pflichtprüfung des ID-Tokens einzeln
 * (01-IDENTITY §3.2), state/nonce/PKCE, die Konto-Zuordnung inkl. Angriffen (§3.3, GHSA-6hhv-gc4g-f4r7),
 * Provisioning, Rollen-Mapping (§3.5), `amr`-Auswertung (§3.4), die Einstellungen und – statisch – die Einbindung in
 * Router, Auth, Mitgliederbereich und Admin.
 *
 * Aufruf: php TESTS/sso-entra/run.php [-v]
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/TestRunner.php';
T::$verbose = in_array('-v', $argv ?? [], true);

$root = dirname(__DIR__, 2) . '/CMS/';
if (!defined('ABSPATH')) {
    define('ABSPATH', $root);
}
if (!defined('SITE_URL')) {
    define('SITE_URL', 'https://cms.example.test');
}

if (!class_exists('CMS\\Hooks')) {
    eval('namespace CMS; final class Hooks {
        public static function applyFilters(string $tag, $value, ...$args): mixed { return $value; }
        public static function doAction(string $tag, ...$args): void {}
    }');
}
spl_autoload_register(static function (string $class) use ($root): void {
    $map = ['Firebase\\JWT\\' => 'assets/php-jwt/', 'CMS\\' => 'core/'];
    foreach ($map as $prefix => $dir) {
        if (str_starts_with($class, $prefix)) {
            $file = $root . $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require_once $file;
            }
        }
    }
});

use CMS\Auth\SSO\EntraIdProvider;
use CMS\Auth\SSO\EntraSettings;
use CMS\Auth\SSO\EntraSsoService;
use CMS\Auth\SSO\IdentityLinker;
use CMS\Auth\SSO\IdentityStoreInterface;
use CMS\Auth\SSO\OidcFlowState;
use CMS\Routing\SsoRouter;
use Firebase\JWT\JWT;

$_SESSION = [];

const TENANT = '11111111-2222-3333-4444-555555555555';
const OTHER_TENANT = '99999999-8888-7777-6666-555555555555';
const CLIENT = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
const OID = '0a0b0c0d-1111-2222-3333-444455556666';
const NOW = 1_800_000_000;

// ── Schlüssel und JWKS ────────────────────────────────────────────────────────
$newKey = static function (): array {
    $res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($res, $pem);
    $details = openssl_pkey_get_details($res);
    $b64 = static fn (string $bin): string => rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');

    return [
        'private' => $pem,
        'public' => $details['key'],
        'jwk' => ['kty' => 'RSA', 'use' => 'sig', 'n' => $b64($details['rsa']['n']), 'e' => $b64($details['rsa']['e'])],
    ];
};
$key1 = $newKey();
$key2 = $newKey();
$attackerKey = $newKey();
$jwks = static fn (array ...$keys): array => ['keys' => array_map(static fn (array $pair): array => $pair[0]['jwk'] + ['kid' => $pair[1]], $keys)];

$settings = EntraSettings::normalize([
    'enabled' => '1',
    'tenant_id' => TENANT,
    'client_id' => CLIENT,
    'client_secret_set' => true,
]) + ['client_secret' => 'top-secret-value'];

$claims = static fn (array $overrides = []): array => array_merge([
    'iss' => 'https://login.microsoftonline.com/' . TENANT . '/v2.0',
    'aud' => CLIENT,
    'tid' => TENANT,
    'oid' => OID,
    'sub' => 'pairwise-subject',
    'ver' => '2.0',
    'iat' => NOW - 10,
    'nbf' => NOW - 10,
    'exp' => NOW + 3600,
    'nonce' => 'nonce-123',
    'name' => 'Erika Mustermann',
    'email' => 'erika@contoso.example',
], $overrides);
$sign = static fn (array $payload, array $key, string $kid = 'k1'): string => JWT::encode($payload, $key['private'], 'RS256', $kid);

/**
 * @return array{0: EntraIdProvider, 1: \ArrayObject}
 */
$makeProvider = static function (array $settings, ?array $cache, array $remoteJwks, array $tokenResponse = [], int $now = NOW) {
    $log = new \ArrayObject();
    $state = new \ArrayObject(['cache' => $cache]);
    $provider = new EntraIdProvider($settings, [
        'clock' => static fn (): int => $now,
        'cacheGet' => static fn (): ?array => $state['cache'],
        'cacheSet' => static function (array $entry) use ($state): void {
            $state['cache'] = $entry;
        },
        'http' => static function (string $method, string $url, array $form = []) use ($log, $remoteJwks, $tokenResponse): array {
            $log[] = [$method, $url, $form];
            if (str_ends_with($url, '/discovery/v2.0/keys')) {
                return ['success' => true, 'status' => 200, 'body' => (string) json_encode($remoteJwks)];
            }
            if (str_ends_with($url, '/oauth2/v2.0/token')) {
                $status = (int) ($tokenResponse['status'] ?? 200);

                return ['success' => $status < 300, 'status' => $status, 'body' => (string) json_encode($tokenResponse['body'] ?? [])];
            }
            if (str_ends_with($url, '/.well-known/openid-configuration')) {
                $authority = explode('/', (string) parse_url($url, PHP_URL_PATH))[1] ?? '';

                return ['success' => true, 'status' => 200, 'body' => (string) json_encode([
                    'token_endpoint' => 'https://login.microsoftonline.com/' . $authority . '/oauth2/v2.0/token',
                ])];
            }

            return ['success' => false, 'status' => 404, 'body' => ''];
        },
    ]);

    return [$provider, $log, $state];
};

T::run('ID-Token: gültiges Token', static function () use ($makeProvider, $settings, $jwks, $key1, $claims, $sign): void {
    [$provider, $log] = $makeProvider($settings, null, $jwks([$key1, 'k1']));
    $result = $provider->verifyIdToken($sign($claims(), $key1), 'nonce-123');
    T::check($result['ok'], 'gültiges Token wird akzeptiert', (string) ($result['error'] ?? ''));
    T::same(OID, $result['claims']['oid'] ?? null, 'Claims kommen zurück');
    T::same(1, count($log), 'JWKS einmal geladen');
    T::same('https://login.microsoftonline.com/' . TENANT . '/discovery/v2.0/keys', $log[0][1], 'JWKS des konfigurierten Tenants');
    T::same(0, JWT::$leeway, 'JWT::$leeway nach der Prüfung zurückgesetzt');
    T::same(null, JWT::$timestamp, 'JWT::$timestamp nach der Prüfung zurückgesetzt');
});

T::run('ID-Token: jede Pflichtprüfung einzeln verletzt', static function () use ($makeProvider, $settings, $jwks, $key1, $attackerKey, $claims, $sign): void {
    [$provider] = $makeProvider($settings, null, $jwks([$key1, 'k1']));
    $verify = static fn (string $token, string $nonce = 'nonce-123'): string => (string) ($provider->verifyIdToken($token, $nonce)['error'] ?? 'ok');
    $b64 = static fn (string $json): string => rtrim(strtr(base64_encode($json), '+/', '-_'), '=');

    T::same('signature', $verify($sign($claims(), $attackerKey)), 'falsche Signatur (fremder Schlüssel, gleiche kid)');
    $none = $b64('{"alg":"none","typ":"JWT","kid":"k1"}') . '.' . $b64((string) json_encode($claims())) . '.';
    T::same('algorithm', $verify($none), 'alg=none');
    $hs = JWT::encode($claims(), $key1['public'], 'HS256', 'k1');
    T::same('algorithm', $verify($hs), 'HS256 mit dem öffentlichen Schlüssel als Secret');
    $rs512 = JWT::encode($claims(), $key1['private'], 'RS512', 'k1');
    T::same('algorithm', $verify($rs512), 'anderer RSA-Algorithmus (RS512)');
    T::same('kid_missing', $verify(JWT::encode($claims(), $key1['private'], 'RS256')), 'Token ohne kid');
    T::same('issuer', $verify($sign($claims(['iss' => 'https://login.microsoftonline.com/' . TENANT . '/v2.0/']), $key1)), 'falscher iss (Schrägstrich am Ende)');
    T::same('issuer', $verify($sign($claims(['iss' => 'https://sts.windows.net/' . TENANT . '/']), $key1)), 'v1-Issuer');
    T::same('audience', $verify($sign($claims(['aud' => 'bbbbbbbb-bbbb-cccc-dddd-eeeeeeeeeeee']), $key1)), 'falsche aud');
    T::same('audience', $verify($sign($claims(['aud' => [CLIENT, 'other']]), $key1)), 'aud mit mehreren Werten');
    T::same('tenant', $verify($sign($claims(['tid' => OTHER_TENANT, 'iss' => 'https://login.microsoftonline.com/' . OTHER_TENANT . '/v2.0']), $key1)), 'fremder Tenant (iss passend zum tid)');
    T::same('expired', $verify($sign($claims(['exp' => NOW - 121]), $key1)), 'abgelaufen (außerhalb der Toleranz)');
    T::same('ok', $verify($sign($claims(['exp' => NOW - 60]), $key1)), 'abgelaufen innerhalb der 120 s Toleranz → gültig');
    T::same('not_yet_valid', $verify($sign($claims(['nbf' => NOW + 600]), $key1)), 'nbf in der Zukunft');
    T::same('not_yet_valid', $verify($sign($claims(['iat' => NOW + 600, 'nbf' => NOW - 10]), $key1)), 'iat in der Zukunft');
    $noExp = $claims();
    unset($noExp['exp']);
    T::same('claims_missing', $verify($sign($noExp, $key1)), 'exp fehlt');
    T::same('nonce', $verify($sign($claims(), $key1), 'andere-nonce'), 'falsche nonce');
    T::same('nonce', $verify($sign($claims(), $key1), ''), 'leere erwartete nonce (verbrauchter Fluss)');
    $noNonce = $claims();
    unset($noNonce['nonce']);
    T::same('nonce', $verify($sign($noNonce, $key1)), 'nonce fehlt im Token');
    T::same('version', $verify($sign($claims(['ver' => '1.0']), $key1)), 'ver 1.0');
    T::same('subject', $verify($sign($claims(['oid' => 'admin']), $key1)), 'oid kein GUID');
    T::same('subject', $verify($sign($claims(['tid' => null]), $key1)), 'tid fehlt');
    T::same('malformed', $verify('a.b'), 'kein JWT');
});

T::run('JWKS: Cache und Schlüsselrotation', static function () use ($makeProvider, $settings, $jwks, $key1, $key2, $claims, $sign): void {
    $fresh = ['authority' => TENANT, 'fetched_at' => NOW - 3600, 'jwks' => $jwks([$key1, 'k1'])];
    [$provider, $log] = $makeProvider($settings, $fresh, $jwks([$key1, 'k1'], [$key2, 'k2']));
    T::check($provider->verifyIdToken($sign($claims(), $key1), 'nonce-123')['ok'], 'bekannte kid aus dem Cache');
    T::same(0, count($log), 'kein Netzaufruf bei gültigem Cache');

    T::check($provider->verifyIdToken($sign($claims(), $key2, 'k2'), 'nonce-123')['ok'], 'unbekannte kid → einmal neu laden (Rotation)');
    T::same(1, count($log), 'genau ein Nachladen');

    $recent = ['authority' => TENANT, 'fetched_at' => NOW - 100, 'jwks' => $jwks([$key1, 'k1'])];
    [$provider, $log] = $makeProvider($settings, $recent, $jwks([$key1, 'k1'], [$key2, 'k2']));
    T::same('kid_unknown', $provider->verifyIdToken($sign($claims(), $key2, 'k2'), 'nonce-123')['error'] ?? null, 'kein erneutes Laden innerhalb von 5 Minuten');
    T::same(0, count($log), 'kein Netzaufruf (Schutz vor Nachlade-Schleifen)');

    $stale = ['authority' => TENANT, 'fetched_at' => NOW - 90000, 'jwks' => $jwks([$key1, 'k1'])];
    [$provider, $log, $state] = $makeProvider($settings, $stale, $jwks([$key2, 'k2']));
    T::same('kid_unknown', $provider->verifyIdToken($sign($claims(), $key1), 'nonce-123')['error'] ?? null, 'nach 24 h gilt nur das neue JWKS (zurückgezogener Schlüssel abgelehnt)');
    T::same(NOW, $state['cache']['fetched_at'] ?? null, 'Cache aktualisiert');

    $otherAuthority = ['authority' => 'organizations', 'fetched_at' => NOW, 'jwks' => $jwks([$key1, 'k1'])];
    [$provider, $log] = $makeProvider($settings, $otherAuthority, $jwks([$key1, 'k1']));
    $provider->verifyIdToken($sign($claims(), $key1), 'nonce-123');
    T::same(1, count($log), 'Cache einer anderen Autorität wird nicht verwendet');

    $badJwks = ['keys' => [['kty' => 'oct', 'kid' => 'k1', 'k' => 'c2VjcmV0'], $key1['jwk'] + ['kid' => 'enc', 'use' => 'enc']]];
    [$provider] = $makeProvider($settings, null, $badJwks);
    T::same('kid_unknown', $provider->verifyIdToken($sign($claims(), $key1), 'nonce-123')['error'] ?? null, 'symmetrische und Verschlüsselungsschlüssel im JWKS werden ignoriert');
});

T::run('Multi-Tenant nur mit Allowlist', static function () use ($makeProvider, $jwks, $key1, $claims, $sign): void {
    $multi = EntraSettings::normalize(['enabled' => '1', 'tenant_id' => TENANT, 'client_id' => CLIENT, 'client_secret_set' => true, 'allowed_tenants' => OTHER_TENANT]);
    T::check(EntraSettings::isMultiTenant($multi), 'zweiter Tenant → Multi-Tenant');
    T::same([TENANT, OTHER_TENANT], $multi['allowed_tenants'], 'eigener Tenant immer in der Allowlist');
    [$provider, $log] = $makeProvider($multi, null, $jwks([$key1, 'k1']));
    T::same('organizations', $provider->authority(), 'Autorität organizations');
    $other = $claims(['tid' => OTHER_TENANT, 'iss' => 'https://login.microsoftonline.com/' . OTHER_TENANT . '/v2.0']);
    T::check($provider->verifyIdToken($sign($other, $key1), 'nonce-123')['ok'], 'erlaubter fremder Tenant');
    $third = '12121212-3434-5656-7878-909090909090';
    $foreign = $claims(['tid' => $third, 'iss' => 'https://login.microsoftonline.com/' . $third . '/v2.0']);
    T::same('tenant', $provider->verifyIdToken($sign($foreign, $key1), 'nonce-123')['error'] ?? null, 'Tenant außerhalb der Allowlist');
    T::check(str_contains((string) $log[0][1], '/organizations/discovery/v2.0/keys'), 'JWKS der Autorität organizations');

    $single = EntraSettings::normalize(['tenant_id' => TENANT, 'allowed_tenants' => TENANT]);
    T::check(!EntraSettings::isMultiTenant($single), 'nur eigener Tenant in der Liste → Single-Tenant');
});

T::run('Autorisierungs-URL und Token-Endpunkt', static function () use ($makeProvider, $settings, $jwks, $key1): void {
    [$provider, $log] = $makeProvider($settings, null, $jwks([$key1, 'k1']), ['body' => ['id_token' => 'x.y.z', 'access_token' => 'AT', 'refresh_token' => 'RT']]);
    $url = $provider->authorizationUrl('STATE', 'NONCE', 'CHALLENGE', EntraSettings::redirectUri(), ['prompt' => 'select_account', 'evil' => '1']);
    $parts = parse_url($url);
    parse_str((string) ($parts['query'] ?? ''), $query);
    T::same('login.microsoftonline.com', $parts['host'] ?? '', 'Host fest');
    T::same('/' . TENANT . '/oauth2/v2.0/authorize', $parts['path'] ?? '', 'Pfad mit Tenant');
    T::same(['code', 'S256', 'CHALLENGE', 'STATE', 'NONCE', 'query', 'openid profile email', CLIENT, 'https://cms.example.test/cms-login/sso/entra/callback', 'select_account'],
        [$query['response_type'], $query['code_challenge_method'], $query['code_challenge'], $query['state'], $query['nonce'], $query['response_mode'], $query['scope'], $query['client_id'], $query['redirect_uri'], $query['prompt'] ?? ''],
        'Pflichtparameter (Code Flow, PKCE S256, state, nonce, feste Scopes)');
    T::check(!isset($query['evil']), 'unbekannte Zusatzparameter werden verworfen');

    $exchange = $provider->exchangeCode('abc.DEF-123_~', 'verifier', EntraSettings::redirectUri());
    T::same(['ok' => true, 'id_token' => 'x.y.z'], $exchange, 'nur das ID-Token kommt zurück (Access/Refresh verworfen)');
    $form = $log[0][2];
    T::same(['authorization_code', 'verifier', 'top-secret-value'], [$form['grant_type'], $form['code_verifier'], $form['client_secret']], 'Token-Anfrage mit PKCE-Verifier und Secret');
    T::same(1, count($log), 'ein Aufruf');
    T::same(['ok' => false, 'error' => 'invalid_code'], $provider->exchangeCode("abc\"<script>", 'v', 'r'), 'Code mit fremden Zeichen ohne Netzaufruf abgelehnt');
    T::same(1, count($log), 'kein weiterer Aufruf');

    [$failing] = $makeProvider($settings, null, [], ['status' => 400, 'body' => [
        'error' => 'invalid_grant',
        'error_description' => "AADSTS70008: The code has expired <img src=x onerror=alert(1)>\r\nTrace ID: 123\r\nCorrelation ID: 456",
    ]]);
    $failed = $failing->exchangeCode('abc', 'v', 'r');
    T::same('invalid_grant', $failed['error'] ?? null, 'Fehlercode von Entra');
    T::check(!str_contains((string) ($failed['description'] ?? ''), 'Trace ID') && !str_contains((string) ($failed['description'] ?? ''), '<'), 'Beschreibung gekürzt, ohne Trace-ID und Markup', (string) ($failed['description'] ?? ''));
    T::check(!str_contains((string) json_encode($failed), 'top-secret-value'), 'Secret nie im Ergebnis');
});

T::run('Verbindung prüfen', static function () use ($makeProvider, $settings): void {
    [$ok] = $makeProvider($settings, null, [], ['status' => 400, 'body' => ['error' => 'invalid_grant']]);
    T::same('ok', $ok->testConnection(EntraSettings::redirectUri())['code'], 'invalid_grant = Secret gültig');
    [$bad] = $makeProvider($settings, null, [], ['status' => 401, 'body' => ['error' => 'invalid_client', 'error_description' => 'AADSTS7000215: Invalid client secret provided.']]);
    $result = $bad->testConnection(EntraSettings::redirectUri());
    T::same(['invalid_client', false], [$result['code'], $result['ok']], 'invalid_client erkannt');
    $empty = new EntraIdProvider(EntraSettings::normalize([]), ['http' => static function (): array {
        throw new \RuntimeException('darf nicht aufgerufen werden');
    }]);
    T::same('discovery_failed', $empty->testConnection('r')['code'], 'ohne Tenant kein Netzaufruf');
});

T::run('state, nonce und PKCE in der Sitzung', static function (): void {
    $_SESSION = [];
    $now = NOW;
    $flows = new OidcFlowState(static function () use (&$now): int {
        return $now;
    });
    $first = $flows->begin(OidcFlowState::PURPOSE_LOGIN, '/admin');
    T::same([43, 43, 43], [strlen($first['state']), strlen($first['nonce']), strlen($first['code_challenge'])], 'je 32 Zufallsbytes (base64url)');
    T::check(!str_contains((string) json_encode($_SESSION), $first['state']), 'Sitzung enthält nur den Hash des state');
    T::same('E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM', OidcFlowState::codeChallenge('dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk'), 'S256 nach RFC 7636 (Anhang B)');

    $consumed = $flows->consume($first['state']);
    T::check($consumed['ok'], 'state einlösbar');
    T::same(['login', '/admin', $first['nonce']], [$consumed['flow']['purpose'], $consumed['flow']['redirect'], $consumed['flow']['nonce']], 'Zweck, Ziel und nonce');
    T::same($first['code_challenge'], OidcFlowState::codeChallenge($consumed['flow']['verifier']), 'Verifier passt zur Challenge');
    T::same('state_unknown', $flows->consume($first['state'])['reason'], 'state ist einmalig (Wiederverwendung abgelehnt)');

    $expiring = $flows->begin(OidcFlowState::PURPOSE_LOGIN, '/member');
    $now += 601;
    T::same('state_expired', $flows->consume($expiring['state'])['reason'], 'nach 10 Minuten abgelaufen');
    T::same('state_unknown', $flows->consume($expiring['state'])['reason'], 'abgelaufener state ist danach verbraucht');
    T::same('state_missing', $flows->consume(null)['reason'], 'state fehlt');
    T::same('state_missing', $flows->consume('<script>')['reason'], 'state mit fremden Zeichen');
    T::same('state_unknown', $flows->consume('a' . substr($first['state'], 1))['reason'], 'gefälschter state');

    $states = [];
    for ($i = 0; $i < 6; $i++) {
        $states[] = $flows->begin(OidcFlowState::PURPOSE_LOGIN, '/member')['state'];
    }
    T::same(5, count($_SESSION[OidcFlowState::SESSION_KEY]), 'höchstens fünf parallele Flüsse');
    T::same('state_unknown', $flows->consume($states[0])['reason'], 'ältester Fluss fällt weg');
    T::check($flows->consume($states[5])['ok'], 'neuester Fluss bleibt');

    $link = $flows->begin(OidcFlowState::PURPOSE_LINK, '/member/security', 42);
    T::same(42, $flows->consume($link['state'])['flow']['user_id'] ?? null, 'Verknüpfen merkt sich das Konto');
    $threw = false;
    try {
        $flows->begin(OidcFlowState::PURPOSE_LINK, '/member/security');
    } catch (\InvalidArgumentException) {
        $threw = true;
    }
    T::check($threw, 'Verknüpfen ohne Konto nicht möglich');
});

T::run('amr, E-Mail-Bestätigung, öffentliche Claims', static function () use ($claims): void {
    T::check(EntraIdProvider::mfaFromClaims(['amr' => ['pwd', 'mfa']]), 'amr mit mfa');
    T::check(EntraIdProvider::mfaFromClaims(['amr' => ['ngcmfa']]), 'Windows Hello (ngcmfa)');
    T::check(EntraIdProvider::mfaFromClaims(['amr' => ['fido']]), 'FIDO2');
    T::check(!EntraIdProvider::mfaFromClaims(['amr' => ['pwd']]), 'nur Passwort');
    T::check(!EntraIdProvider::mfaFromClaims(['amr' => ['wia']]), 'wia allein ist kein zweiter Faktor');
    T::check(!EntraIdProvider::mfaFromClaims(['amr' => 'mfa']), 'amr als Zeichenkette statt Liste');
    T::check(!EntraIdProvider::mfaFromClaims([]), 'amr fehlt (v2-Token ohne amr) → kein Nachweis');

    T::same('erika@contoso.example', EntraIdProvider::verifiedEmail($claims(['xms_edov' => true, 'email' => 'Erika@Contoso.example'])), 'email + xms_edov → bestätigt (klein)');
    T::same('', EntraIdProvider::verifiedEmail($claims()), 'ohne xms_edov unbestätigt');
    T::same('', EntraIdProvider::verifiedEmail($claims(['xms_edov' => false])), 'xms_edov = false');
    T::same('', EntraIdProvider::verifiedEmail(['xms_edov' => true, 'preferred_username' => 'admin@contoso.example', 'upn' => 'admin@contoso.example']), 'preferred_username/upn werden nie verwendet');
    T::same('', EntraIdProvider::claimEmail(['email' => 'kein-mail']), 'ungültige Adresse');

    $public = EntraIdProvider::publicClaims($claims(['amr' => ['mfa'], 'nonce' => 'n', 'roles' => ['CMS.Editor']]));
    T::same(['tid', 'oid', 'name', 'email', 'roles', 'amr'], array_keys($public), 'Hook-Claims ohne nonce/aud/Token-Rohdaten');
});

final class MemoryIdentityStore implements IdentityStoreInterface
{
    /** @var array<int, object> */
    public array $users = [];
    /** @var list<array<string, mixed>> */
    public array $identities = [];
    /** @var array<int, bool> */
    public array $verified = [];
    public int $nextId = 100;
    public bool $failIdentityInsert = false;

    public function addUser(int $id, string $email, string $role, string $status = 'active', bool $verified = true): void
    {
        $this->users[$id] = (object) ['id' => $id, 'username' => 'u' . $id, 'email' => $email, 'role' => $role, 'status' => $status];
        $this->verified[$id] = $verified;
    }

    public function findIdentity(string $provider, string $tenantId, string $subject): ?array
    {
        foreach ($this->identities as $i) {
            if ($i['provider'] === $provider && $i['tenant_id'] === $tenantId && $i['subject'] === $subject) {
                return ['id' => $i['id'], 'user_id' => $i['user_id'], 'provisioned' => $i['provisioned'], 'email_at_link' => $i['email'], 'linked_at' => '', 'last_login_at' => ''];
            }
        }

        return null;
    }

    public function findIdentityForUser(int $userId, string $provider): ?array
    {
        foreach ($this->identities as $i) {
            if ($i['user_id'] === $userId && $i['provider'] === $provider) {
                return ['id' => $i['id'], 'user_id' => $userId, 'tenant_id' => $i['tenant_id'], 'subject' => $i['subject'], 'provisioned' => $i['provisioned'], 'email_at_link' => $i['email'], 'linked_at' => '', 'last_login_at' => ''];
            }
        }

        return null;
    }

    public function findUser(int $userId): ?object
    {
        return $this->users[$userId] ?? null;
    }

    public function findUsersByEmail(string $email): array
    {
        return array_values(array_filter($this->users, static fn (object $u): bool => strcasecmp($u->email, $email) === 0));
    }

    public function isEmailVerified(int $userId): bool
    {
        return $this->verified[$userId] ?? false;
    }

    public function usernameExists(string $username): bool
    {
        foreach ($this->users as $u) {
            if ($u->username === $username) {
                return true;
            }
        }

        return false;
    }

    public function createUser(string $username, string $email, string $displayName, string $role, bool $emailVerified): int
    {
        $id = $this->nextId++;
        $this->users[$id] = (object) ['id' => $id, 'username' => $username, 'email' => $email, 'display_name' => $displayName, 'role' => $role, 'status' => 'active'];
        $this->verified[$id] = $emailVerified;

        return $id;
    }

    public function deleteUser(int $userId): void
    {
        unset($this->users[$userId], $this->verified[$userId]);
    }

    public function createIdentity(int $userId, string $provider, string $tenantId, string $subject, string $email, bool $provisioned): bool
    {
        if ($this->failIdentityInsert || $this->findIdentity($provider, $tenantId, $subject) !== null || $this->findIdentityForUser($userId, $provider) !== null) {
            return false;
        }
        $this->identities[] = ['id' => count($this->identities) + 1, 'user_id' => $userId, 'provider' => $provider, 'tenant_id' => $tenantId, 'subject' => $subject, 'email' => $email, 'provisioned' => $provisioned];

        return true;
    }

    public function deleteIdentity(int $userId, string $provider): bool
    {
        $before = count($this->identities);
        $this->identities = array_values(array_filter($this->identities, static fn (array $i): bool => !($i['user_id'] === $userId && $i['provider'] === $provider)));

        return count($this->identities) < $before;
    }

    public function touchIdentity(int $identityId): void
    {
    }

    public function updateRole(int $userId, string $role): bool
    {
        if (!isset($this->users[$userId])) {
            return false;
        }
        $this->users[$userId]->role = $role;

        return true;
    }
}

$roles = ['admin', 'editor', 'author', 'member'];
$linkSettings = static fn (array $overrides = []): array => EntraSettings::normalize(array_merge([
    'enabled' => '1', 'tenant_id' => TENANT, 'client_id' => CLIENT, 'client_secret_set' => true,
    'auto_link' => '1', 'allowed_domains' => "contoso.example",
], $overrides), $roles);

T::run('Zuordnung: bestehende Bindung', static function () use ($claims, $linkSettings, $roles): void {
    $store = new MemoryIdentityStore();
    $store->addUser(1, 'erika@contoso.example', 'member');
    $store->createIdentity(1, 'entra', TENANT, OID, 'erika@contoso.example', false);
    $linker = new IdentityLinker($store);
    $result = $linker->resolveLogin($claims(), $linkSettings(), [], $roles);
    T::same(['login', 1, 'existing'], [$result['status'], $result['user_id'] ?? null, $result['linked'] ?? null], 'Bindung tid+oid → dieses Konto');

    $store->users[1]->status = 'inactive';
    T::same('account_inactive', $linker->resolveLogin($claims(), $linkSettings(), [], $roles)['reason'] ?? null, 'gesperrtes Konto abgelehnt');
    T::same('inactive', $store->users[1]->status, 'Konto wird nie reaktiviert');

    $store->users[1]->status = 'active';
    $other = $linker->resolveLogin($claims(['tid' => OTHER_TENANT]), $linkSettings(), [], $roles);
    T::same('rejected', $other['status'], 'gleiche oid in anderem Tenant ist eine andere Identität');
});

T::run('Zuordnung: Angriffe (GHSA-6hhv-gc4g-f4r7)', static function () use ($claims, $linkSettings, $roles): void {
    $store = new MemoryIdentityStore();
    $store->addUser(1, 'admin@contoso.example', 'admin');
    $store->addUser(2, 'erika@contoso.example', 'member');
    $linker = new IdentityLinker($store);

    $attack = $claims(['preferred_username' => 'admin', 'upn' => 'admin@contoso.example', 'email' => null]);
    T::same('not_linked', $linker->resolveLogin($attack, $linkSettings(), [], $roles)['reason'] ?? null, '„preferred_username = admin“ ohne email → kein Konto');
    T::same('email_missing', $linker->resolveLogin($attack, $linkSettings(['auto_provision' => '1']), [], $roles)['reason'] ?? null, 'auch mit Provisioning keine Übernahme');

    $adminEmail = $claims(['email' => 'admin@contoso.example']);
    T::same('link_conflict', $linker->resolveLogin($adminEmail, $linkSettings(['auto_provision' => '1']), [], $roles)['reason'] ?? null, 'E-Mail eines Admin-Kontos ohne xms_edov → Ablehnung');
    T::same('link_conflict', $linker->resolveLogin($adminEmail + ['xms_edov' => true], $linkSettings(), [], $roles)['reason'] ?? null, 'E-Mail eines Admin-Kontos mit xms_edov → keine automatische Verknüpfung');
    T::same([], $store->identities, 'keine Bindung entstanden');
    T::same(2, count($store->users), 'kein zweites Konto mit derselben Adresse');
});

T::run('Zuordnung: automatische Verknüpfung nur unter allen Bedingungen', static function () use ($claims, $linkSettings, $roles): void {
    $verified = $claims(['email' => 'erika@contoso.example', 'xms_edov' => true]);
    $case = static function (callable $prepare, array $claimsIn, array $settings, array $mfaRoles = []) use ($roles): array {
        $store = new MemoryIdentityStore();
        $store->addUser(2, 'erika@contoso.example', 'member');
        $prepare($store);

        return [(new IdentityLinker($store))->resolveLogin($claimsIn, $settings, $mfaRoles, $roles), $store];
    };
    $noop = static function (): void {
    };

    [$ok, $store] = $case($noop, $verified, $linkSettings());
    T::same(['login', 2, 'auto', false], [$ok['status'], $ok['user_id'] ?? null, $ok['linked'] ?? null, $store->identities[0]['provisioned'] ?? null], 'alle Bedingungen erfüllt → Verknüpfung (nicht provisioniert)');

    T::same('link_conflict', $case($noop, $verified, $linkSettings(['auto_link' => '0']))[0]['reason'] ?? null, 'Einstellung aus (Standard)');
    T::same('link_conflict', $case($noop, $claims(['email' => 'erika@contoso.example']), $linkSettings())[0]['reason'] ?? null, 'ohne xms_edov');
    T::same('link_conflict', $case($noop, $verified, $linkSettings(['allowed_domains' => 'fabrikam.example']))[0]['reason'] ?? null, 'Domain nicht in der Allowlist');
    T::same('link_conflict', $case($noop, $verified, $linkSettings(['allowed_tenants' => OTHER_TENANT]))[0]['reason'] ?? null, 'Multi-Tenant');
    T::same('link_conflict', $case(static fn (MemoryIdentityStore $s) => $s->verified[2] = false, $verified, $linkSettings())[0]['reason'] ?? null, 'lokale Adresse unbestätigt');
    T::same('link_conflict', $case(static fn (MemoryIdentityStore $s) => $s->users[2]->status = 'unverified', $verified, $linkSettings())[0]['reason'] ?? null, 'Konto nicht aktiv');
    T::same('link_conflict', $case(static fn (MemoryIdentityStore $s) => $s->addUser(3, 'ERIKA@contoso.example', 'member'), $verified, $linkSettings())[0]['reason'] ?? null, 'zwei Konten mit der Adresse');
    T::same('link_conflict', $case(static fn (MemoryIdentityStore $s) => $s->users[2]->role = 'editor', $verified, $linkSettings())[0]['reason'] ?? null, 'Rolle editor (privilegiert)');
    T::same('link_conflict', $case($noop, $verified, $linkSettings(), ['member'])[0]['reason'] ?? null, 'Rolle mit MFA-Zwang');
    T::same('link_conflict', $case(static fn (MemoryIdentityStore $s) => $s->createIdentity(2, 'entra', TENANT, '0f0f0f0f-0000-0000-0000-000000000000', '', false), $verified, $linkSettings())[0]['reason'] ?? null, 'Konto ist schon mit einer anderen Entra-Identität verknüpft');
});

T::run('Provisioning und Rollen-Mapping', static function () use ($claims, $linkSettings, $roles): void {
    $mapping = [
        ['type' => 'role', 'value' => 'CMS.Admin', 'role' => 'admin'],
        ['type' => 'group', 'value' => 'ABCDEFAB-0000-0000-0000-000000000001', 'role' => 'editor'],
        ['type' => 'role', 'value' => 'CMS.Author', 'role' => 'author'],
    ];
    $store = new MemoryIdentityStore();
    $linker = new IdentityLinker($store);
    T::same('not_linked', $linker->resolveLogin($claims(), $linkSettings(), [], $roles)['reason'] ?? null, 'Provisioning aus (Standard) → kein Konto');

    $result = $linker->resolveLogin($claims(), $linkSettings(['auto_provision' => '1', 'role_mapping' => $mapping]), [], $roles);
    $user = $store->users[$result['user_id'] ?? 0] ?? null;
    T::same(['login', 'provisioned', 'member', true], [$result['status'], $result['linked'] ?? null, $user->role ?? null, $store->identities[0]['provisioned'] ?? null], 'neues Konto mit Standardrolle, Bindung provisioned = 1');
    T::check(preg_match('/^erika\.mustermann-[0-9a-f]{4}$/', (string) ($user->username ?? '')) === 1, 'Benutzername aus Anzeigename + Zufall', (string) ($user->username ?? ''));
    T::same(false, $store->verified[$result['user_id']] ?? null, 'E-Mail ohne xms_edov nicht als bestätigt markiert');

    $groupClaims = $claims(['oid' => '0a0b0c0d-1111-2222-3333-000000000002', 'email' => 'max@contoso.example', 'xms_edov' => true, 'groups' => ['abcdefab-0000-0000-0000-000000000001'], 'roles' => ['CMS.Author']]);
    $result = $linker->resolveLogin($groupClaims, $linkSettings(['auto_provision' => '1', 'role_mapping' => $mapping]), [], $roles);
    T::same('editor', $store->users[$result['user_id']]->role ?? null, 'Reihenfolge = Priorität, Gruppen-ID ohne Groß-/Kleinschreibung');
    T::same(true, $store->verified[$result['user_id']] ?? null, 'mit xms_edov als bestätigt markiert');

    $adminClaims = $claims(['oid' => '0a0b0c0d-1111-2222-3333-000000000003', 'email' => 'boss@contoso.example', 'roles' => ['CMS.Admin']]);
    $result = $linker->resolveLogin($adminClaims, $linkSettings(['auto_provision' => '1', 'role_mapping' => $mapping]), [], $roles);
    T::same('member', $store->users[$result['user_id']]->role ?? null, 'Admin-Regel ohne „Admin erlauben“ greift nicht');
    $adminClaims['oid'] = '0a0b0c0d-1111-2222-3333-000000000004';
    $adminClaims['email'] = 'boss2@contoso.example';
    $result = $linker->resolveLogin($adminClaims, $linkSettings(['auto_provision' => '1', 'role_mapping' => $mapping, 'allow_admin_mapping' => '1']), [], $roles);
    T::same('admin', $store->users[$result['user_id']]->role ?? null, 'Admin nur mit ausdrücklicher Erlaubnis');

    $store->failIdentityInsert = true;
    $before = count($store->users);
    $race = $linker->resolveLogin($claims(['oid' => '0a0b0c0d-1111-2222-3333-000000000005', 'email' => 'race@contoso.example']), $linkSettings(['auto_provision' => '1']), [], $roles);
    T::same(['provision_failed', $before], [$race['reason'] ?? null, count($store->users)], 'Bindung scheitert → angelegtes Konto wird entfernt');

    T::same('member', EntraSettings::normalize(['default_role' => 'admin'], $roles)['default_role'], 'Standardrolle nie admin');
    T::same(null, IdentityLinker::mapRole(['roles' => ['CMS.Admin']], $linkSettings(['role_mapping' => $mapping]), $roles), 'mapRole: Admin-Regel ohne Erlaubnis übersprungen');
    T::check(IdentityLinker::hasGroupOverage(['_claim_names' => ['groups' => 'src1']]), 'Gruppen-Overage erkannt');
});

T::run('Rollen-Synchronisation bestehender Konten', static function () use ($claims, $linkSettings, $roles): void {
    $mapping = [['type' => 'role', 'value' => 'CMS.Editor', 'role' => 'editor'], ['type' => 'role', 'value' => 'CMS.Admin', 'role' => 'admin']];
    $store = new MemoryIdentityStore();
    $store->addUser(5, 'erika@contoso.example', 'editor');
    $store->createIdentity(5, 'entra', TENANT, OID, '', true);
    $linker = new IdentityLinker($store);

    $result = $linker->resolveLogin($claims(), $linkSettings(['sync_roles' => '1', 'role_mapping' => $mapping]), [], $roles);
    T::same([['from' => 'editor', 'to' => 'member'], 'member'], [$result['role_change'] ?? null, $store->users[5]->role], 'Herabstufen ohne passende Regel');
    $linker->resolveLogin($claims(['roles' => ['CMS.Admin']]), $linkSettings(['sync_roles' => '1', 'role_mapping' => $mapping]), [], $roles);
    T::same('member', $store->users[5]->role, 'Hochstufen auf admin ohne Erlaubnis verhindert');
    $linker->resolveLogin($claims(['roles' => ['CMS.Admin']]), $linkSettings(['sync_roles' => '1', 'role_mapping' => $mapping, 'allow_admin_mapping' => '1']), [], $roles);
    T::same('admin', $store->users[5]->role, 'Hochstufen mit Erlaubnis');
    $linker->resolveLogin($claims(['roles' => ['CMS.Editor']]), $linkSettings(['role_mapping' => $mapping]), [], $roles);
    T::same('admin', $store->users[5]->role, 'ohne „synchronisieren“ keine Änderung');

    $manual = new MemoryIdentityStore();
    $manual->addUser(6, 'erika@contoso.example', 'editor');
    $manual->createIdentity(6, 'entra', TENANT, OID, '', false);
    (new IdentityLinker($manual))->resolveLogin($claims(), $linkSettings(['sync_roles' => '1', 'role_mapping' => $mapping]), [], $roles);
    T::same('editor', $manual->users[6]->role, 'manuell verknüpfte Konten werden nie synchronisiert');
});

T::run('Aktive Verknüpfung', static function () use ($claims): void {
    $store = new MemoryIdentityStore();
    $store->addUser(1, 'admin@contoso.example', 'admin');
    $store->addUser(2, 'erika@contoso.example', 'member');
    $linker = new IdentityLinker($store);

    T::same('linked', $linker->link($claims(['email' => null]), 1)['status'], 'angemeldetes Admin-Konto verknüpft aktiv (auch ohne email)');
    T::same('already_linked', $linker->link($claims(), 1)['status'], 'gleiche Identität erneut');
    T::same('identity_taken', $linker->link($claims(), 2)['reason'] ?? null, 'Identität gehört schon einem anderen Konto');
    T::same('user_has_identity', $linker->link($claims(['oid' => '0a0b0c0d-1111-2222-3333-999999999999']), 1)['reason'] ?? null, 'Konto hat schon eine andere Entra-Identität');
    $store->users[2]->status = 'inactive';
    T::same('account_inactive', $linker->link($claims(['oid' => '0a0b0c0d-1111-2222-3333-888888888888']), 2)['reason'] ?? null, 'inaktives Konto');
    T::same('subject', $linker->link(['tid' => TENANT, 'oid' => 'x'], 2)['reason'] ?? null, 'ungültige oid');
});

T::run('Einstellungen', static function () use ($roles): void {
    $d = EntraSettings::normalize([]);
    T::same([false, false, false, true, 'all', 'member'], [$d['enabled'], $d['auto_link'], $d['auto_provision'], $d['mfa_from_amr'], $d['local_login'], $d['default_role']], 'Standard: aus, keine Automatik, MFA aus amr an');
    $n = EntraSettings::normalize([
        'tenant_id' => ' 11111111-2222-3333-4444-555555555555 ',
        'client_id' => 'not-a-guid',
        'allowed_domains' => "@Contoso.example\nbad_domain\nfabrikam.example",
        'local_login' => 'nobody',
        'client_secret_expires' => '2026-02-30',
        'role_mapping' => [
            ['type' => 'group', 'value' => 'kein-guid', 'role' => 'editor'],
            ['type' => 'role', 'value' => 'CMS.Editor', 'role' => 'unbekannt'],
            ['type' => 'role', 'value' => 'CMS Editor <x>', 'role' => 'editor'],
            ['type' => 'role', 'value' => 'CMS.Editor', 'role' => 'editor'],
            ['type' => 'claim', 'value' => 'x', 'role' => 'editor'],
        ],
        'button_label' => '<b>Login</b>',
    ], $roles);
    T::same(TENANT, $n['tenant_id'], 'GUID getrimmt');
    T::same('', $n['client_id'], 'ungültige Client-ID verworfen');
    T::same(['contoso.example', 'fabrikam.example'], $n['allowed_domains'], 'Domains bereinigt');
    T::same('all', $n['local_login'], 'unbekannter Modus → all');
    T::same('', $n['client_secret_expires'], 'ungültiges Datum verworfen');
    T::same([['type' => 'role', 'value' => 'CMS.Editor', 'role' => 'editor']], $n['role_mapping'], 'nur gültige Mapping-Regeln');
    T::same('Login', $n['button_label'], 'Beschriftung ohne Markup');
    T::check(!array_key_exists('client_secret', EntraSettings::normalize(['client_secret' => 'x'])), 'Secret nie im normalisierten Ergebnis');

    $ready = EntraSettings::normalize(['enabled' => '1', 'tenant_id' => TENANT, 'client_id' => CLIENT, 'client_secret_set' => true]);
    T::check(EntraSettings::isReady($ready), 'bereit mit Tenant, Client-ID und Secret');
    T::check(!EntraSettings::isReady(['client_secret_set' => false] + $ready), 'ohne Secret nicht bereit');
    $mode = static fn (string $m): array => ['local_login' => $m] + $ready;
    T::same([true, true, true], [
        EntraSettings::passwordLoginAllowed($mode('all'), false, true),
        EntraSettings::passwordLoginAllowed($mode('disabled_for_linked'), false, false),
        EntraSettings::passwordLoginAllowed($mode('disabled_for_linked'), true, true),
    ], 'Passwort-Login: all; nicht verknüpft; Admin-Notfallzugang');
    T::same([false, false, true], [
        EntraSettings::passwordLoginAllowed($mode('disabled_for_linked'), false, true),
        EntraSettings::passwordLoginAllowed($mode('admins_only'), false, false),
        EntraSettings::passwordLoginAllowed($mode('admins_only'), true, false),
    ], 'Passwort-Login: verknüpft gesperrt; admins_only');
    T::check(EntraSettings::passwordLoginAllowed(['local_login' => 'admins_only', 'enabled' => false] + $ready, false, true), 'ohne eingerichtete SSO-Anmeldung nie gesperrt');

    T::same(9, EntraSettings::secretDaysLeft(['client_secret_expires' => '2027-01-10'], strtotime('2026-12-31 12:00:00 UTC')), 'Tage bis zum Ablauf');
    T::same(null, EntraSettings::secretExpiryNotice(['enabled' => true, 'client_secret_expires' => '2027-06-01'], strtotime('2026-12-31 UTC')), 'kein Hinweis bei > 30 Tagen');
    T::same('warning', EntraSettings::secretExpiryNotice(['enabled' => true, 'client_secret_expires' => '2027-01-10'], strtotime('2026-12-31 UTC'))['severity'] ?? null, 'Hinweis 30 Tage vorher');
    T::same('danger', EntraSettings::secretExpiryNotice(['enabled' => true, 'client_secret_expires' => '2026-12-01'], strtotime('2026-12-31 UTC'))['severity'] ?? null, 'abgelaufen');
    T::same('https://cms.example.test/cms-login/sso/entra/callback', EntraSettings::redirectUri(), 'Redirect-URI');
});

T::run('Sitzung: frische SSO-Anmeldung, Zwischenseite', static function (): void {
    $_SESSION = [EntraSsoService::SESSION_SSO_USER => 7, EntraSsoService::SESSION_SSO_AT => NOW - 300];
    T::check(EntraSsoService::isFreshSsoSession(7, NOW), 'SSO vor 5 Minuten → frisch');
    T::check(!EntraSsoService::isFreshSsoSession(8, NOW), 'anderes Konto');
    T::check(!EntraSsoService::isFreshSsoSession(7, NOW + 400), 'nach 10 Minuten nicht mehr frisch');
    $_SESSION = [];
    T::check(!EntraSsoService::isFreshSsoSession(7, NOW), 'Passwort-Sitzung ohne SSO');

    T::same(['code' => 'abc-DEF_1.2~', 'state' => 'S_t-1', 'hop' => '1'], SsoRouter::hopParameters(['code' => 'abc-DEF_1.2~', 'state' => 'S_t-1', 'redirect' => '//evil.example']), 'Zwischenseite: nur code/state/error');
    T::same(['hop' => '1'], SsoRouter::hopParameters(['code' => 'x"><script>alert(1)</script>', 'state' => ['array'], 'error' => 'Bad Error']), 'Zwischenseite: Werte mit fremden Zeichen fallen weg');
});

T::run('Einbindung (statisch)', static function () use ($root): void {
    $read = static fn (string $file): string => (string) file_get_contents($root . $file);

    $router = $read('core/Router.php');
    T::check(str_contains($router, "str_starts_with(\$this->requestUri, '/cms-login/sso/')") && str_contains($router, "loadRouteModule('SsoRouter')"), 'Router lädt SsoRouter für /cms-login/sso/');
    T::check(substr_count($router, "'/cms-login/sso/entra/callback'") >= 2, 'Callback vom Wartungsmodus ausgenommen und nicht gecacht');

    $sso = $read('core/Routing/SsoRouter.php');
    T::check(str_contains($sso, "(string) (\$_GET['hop'] ?? '') !== '1'") && str_contains($sso, 'http-equiv="refresh"'), 'Callback: Zwischenseite wegen SameSite=Strict');
    T::check(str_contains($sso, "'https://' . EntraIdProvider::HOST . '/'"), 'Weiterleitung nur zum Anmeldehost');
    T::check(str_contains($sso, 'safeRedirectTarget(') && str_contains($sso, 'successfulLoginTarget('), 'Rücksprungziel mit der Login-Prüfung (nur lokal)');
    T::check(!preg_match('/<script|\son[a-z]+=/i', $sso), 'Zwischenseite ohne Skript/Handler');

    $service = $read('core/Auth/SSO/EntraSsoService.php');
    $rate = strpos($service, 'Security::checkDbRateLimit($ip, self::RATE_LIMIT_ACTION');
    $state = strpos($service, '(new OidcFlowState())->consume(');
    $exchange = strpos($service, '->exchangeCode(');
    $verify = strpos($service, '->verifyIdToken(');
    T::check($rate !== false && $state !== false && $exchange !== false && $verify !== false && $rate < $state && $state < $exchange && $exchange < $verify, 'Reihenfolge: Rate-Limit → state → Code → ID-Token');
    T::check(str_contains($service, 'completeLoginForUserId($userId)'), 'Sitzung über completeLoginForUserId (neue Session-ID)');
    T::check(preg_match('/if \(\$mfaProof\) \{\s*\$_SESSION\[MfaEnforcementService::SESSION_SSO_MFA\] = \$userId;/', $service) === 1, 'SSO-MFA-Flag nur mit Nachweis aus amr');
    T::check(str_contains($service, "!\$mfaProof && \$auth->isMfaEnabled(\$userId)"), 'ohne Nachweis lokales TOTP abfragen');
    T::check(str_contains($service, 'unset($_SESSION[MfaEnforcementService::SESSION_PASSWORD_CHANGE])'), 'SSO setzt kein Passwort-Ablauf-Flag');
    T::check(!preg_match('/audit\([^;]*id_token/', $service) && !str_contains($service, "set(EntraSettings::GROUP, 'id_token'"), 'Tokens werden nicht protokolliert oder gespeichert');
    T::check(str_contains($service, "Auth::instance()->currentUser()") && str_contains($service, "\$userId !== (int) \$flow['user_id']"), 'Verknüpfen nur für das Konto, das den Vorgang gestartet hat');

    $auth = $read('core/Auth.php');
    T::check(str_contains($auth, "unset(\$_SESSION['auth_sso_mfa_user_id'], \$_SESSION['auth_sso_user_id'], \$_SESSION['auth_sso_at']);"), 'jede Anmeldung setzt SSO-Merkmale zurück');
    T::check(str_contains($auth, '$this->isPasswordLoginAllowedForUser($user)') && str_contains($auth, 'EntraSettings::passwordLoginAllowed('), 'Passwort-Login beachtet „Lokale Anmeldung“');

    $public = $read('core/Routing/PublicRouter.php');
    T::check(str_contains($public, "\$currentPassword === '' && \\CMS\\Auth\\SSO\\EntraSsoService::isFreshSsoSession(\$userId)"), '/mfa-setup: frische SSO-Anmeldung als Re-Auth');

    $controller = $read('member/includes/class-member-controller.php');
    T::check(str_contains($controller, "'sso_link', 'sso_unlink' => 'security_sso'"), 'Mitgliederbereich: eigenes CSRF-Token für Verknüpfen/Trennen');
    T::check(str_contains($controller, 'startUrl(OidcFlowState::PURPOSE_LINK, \'/member/security\', $userId)'), 'Verknüpfen startet den Fluss für das eigene Konto');

    $settingsPhp = $read('admin/settings.php');
    T::check(str_contains($settingsPhp, "'sso' => 'sso.php'"), 'Admin: Tab ?tab=sso registriert');
    $tab = $read('admin/settings-tabs/sso.php');
    T::check(str_contains($tab, "CMS_ADMIN_SSO_CAPABILITY = 'security.sso'") && str_contains($tab, 'hasCapability(CMS_ADMIN_SSO_CAPABILITY)'), 'Admin: Capability security.sso');
    T::check(str_contains($tab, 'verifyToken((string) ($_POST[\'csrf_token\'] ?? \'\'), CMS_ADMIN_SSO_CSRF_ACTION)') && str_contains($tab, 'CMS_ADMIN_SSO_TEST_CSRF_ACTION)'), 'Admin: CSRF je Formular');
    $settingsClass = $read('core/Auth/SSO/EntraSettings.php');
    T::check(str_contains($settingsClass, "->set(self::GROUP, 'client_secret', \$secret, true)"), 'Client-Secret verschlüsselt gespeichert');
    $view = $read('admin/views/settings/sso.php');
    T::check(!str_contains($view, "\$ssoSettings['client_secret']") && !preg_match('/<script|\son[a-z]+=/i', $view), 'Admin-Ansicht gibt das Secret nie aus, ohne Inline-Skript');
    T::check(is_file($root . 'assets/js/admin-sso-settings.js'), 'Mapping-Tabelle über eigenes Skript');

    $login = $read('views/auth/cms-auth.php');
    T::check(str_contains($login, "!empty(\$ssoLogin['available'])"), 'Loginseite: Knopf „Mit Microsoft anmelden“');

    foreach (['de', 'en'] as $lang) {
        $yaml = $read("lang/{$lang}.yaml");
        foreach (['sso_settings.title', 'sso_settings.auto_link_hint', 'sso_settings.test.ok', 'member.security.sso_link', 'member.security.sso_fresh_hint'] as $key) {
            T::check(str_contains($yaml, "\"{$key}\":"), "{$lang}: {$key}");
        }
    }
});

T::finish();
