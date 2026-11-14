<?php
/**
 * Zustand eines OIDC-Anmeldeflusses in der Sitzung (4.1.0, Bereich 1c): `state`, `nonce` und PKCE-`code_verifier`
 * (je 32 Zufallsbytes), Zweck (`login`/`link`), Rücksprungziel und – beim Verknüpfen – das Konto.
 *
 * Jeder Eintrag ist 10 Minuten gültig und wird beim ersten Einlösen entfernt, auch wenn er abgelaufen ist
 * (Einmalverwendung). In der Sitzung liegt nur der SHA-256 des `state` als Schlüssel; höchstens fünf parallele
 * Flüsse (mehrere Tabs), der älteste fällt zuerst weg.
 *
 * @package CMSv2\Core\Auth\SSO
 */

declare(strict_types=1);

namespace CMS\Auth\SSO;

if (!defined('ABSPATH')) {
    exit;
}

final class OidcFlowState
{
    public const SESSION_KEY = 'sso_oidc_flows';
    public const TTL = 600;
    public const MAX_PARALLEL = 5;

    public const PURPOSE_LOGIN = 'login';
    public const PURPOSE_LINK = 'link';

    /** @var \Closure(): int */
    private \Closure $clock;

    public function __construct(?\Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): int => time();
    }

    /**
     * Neuer Fluss. Gibt die Werte für die Autorisierungs-URL zurück (der `code_verifier` bleibt in der Sitzung).
     *
     * @return array{state:string, nonce:string, code_challenge:string}
     */
    public function begin(string $purpose, string $redirect, int $linkUserId = 0, string $provider = EntraSettings::PROVIDER): array
    {
        if (!in_array($purpose, [self::PURPOSE_LOGIN, self::PURPOSE_LINK], true)) {
            throw new \InvalidArgumentException('purpose');
        }
        if ($purpose === self::PURPOSE_LINK && $linkUserId <= 0) {
            throw new \InvalidArgumentException('link user');
        }

        $state = self::randomToken();
        $nonce = self::randomToken();
        $verifier = self::randomToken();
        $now = ($this->clock)();

        $flows = $this->prune($this->flows(), $now);
        while (count($flows) >= self::MAX_PARALLEL) {
            array_shift($flows);
        }
        $flows[hash('sha256', $state)] = [
            'provider' => $provider,
            'purpose' => $purpose,
            'nonce' => $nonce,
            'verifier' => $verifier,
            'redirect' => $redirect,
            'user_id' => $purpose === self::PURPOSE_LINK ? $linkUserId : 0,
            'created' => $now,
        ];
        $_SESSION[self::SESSION_KEY] = $flows;

        return [
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => self::codeChallenge($verifier),
        ];
    }

    /**
     * Löst den Fluss zum `state` ein (immer einmalig).
     *
     * @return array{ok:bool, reason:string, flow?:array{provider:string,purpose:string,nonce:string,verifier:string,redirect:string,user_id:int,created:int}}
     */
    public function consume(mixed $state): array
    {
        if (!is_string($state) || $state === '' || strlen($state) > 128 || preg_match('/^[A-Za-z0-9_-]+$/', $state) !== 1) {
            return ['ok' => false, 'reason' => 'state_missing'];
        }

        $now = ($this->clock)();
        $flows = $this->flows();
        $key = hash('sha256', $state);
        $flow = $flows[$key] ?? null;
        unset($flows[$key]);
        $_SESSION[self::SESSION_KEY] = $this->prune($flows, $now);

        if (!is_array($flow)) {
            return ['ok' => false, 'reason' => 'state_unknown'];
        }
        if ((int) ($flow['created'] ?? 0) < $now - self::TTL || (int) ($flow['created'] ?? 0) > $now + 60) {
            return ['ok' => false, 'reason' => 'state_expired'];
        }

        return [
            'ok' => true,
            'reason' => '',
            'flow' => [
                'provider' => (string) ($flow['provider'] ?? ''),
                'purpose' => (string) ($flow['purpose'] ?? ''),
                'nonce' => (string) ($flow['nonce'] ?? ''),
                'verifier' => (string) ($flow['verifier'] ?? ''),
                'redirect' => (string) ($flow['redirect'] ?? ''),
                'user_id' => (int) ($flow['user_id'] ?? 0),
                'created' => (int) ($flow['created'] ?? 0),
            ],
        ];
    }

    /** RFC 7636 S256: BASE64URL(SHA256(code_verifier)). */
    public static function codeChallenge(string $verifier): string
    {
        return self::base64Url(hash('sha256', $verifier, true));
    }

    public static function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private static function randomToken(): string
    {
        return self::base64Url(random_bytes(32));
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function flows(): array
    {
        $flows = $_SESSION[self::SESSION_KEY] ?? [];

        return is_array($flows) ? array_filter($flows, 'is_array') : [];
    }

    /**
     * @param array<string, array<string, mixed>> $flows
     * @return array<string, array<string, mixed>>
     */
    private function prune(array $flows, int $now): array
    {
        return array_filter($flows, static fn (array $flow): bool => (int) ($flow['created'] ?? 0) >= $now - self::TTL);
    }
}
