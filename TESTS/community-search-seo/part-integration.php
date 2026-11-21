<?php
/**
 * Integrationsfälle über Einheitsgrenzen für `CommunitySearch` (PR #2b, J2; Vertrag DESIGN-2b §6 J2, Fassung 3.1 GK6). Besitzer: J2.
 *
 *  - D `$throttle`: die Standard-Closure läuft gegen den echten `ContentGuard::throttle('search', …)`: Gäste 10 je 60 s je Kennung
 *    aus `IpHash::rateKey()`, dazu der gemeinsame Eimer `community:g:all` (120 je 60 s), Mitglieder 30 je 60 s.
 *  - D `$secret`: der Standard ist `IpHash::defaultSecret()` (keine eigene Reihenfolge der Konstanten).
 *
 * Geladen von `run.php` am Ende; nutzt `CommunityWiring` (TESTS/lib/CommunityTestDatabase.php).
 *
 * @package CMSv2\Core\Services\Community
 */

declare(strict_types=1);

use CMS\Services\Community\Service\AccessDecision;
use CMS\Services\Community\Service\CommunitySearch;
use CMS\Services\Community\Support\IpHash;
use CMS\Services\Community\Support\Settings;

T::run('Integration (J2): CommunitySearch::search über die Standard-Drossel gegen ContentGuard::throttle()', static function (): void {
    $db = CommunityTestDatabase::create();
    $member = $db->user();
    $search = new CommunitySearch(
        $db,
        Settings::fromArray([]),
        static fn (): int => 1767225600,
        static fn (?int $u, string $a, array $c): AccessDecision => AccessDecision::allow(),
        static fn (?int $u): array => [],
        null,
        static fn (): ?string => null,
        locale: 'de'
    );
    $wiring = new CommunityWiring(['web' => true, 'ip' => '203.0.113.9', 'user_agent' => 'J2']);
    $run = static fn (?int $userId, int $times): array => $wiring->run(static function () use ($search, $userId, $times): array {
        $out = [];
        for ($i = 1; $i <= $times; $i++) {
            $out[] = $search->search($userId, 'Forum Integration');
        }

        return $out;
    });

    $guest = $run(null, 11);
    T::same(array_fill(0, 10, [true, 'unavailable']), array_map(static fn (array $r): array => [$r['success'] ?? null, $r['source'] ?? null], array_slice($guest, 0, 10)), 'Gast: 10 Suchen je 60 s (ohne Index: unavailable)');
    T::same(['rate_limited', 60], [$guest[10]['error'] ?? null, $guest[10]['retry_after'] ?? null], '11. Suche des Gasts: rate_limited mit retry_after = Fenster');
    $buckets = $wiring->buckets();
    T::same(2, count($buckets), 'Gast: zwei Eimer', json_encode($buckets));
    T::check(preg_match('~^community_search community:g:[0-9a-f]{40} 10/60$~', $buckets[0] ?? '') === 1, 'Gast: Kennung aus IpHash::rateKey(), Grenze 10 je 60 s', $buckets[0] ?? '');
    T::same('community_search community:g:all 120/60', $buckets[1] ?? null, 'Gast: gemeinsamer Eimer community:g:all mit SEARCH_GUESTS_TOTAL');

    $wiring->calls = [];
    $members = $run($member, 31);
    T::same(array_fill(0, 30, true), array_map(static fn (array $r): mixed => $r['success'] ?? null, array_slice($members, 0, 30)), 'Mitglied: 30 Suchen je 60 s');
    T::same(['rate_limited', 60], [$members[30]['error'] ?? null, $members[30]['retry_after'] ?? null], '31. Suche des Mitglieds: rate_limited');
    T::same(['community_search community:u:' . $member . ' 30/60'], $wiring->buckets(), 'Mitglied: nur die eigene Kennung, kein Gäste-Eimer');
});

T::run('Integration (J2): Standard-Geheimnis von CommunitySearch ist IpHash::defaultSecret()', static function (): void {
    $search = new CommunitySearch(CommunityTestDatabase::create(), Settings::fromArray([]));
    $secret = (new \ReflectionProperty(CommunitySearch::class, 'secret'))->getValue($search);
    T::same([SECURE_AUTH_KEY, SECURE_AUTH_KEY], [IpHash::defaultSecret(), $secret()], 'Standard: erste brauchbare Konstante über IpHash (NONCE_KEY ist der Platzhalter)');
    $source = (string) file_get_contents(ABSPATH . 'core/Services/Community/Service/CommunitySearch.php');
    T::same([1, 0], [substr_count($source, '$this->secret = $secret ?? static fn (): ?string => IpHash::defaultSecret();'), substr_count($source, "'NONCE_KEY'")], 'Standard über IpHash::defaultSecret(), keine eigene Liste der Geheimnisse in CommunitySearch');
});
