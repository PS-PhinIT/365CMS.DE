<?php
/**
 * Worker-Aktionen der Einheit S (PR #2b, W2) für `TESTS/community-db/sections-spam.php` (geladen von `worker.php`).
 *
 *  spam.throttle  `ContentGuard::throttle(action, user)` mit der Standard-Closure der Request-Grenzen (`Security`, `login_attempts`) auf
 *                 der Verbindung des Workers. Liefert `{success: true, allowed: bool, retry_after: int}`.
 *
 * @package CMSv2\Core\Services\Community
 */

declare(strict_types=1);

use CMS\Database;
use CMS\Security;
use CMS\Services\Community\Repository\MemberRepository;
use CMS\Services\Community\Repository\PostRepository;
use CMS\Services\Community\Service\ContentGuard;
use CMS\Services\Community\Support\IpHash;

// Klassen vor dem Startsignal laden: sonst streut das Laden nach dem Start die Anfragen, und der Wettlauf wird zufällig seriell.
foreach ([ContentGuard::class, Security::class, PostRepository::class, MemberRepository::class, IpHash::class] as $class) {
    class_exists($class);
}

return [
    'spam.throttle' => static function (array $args) use ($db, $settings): array {
        // Die Request-Grenzen des Cores laufen über Database::instance(): im Worker die Testdatenbank, in der Sitzungszone von PHP wie
        // Database::connect() (die Zählung vergleicht attempted_at mit date()).
        (new \ReflectionProperty(Database::class, 'instance'))->setValue(null, $db);
        $db->getPdo()->exec("SET time_zone = '" . date('P') . "'");
        $user = isset($args['user']) ? (int) $args['user'] : null;

        return ['success' => true] + (new ContentGuard($db, $settings))->throttle((string) $args['action'], $user);
    },
];
