<?php
/**
 * Worker-Aktionen der Einheit M (PR #2b, W2) für `TESTS/community-db/sections-moderation.php` (geladen von `worker.php`). Jede Aktion
 * ruft `ModerationService` auf der Verbindung des Workers mit der echten `CommunityAccess` des Workers (Rechte und Moderationsbereich).
 *
 *  mod.approve-post   approvePost(user, post)
 *  mod.approve-topic  approveTopic(user, topic)
 *  mod.hide-topic     hideTopic(user, topic, 'Wettlauf')
 *  mod.hide-toggle    `rounds`-mal hideTopic und restoreTopic(user, topic) im Wechsel; Ergebnis: alle Teilergebnisse
 *  mod.move-topic     moveTopic(user, topic, forum, stub)
 *  mod.move-posts     movePosts(user, posts, topic)
 *  mod.pin            pin(user, topic)
 *  mod.solution       markSolution(user, topic, post)
 *  mod.bulk           bulk(user, operation, ids, params)
 *
 * @package CMSv2\Core\Services\Community
 */

declare(strict_types=1);

use CMS\Services\Community\Repository\ModerationLogRepository;
use CMS\Services\Community\Repository\ReportRepository;
use CMS\Services\Community\Service\CounterBook;
use CMS\Services\Community\Service\ModerationService;

// Klassen vor dem Startsignal laden: sonst streut das Laden nach dem Start die Anfragen, und der Wettlauf wird zufällig seriell.
foreach ([ModerationService::class, CounterBook::class, ModerationLogRepository::class, ReportRepository::class] as $class) {
    class_exists($class);
}

$moderation = new ModerationService(
    $db,
    $settings,
    null,
    $can,
    $dispatch,
    static fn (?int $userId, array $context = []): array => $access->moderationScope($userId, $context),
    null
);

/** Ergebnis eines Aufrufs ohne große Listen (Ausgabe als eine JSON-Zeile). */
$slim = static fn (array $result): array => array_intersect_key($result, array_flip(['success', 'error', 'changed', 'status', 'done', 'failed', 'stub_topic_id', 'retry_after']));

return [
    'mod.approve-post' => static fn (array $args): array => $slim($moderation->approvePost((int) $args['user'], (int) $args['post'])),
    'mod.approve-topic' => static fn (array $args): array => $slim($moderation->approveTopic((int) $args['user'], (int) $args['topic'])),
    'mod.hide-topic' => static fn (array $args): array => $slim($moderation->hideTopic((int) $args['user'], (int) $args['topic'], 'Wettlauf')),
    'mod.hide-toggle' => static function (array $args) use ($moderation, $slim): array {
        $results = [];
        for ($i = 0; $i < (int) ($args['rounds'] ?? 3); $i++) {
            $results[] = $slim($moderation->hideTopic((int) $args['user'], (int) $args['topic'], 'Wettlauf'));
            $results[] = $slim($moderation->restoreTopic((int) $args['user'], (int) $args['topic']));
        }
        $failed = array_values(array_filter($results, static fn (array $r): bool => empty($r['success'])));

        return $failed === [] ? ['success' => true, 'steps' => count($results)] : ['success' => false, 'error' => (string) ($failed[0]['error'] ?? '?'), 'steps' => count($results)];
    },
    'mod.move-topic' => static fn (array $args): array => $slim($moderation->moveTopic((int) $args['user'], (int) $args['topic'], (int) $args['forum'], (bool) ($args['stub'] ?? false))),
    'mod.move-posts' => static fn (array $args): array => $slim($moderation->movePosts((int) $args['user'], array_map('intval', (array) $args['posts']), (int) $args['topic'])),
    'mod.pin' => static fn (array $args): array => $slim($moderation->pin((int) $args['user'], (int) $args['topic'])),
    'mod.solution' => static fn (array $args): array => $slim($moderation->markSolution((int) $args['user'], (int) $args['topic'], (int) $args['post'])),
    'mod.bulk' => static fn (array $args): array => $slim($moderation->bulk((int) $args['user'], (string) $args['operation'], array_map('intval', (array) $args['ids']), (array) ($args['params'] ?? []))),
];
