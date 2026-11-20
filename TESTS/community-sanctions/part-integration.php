<?php
/**
 * Integrationsfall über Einheitsgrenzen für `ReportService` (PR #2b, J2; Vertrag DESIGN-2b §6 J2, Fassung 3.1 GK6). Besitzer: J2.
 *
 *  - K `$autoModerate`: die Standard-Closure ruft den echten `ModerationService::instance()->systemAction()` (Einheit M) auf:
 *    Schwelle ⇒ `hide` mit `details.reports`, Akteur 0 (Protokoll `actor_id` NULL), Zähler über CounterBook (Orakel `communityAudit()`);
 *    `invalid` ohne Restmeldung ⇒ `restore`, nur nach automatischem Ausblenden. Beides nach dem Commit von `create`/`resolve`.
 *
 * Geladen von `run.php` am Ende; nutzt `CsnWorld` und `CommunityWiring::swap()` (TESTS/lib/CommunityTestDatabase.php).
 *
 * @package CMSv2\Core\Services\Community
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/CommunityAudit.php';

use CMS\Services\Community\Service\AccessDecision;
use CMS\Services\Community\Service\ModerationService;
use CMS\Services\Community\Service\ReportService;
use CMS\Services\Community\Support\Events;

T::run('Integration (J2): ReportService-Schwelle und Erledigen über ModerationService::systemAction() (Standard-Closure)', static function (): void {
    $w = new CsnWorld();
    $clock = static fn (): int => (int) $w->time['now'];
    $can = static fn (?int $u, string $a, array $c): AccessDecision => $w->access->can($u, $a, $c);
    $hooks = [];
    $dispatch = static function (string $hook, mixed ...$args) use (&$hooks, $w): void {
        $hooks[] = [$hook, $args, $w->db->getPdo()->inTransaction()];
    };
    $moderation = new ModerationService($w->db, $w->settings, $clock, $can, $dispatch, static fn (?int $u, array $c = []): array => $w->access->moderationScope($u, $c), null);
    $reports = new ReportService($w->db, $w->settings, $clock, $can, $dispatch, $w->access, null);
    $previous = CommunityWiring::swap(ModerationService::class, $moderation);
    try {
        [$a, $b, $c] = [$w->member('member', 2), $w->member('member', 2), $w->member('member', 3)];
        $mod = $w->member('moderator');
        communityRecount($w->db, csnUtc());
        $reports->create($a, 'post', $w->reply, 'spam');
        $reports->create($b, 'post', $w->reply, 'spam');
        $hooks = [];
        $third = $reports->create($c, 'post', $w->reply, 'spam');
        T::same([true, true], [$third['success'] ?? null, $third['auto_hidden'] ?? null], 'dritter vertrauenswürdiger Melder: auto_hidden über systemAction(hide)');
        T::same('hidden', $w->db->row('community_posts', ['id' => $w->reply])['status'] ?? null, 'Beitrag ausgeblendet');
        $log = $w->db->rows('community_moderation_log', [], 'id');
        $hide = end($log);
        T::same([null, 'post.hide', $w->reply, 'reports', 3], [$hide['actor_id'], $hide['action'], (int) $hide['target_id'], json_decode((string) $hide['details_json'], true)['auto'] ?? null, json_decode((string) $hide['details_json'], true)['reports'] ?? null], 'Protokoll: actor_id NULL, post.hide, auto, reports = 3 (Detail von ReportService)');
        $hidden = array_values(array_filter($hooks, static fn (array $h): bool => $h[0] === Events::POST_HIDDEN));
        T::same([[Events::POST_HIDDEN, [$w->reply, $w->topic, 0, (int) $hide['id']], false]], $hidden, 'POST_HIDDEN mit Akteur 0 und logId, nach dem Commit');
        T::same([], communityAudit($w->db, csnUtc()), 'Orakel nach dem Ausblenden: Zähler über CounterBook gebucht');

        $open = array_map('intval', array_column($w->db->rows('community_reports', ['target_id' => $w->reply, 'status' => 'open'], 'id'), 'id'));
        $w->at(60);
        $resolved = $reports->resolve($mod, (int) end($open), 'invalid');
        T::same([true, false, true], [$resolved['changed'] ?? null, $resolved['remaining'] ?? null, $resolved['restored'] ?? null], 'invalid ohne Restmeldung: restored über systemAction(restore)');
        T::same('published', $w->db->row('community_posts', ['id' => $w->reply])['status'] ?? null, 'Beitrag wiederhergestellt');
        $log = $w->db->rows('community_moderation_log', [], 'id');
        $restore = end($log);
        T::same([null, 'post.restore', $w->reply], [$restore['actor_id'], $restore['action'], (int) $restore['target_id']], 'Protokoll: post.restore mit actor_id NULL als letzte Zeile');
        T::same([], communityAudit($w->db, csnUtc(60)), 'Orakel nach dem Wiederherstellen');

        // Gegenprobe: von Hand ausgeblendet ⇒ kein automatisches Wiederherstellen
        [, , $manual] = $w->thread($w->forum, $w->author);
        communityRecount($w->db, csnUtc(60));
        $id = (int) $reports->create($a, 'post', $manual, 'spam')['report_id'];
        T::same(true, $moderation->hidePost($mod, $manual, 'von Hand')['success'] ?? null, 'Moderator blendet von Hand aus');
        $again = $reports->resolve($mod, $id, 'invalid');
        T::same([true, false, 'hidden'], [$again['changed'] ?? null, $again['restored'] ?? null, $w->db->row('community_posts', ['id' => $manual])['status'] ?? null], 'von Hand ausgeblendet: systemAction(restore) ändert nichts');
    } finally {
        CommunityWiring::swap(ModerationService::class, $previous);
    }
});
