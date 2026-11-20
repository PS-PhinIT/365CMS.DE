<?php
/**
 * Integrationsfall über Einheitsgrenzen für das Standard-Geheimnis (PR #2b, J2; Vertrag DESIGN-2b §3.6 „Standard wie IpHash“, §6 J2,
 * Fassung 3.1 GK6). Besitzer: J2.
 *
 * `IpHash::defaultSecret()` ist die einzige Stelle der Reihenfolge NONCE_KEY, SECURE_AUTH_KEY, AUTH_KEY. `IpHash` (S), `CommunitySearch`
 * (D, Indexname) und `SubscriptionService` (N, Abmeldelink) nutzen sie als Standard ihres `$secret`; die Zwecke trennt HKDF.
 * Die Konstanten setzt `run.php` (NONCE_KEY Platzhalter, SECURE_AUTH_KEY brauchbar, AUTH_KEY mit `YOUR_`).
 *
 * @package CMSv2\Core\Services\Community
 */

declare(strict_types=1);

use CMS\Services\Community\Service\CommunitySearch;
use CMS\Services\Community\Service\SubscriptionService;
use CMS\Services\Community\Support\IpHash;
use CMS\Services\Community\Support\Settings;

T::run('Integration (J2): ein Standard-Geheimnis für IpHash, CommunitySearch und SubscriptionService', static function (): void {
    T::same(SECURE_AUTH_KEY, IpHash::defaultSecret(), 'IpHash::defaultSecret(): erste brauchbare Konstante (NONCE_KEY ist der Platzhalter)');
    $db = CommunityTestDatabase::create();
    $settings = Settings::fromArray([]);
    $read = static fn (object $service): mixed => ((new \ReflectionProperty($service, 'secret'))->getValue($service))();
    T::same(
        [SECURE_AUTH_KEY, SECURE_AUTH_KEY, SECURE_AUTH_KEY],
        [$read(new IpHash()), $read(new CommunitySearch($db, $settings)), $read(new SubscriptionService($db, $settings))],
        'Standard von $secret: IpHash, CommunitySearch und SubscriptionService lesen dasselbe Geheimnis'
    );
    T::same((new IpHash(static fn (): string => SECURE_AUTH_KEY))->hash('203.0.113.77'), (new IpHash())->hash('203.0.113.77'), 'IpHash ohne Closure: ip_hash mit SECURE_AUTH_KEY');

    $default = new SubscriptionService($db, $settings);
    $explicit = new SubscriptionService($db, $settings, secret: static fn (): string => SECURE_AUTH_KEY);
    $token = $default->token(7, 'all');
    T::same([true, true], [$default->canSign(), is_string($token)], 'SubscriptionService mit Standard-Geheimnis: Abmeldelink signierbar');
    T::same(['user_id' => 7, 'scope' => 'all'], array_intersect_key((array) $explicit->verifyToken((string) $token), ['user_id' => 0, 'scope' => 0]), 'Token des Standards prüft mit SECURE_AUTH_KEY');
    $other = new SubscriptionService($db, $settings, secret: static fn (): string => 'anderes-geheimnis-0123456789');
    T::same(null, $other->verifyToken((string) $token), 'anderes Geheimnis: Token ungültig (Gegenprobe)');
});
