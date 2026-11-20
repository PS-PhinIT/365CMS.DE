<?php
/**
 * Pseudonyme Kennungen aus Client-IPs (PR #2b; Vertrag DESIGN-2b §2.1 Nr. 4, Entwurf schutz §1.1).
 *
 * - `hash()`: HMAC-SHA256 über das Netz der IP (IPv4 /24, IPv6 /48) für `community_posts.ip_hash`. Moderation erkennt damit
 *   Beiträge aus demselben Netz (COM-MOD-16), ohne die Adresse zu speichern.
 * - `rateKey()`: getrennte Kennung je IPv4 bzw. IPv6-/64 für Request-Grenzen von Gästen (`ContentGuard::throttle()`).
 *
 * Schlüssel per HKDF aus dem ersten brauchbaren Geheimnis (`NONCE_KEY`, `SECURE_AUTH_KEY`, `AUTH_KEY`), je Zweck eine eigene Info
 * (Domänentrennung zu FormSpamGuard, CryptoService, Downloads und dem Indexnamen der Suche). Ohne brauchbares Geheimnis gibt es keinen
 * Wert (`null`) und eine Warnung je Prozess, nie einen Rückfallschlüssel: 2^24 IPv4-Netze wären mit vorhersagbarem Schlüssel per
 * Wörterbuch umkehrbar. Das Pseudonym bleibt mit dem Schlüssel aus `config/app.php` umkehrbar (Löschfrist `ip_hash_days`, PR #5).
 *
 * IPv4 in IPv6 (`::ffff:0:0/96`) zählt als IPv4. Leer, ungültig, mit Zonen-ID und die Nulladressen (`0.0.0.0`, `::`; CLI-Wert von
 * `TrustedProxyService`) ergeben `null`. Rein: keine Datenbank, keine Uhr.
 *
 * @package CMSv2\Core\Services\Community
 */

declare(strict_types=1);

namespace CMS\Services\Community\Support;

use CMS\Logger;

if (!defined('ABSPATH')) {
    exit;
}

final class IpHash
{
    /** Hex-Zeichen von `ip_hash` (Spalte CHAR(64)). */
    public const LENGTH = 64;

    /** HKDF-Info des Schlüssels für `ip_hash`. */
    private const INFO = '365cms:community:ip_hash:v1';

    /** HKDF-Info des Schlüssels für Request-Grenzen. */
    private const RATE_INFO = '365cms:community:rate:v1';

    /** Geheimnisse in Vorrangreihenfolge; das erste brauchbare gilt. */
    private const SECRETS = ['NONCE_KEY', 'SECURE_AUTH_KEY', 'AUTH_KEY'];

    /** Mindestlänge eines Geheimnisses in Bytes. */
    private const MIN_SECRET = 16;

    /** Präfix der IPv4-Adressen in IPv6 (`::ffff:0:0/96`). */
    private const MAPPED_PREFIX = "\0\0\0\0\0\0\0\0\0\0\xff\xff";

    private static bool $warned = false;

    /** @var \Closure(): mixed */
    private \Closure $secret;

    /** @var array{0: string, 1: string}|false|null Schlüssel für ip_hash und Rate-Kennung; false = kein Geheimnis; null = noch nicht gelesen */
    private array|false|null $keys = null;

    /**
     * @param (\Closure(): ?string)|null $secret Geheimnis; Standard: erste Konstante aus NONCE_KEY, SECURE_AUTH_KEY, AUTH_KEY mit usableSecret()
     */
    public function __construct(?\Closure $secret = null)
    {
        $this->secret = $secret ?? static function (): ?string {
            foreach (self::SECRETS as $name) {
                $value = defined($name) ? constant($name) : null;
                if (self::usableSecret($value)) {
                    return $value;
                }
            }

            return null;
        };
    }

    /** Netz einer IP: `203.0.113.0/24`, `2001:db8:abcd::/48`; `null` für leere, ungültige und Nulladressen. */
    public static function network(string $ip): ?string
    {
        $packed = self::packed($ip);
        if ($packed === null) {
            return null;
        }

        return strlen($packed) === 4
            ? inet_ntop(substr($packed, 0, 3) . "\0") . '/24'
            : inet_ntop(substr($packed, 0, 6) . str_repeat("\0", 10)) . '/48';
    }

    /** Subjekt der Rate-Kennung: IPv4 voll (`203.0.113.77`), IPv6 als /64 (`2001:db8:abcd:12::/64`); sonst `null`. */
    public static function rateSubject(string $ip): ?string
    {
        $packed = self::packed($ip);
        if ($packed === null) {
            return null;
        }

        return strlen($packed) === 4 ? inet_ntop($packed) : inet_ntop(substr($packed, 0, 8) . str_repeat("\0", 8)) . '/64';
    }

    /** Taugt der Wert als Geheimnis? Text mit mindestens 16 Bytes, ohne Platzhalter des Installers und ohne Präfix `YOUR_`. */
    public static function usableSecret(mixed $value): bool
    {
        return is_string($value)
            && strlen($value) >= self::MIN_SECRET
            && !str_contains($value, 'REPLACE_VIA_INSTALLER')
            && !str_starts_with($value, 'YOUR_');
    }

    /** Wert für `community_posts.ip_hash`: 64 Hex-Zeichen oder `null` (keine gültige IP, kein Geheimnis). */
    public function hash(string $ip): ?string
    {
        $network = self::network($ip);
        $keys = $network === null ? null : $this->keys();

        return $keys === null ? null : hash_hmac('sha256', $network, $keys[0]);
    }

    /** Rate-Kennung eines Gasts: 40 Hex-Zeichen oder `null` (keine gültige IP, kein Geheimnis). */
    public function rateKey(string $ip): ?string
    {
        $subject = self::rateSubject($ip);
        $keys = $subject === null ? null : $this->keys();

        return $keys === null ? null : substr(hash_hmac('sha256', $subject, $keys[1]), 0, 40);
    }

    /** Gepackte Adresse (4 oder 16 Bytes, IPv4 in IPv6 als IPv4) oder `null`. */
    private static function packed(string $ip): ?string
    {
        $ip = trim($ip);
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }
        $packed = inet_pton($ip);
        if ($packed === false) {
            return null;
        }
        if (strlen($packed) === 16 && substr($packed, 0, 12) === self::MAPPED_PREFIX) {
            $packed = substr($packed, 12);
        }
        if ($packed === "\0\0\0\0" || $packed === str_repeat("\0", 16)) {
            return null;
        }

        return $packed;
    }

    /** @return array{0: string, 1: string}|null Schlüssel (einmal je Instanz), `null` ohne brauchbares Geheimnis */
    private function keys(): ?array
    {
        if ($this->keys === null) {
            try {
                $secret = ($this->secret)();
            } catch (\Throwable) {
                $secret = null;
            }
            if (!self::usableSecret($secret)) {
                $this->keys = false;
                self::warnOnce();

                return null;
            }
            $this->keys = [hash_hkdf('sha256', $secret, 32, self::INFO), hash_hkdf('sha256', $secret, 32, self::RATE_INFO)];
        }

        return $this->keys === false ? null : $this->keys;
    }

    private static function warnOnce(): void
    {
        if (self::$warned) {
            return;
        }
        self::$warned = true;
        Logger::instance()->withChannel('community')->warning(
            'IpHash: kein brauchbares Geheimnis (NONCE_KEY, SECURE_AUTH_KEY, AUTH_KEY); ip_hash und Rate-Kennung der Gäste entfallen.'
        );
    }
}
