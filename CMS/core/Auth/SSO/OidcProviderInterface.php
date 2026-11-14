<?php
/**
 * OpenID-Connect-Anbieter für den Authorization Code Flow mit PKCE (4.1.0, Bereich 1c).
 *
 * @package CMSv2\Core\Auth\SSO
 */

declare(strict_types=1);

namespace CMS\Auth\SSO;

if (!defined('ABSPATH')) {
    exit;
}

interface OidcProviderInterface
{
    /**
     * @param array<string, string> $extra zusätzliche Parameter (z. B. `prompt`)
     */
    public function authorizationUrl(string $state, string $nonce, string $codeChallenge, string $redirectUri, array $extra = []): string;

    /**
     * Tauscht den Code gegen Tokens. Zurück kommt nur das ID-Token; weitere Tokens werden verworfen.
     *
     * @return array{ok:bool, id_token?:string, error?:string, description?:string}
     */
    public function exchangeCode(string $code, string $codeVerifier, string $redirectUri): array;

    /**
     * Prüft Signatur und Pflicht-Claims des ID-Tokens.
     *
     * @return array{ok:bool, claims?:array<string, mixed>, error?:string}
     */
    public function verifyIdToken(string $idToken, string $expectedNonce): array;
}
