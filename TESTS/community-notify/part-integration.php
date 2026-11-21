<?php
/**
 * Integrationsfälle über Einheitsgrenzen für die Dienste der Interaktion (PR #2b, J2; Vertrag DESIGN-2b §6 J2, Fassung 3.1 GK6).
 * Besitzer: J2.
 *
 *  - N `$throttle` von `SubscriptionService` (`subscribe`, 30 je 60 s), `ReactionService` (`react`, 30 je 60 s) und
 *    `NotificationDispatcher` (`react_notify`, 10 je 3.600 s je Akteur) gegen den echten `ContentGuard::throttle()`.
 *  - N `$secret` von `SubscriptionService` ist `IpHash::defaultSecret()` (Wirkung mit Konstanten in `community-spam`).
 *
 * Geladen von `run.php` am Ende; nutzt `CnWorld` und `CommunityWiring` (TESTS/lib/CommunityTestDatabase.php).
 *
 * @package CMSv2\Core\Services\Community
 */

declare(strict_types=1);

use CMS\Services\Community\Service\AccessDecision;
use CMS\Services\Community\Service\NotificationDispatcher;
use CMS\Services\Community\Service\ReactionService;
use CMS\Services\Community\Service\SubscriptionService;

T::run('Integration (J2): Abo, Reaktion und Reaktionsauftrag über die Standard-Drossel gegen ContentGuard::throttle()', static function (): void {
    $w = new CnWorld();
    $member = $w->member();
    $clock = static fn (): int => (int) $w->time['now'];
    $can = static fn (?int $u, string $a, array $c): AccessDecision => $w->access->can($u, $a, $c);
    $quiet = static function (string $hook, mixed ...$args): void {
    };

    $subs = new SubscriptionService($w->db, $w->settings, $clock, $can, $quiet, null, static fn (): ?string => CN_SECRET, static fn (): string => 'de');
    $wiring = new CommunityWiring();
    $results = $wiring->run(static function () use ($subs, $member, $w): array {
        $out = [];
        for ($i = 1; $i <= 31; $i++) {
            $out[] = $subs->subscribe($member, 'topic', $w->topic, $i % 2 === 1 ? 'instant' : 'none');
        }

        return $out;
    });
    T::same(array_fill(0, 30, true), array_map(static fn (array $r): mixed => $r['success'] ?? null, array_slice($results, 0, 30)), 'subscribe: 30 je 60 s');
    T::same(['success' => false, 'error' => 'rate_limited', 'retry_after' => 60], $results[30], '31. subscribe: rate_limited');
    T::same(['community_subscribe community:u:' . $member . ' 30/60'], $wiring->buckets(), 'subscribe: Aktion, Kennung des Mitglieds, Grenze aus ContentGuard::THROTTLE');

    $reactions = new ReactionService($w->db, $w->settings, $clock, $can, $quiet, null);
    $kinds = array_values($reactions->available());
    T::check(count($kinds) >= 2, 'mindestens zwei Reaktionen verfügbar', json_encode($kinds));
    $wiring = new CommunityWiring();
    $results = $wiring->run(static function () use ($reactions, $member, $w, $kinds): array {
        $out = [];
        for ($i = 1; $i <= 31; $i++) {
            $out[] = $reactions->set($member, $w->first, $kinds[$i % 2]);
        }

        return $out;
    });
    T::same(array_fill(0, 30, true), array_map(static fn (array $r): mixed => $r['success'] ?? null, array_slice($results, 0, 30)), 'react: 30 je 60 s');
    T::same(['success' => false, 'error' => 'rate_limited', 'retry_after' => 60], $results[30], '31. Reaktion: rate_limited');
    T::same(['community_react community:u:' . $member . ' 30/60'], $wiring->buckets(), 'react: Aktion, Kennung des Mitglieds, Grenze aus ContentGuard::THROTTLE');

    $dispatcher = new NotificationDispatcher($w->db, $w->settings, $clock, $can, subscriptions: $subs);
    $before = $w->db->count('community_notify_queue', ['kind' => 'reaction']);
    $wiring = new CommunityWiring();
    $wiring->run(static function () use ($dispatcher, $member, $w, $kinds): void {
        for ($i = 1; $i <= 11; $i++) {
            $dispatcher->onReactionChanged($w->first, $member, $kinds[0], '');
        }
        $dispatcher->onReactionChanged($w->first, $member, $kinds[1], $kinds[0]);
    });
    T::same(10, $w->db->count('community_notify_queue', ['kind' => 'reaction']) - $before, 'react_notify: 10 Reaktionsaufträge je Stunde und Akteur, der 11. entfällt');
    T::same(['community_react_notify community:u:' . $member . ' 10/3600'], $wiring->buckets(), 'react_notify: Kennung des Akteurs, Grenze 10 je 3.600 s; ein Wechsel fragt die Drossel nicht');
    T::same(11, count($wiring->calls), 'genau ein Limiter-Aufruf je neuer Reaktion');
});

T::run('Integration (J2): Standard-Geheimnis von SubscriptionService ist IpHash::defaultSecret()', static function (): void {
    $source = (string) file_get_contents(ABSPATH . 'core/Services/Community/Service/SubscriptionService.php');
    T::same([1, 0], [substr_count($source, '$this->secret = $secret ?? static fn (): ?string => IpHash::defaultSecret();'), substr_count($source, "'NONCE_KEY'")], 'Standard über IpHash::defaultSecret(), keine eigene Liste der Geheimnisse in SubscriptionService');
});
