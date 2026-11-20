<?php
/**
 * Abschnitt von J2 (PR #2b, nach W2) in `TESTS/community-db`: Verdrahtung über Einheitsgrenzen gegen MariaDB (Vertrag DESIGN-2b §6 J2,
 * Fassung 3.1 GK6). Besitzer: J2.
 *
 *  integration-throttle  Standard-Closures `$throttle` von RenderService (R), AttachmentService und CommunitySearch (D), SubscriptionService,
 *                        ReactionService und NotificationDispatcher (N) gegen den echten `ContentGuard::instance()` mit dem Standard-Limiter
 *                        (`Security`, `login_attempts`): je Aktion genau die Grenze aus `ContentGuard::THROTTLE`, danach `rate_limited`
 *                        bzw. kein Auftrag; eine Zeile je erlaubter Anfrage, keine für die abgelehnte (Zählweise §3.13).
 *
 * Die DB-freien Integrationsfälle stehen in `part-integration.php` der Suiten. Geladen von `run.php` am Ende; nutzt `cdbSection()`.
 *
 * @package CMSv2\Core\Services\Community
 */

declare(strict_types=1);

use CMS\Database;
use CMS\SchemaManager;
use CMS\Services\Community\Service\AccessDecision;
use CMS\Services\Community\Service\AttachmentService;
use CMS\Services\Community\Service\CommunitySearch;
use CMS\Services\Community\Service\ContentGuard;
use CMS\Services\Community\Service\NotificationDispatcher;
use CMS\Services\Community\Service\ReactionService;
use CMS\Services\Community\Service\RenderService;
use CMS\Services\Community\Service\SubscriptionService;
use CMS\Services\Community\Support\Settings;

cdbSection('integration-throttle', 'integration-throttle: Standard-Drosseln von R, D und N gegen ContentGuard::instance() und login_attempts', static function (): void {
    // Sitzungszone von PHP wie Database::connect(); Security vergleicht attempted_at mit date().
    $db = MariaDbTestDatabase::create(date('P'));
    $db->getPdo()->exec(SchemaManager::getSchemaQueries('cms_', 'utf8mb4')['login_attempts']);
    $previousDb = (new \ReflectionProperty(Database::class, 'instance'))->getValue();
    $previousGuard = (new \ReflectionProperty(ContentGuard::class, 'instance'))->getValue();
    (new \ReflectionProperty(Database::class, 'instance'))->setValue(null, $db);
    (new \ReflectionProperty(ContentGuard::class, 'instance'))->setValue(null, null);
    try {
        $settings = Settings::fromArray([]);
        $now = time();
        $clock = static fn (): int => $now;
        $allow = static fn (?int $u, string $a, array $c): AccessDecision => AccessDecision::allow();
        $quiet = static function (string $hook, mixed ...$args): void {
        };
        $forum = $db->forum();
        $author = $db->user();
        $topic = $db->topic(['forum' => $forum, 'user' => $author]);
        $post = $db->post(['topic' => $topic, 'user' => $author, 'first' => true]);
        $db->execute('UPDATE cms_community_topics SET first_post_id = ? WHERE id = ?', [$post, $topic]);
        $rows = static fn (string $action, int $user): int => (int) cdbScalar($db, 'SELECT COUNT(*) FROM cms_login_attempts WHERE action = ? AND username = ?', ['community_' . $action, 'community:u:' . $user]);
        $member = static fn (): int => $db->user();

        $subs = new SubscriptionService($db, $settings, $clock, $allow, $quiet, null, static fn (): string => 'integration-secret-0123456789', static fn (): string => 'de');
        $reactions = new ReactionService($db, $settings, $clock, $allow, $quiet, null);
        $kinds = array_values($reactions->available());
        $cases = [
            // Aktion, Grenze, Aufruf je Mitglied: true = erlaubt (Erfolg bzw. ein anderer Fehler als rate_limited)
            'preview' => [10, static function (int $user) use ($db, $settings, $clock, $allow, $topic): bool {
                $r = (new RenderService($db, $settings, $clock, $allow))->preview($user, 'Vorschau der Integration', ['topic_id' => $topic]);

                return ($r['error'] ?? null) !== 'rate_limited';
            }],
            'upload' => [30, static function (int $user) use ($db, $settings, $clock, $allow, $forum): bool {
                $service = new AttachmentService($db, $settings, $clock, $allow, isUploaded: static fn (string $p): bool => false);
                $r = $service->upload($user, ['name' => 'bild.png', 'tmp_name' => '/gibt/es/nicht.png', 'error' => UPLOAD_ERR_OK, 'size' => 100], ['forum_id' => $forum], $service->newToken());

                return ($r['error'] ?? null) !== 'rate_limited';
            }],
            'search' => [30, static function (int $user) use ($db, $settings, $clock, $allow): bool {
                $r = (new CommunitySearch($db, $settings, $clock, $allow, static fn (?int $u): array => [], null, static fn (): ?string => null, locale: 'de'))->search($user, 'Integration Suche');

                return ($r['error'] ?? null) !== 'rate_limited';
            }],
            'subscribe' => [30, static function (int $user) use ($subs, $topic): bool {
                return ($subs->subscribe($user, 'topic', $topic)['error'] ?? null) !== 'rate_limited';
            }],
            'react' => [30, static function (int $user) use ($reactions, $post, $kinds): bool {
                static $i = 0;

                return ($reactions->set($user, $post, $kinds[++$i % 2])['error'] ?? null) !== 'rate_limited';
            }],
            'react_notify' => [10, static function (int $user) use ($db, $settings, $clock, $allow, $subs, $post, $kinds): bool {
                $before = $db->count('community_notify_queue', ['actor_id' => $user]);
                (new NotificationDispatcher($db, $settings, $clock, $allow, subscriptions: $subs))->onReactionChanged($post, $user, $kinds[0], '');

                return $db->count('community_notify_queue', ['actor_id' => $user]) > $before;
            }],
        ];
        foreach ($cases as $action => [$max, $call]) {
            $user = $member();
            $allowed = [];
            for ($i = 0; $i <= $max; $i++) {
                $allowed[] = $call($user);
            }
            T::same(array_merge(array_fill(0, $max, true), [false]), $allowed, "{$action}: genau {$max} erlaubt, die nächste abgelehnt (ContentGuard::THROTTLE)");
            T::same($max, $rows($action, $user), "{$action}: {$max} Zeilen in login_attempts (community_{$action}, community:u:{$user}), keine für die abgelehnte");
        }
        T::same(['0.0.0.0'], array_values(array_unique(array_map('strval', $db->getPdo()->query("SELECT DISTINCT ip_address FROM cms_login_attempts WHERE action LIKE 'community\\_%'")->fetchAll(\PDO::FETCH_COLUMN)))), 'keine Klar-IP in login_attempts');
    } finally {
        (new \ReflectionProperty(ContentGuard::class, 'instance'))->setValue(null, $previousGuard);
        (new \ReflectionProperty(Database::class, 'instance'))->setValue(null, $previousDb);
        $db->dispose();
    }
});
