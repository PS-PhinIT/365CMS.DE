<?php
/**
 * Paralleler Prozess der Suite `TESTS/community-db` (Wettlauf- und Sperrtests): eigene PHP-Instanz, eigene Datenbankverbindung,
 * echte `CommunityAccess`, echte Repositories, echte Dienste. Aufruf nur durch `run.php`:
 *
 *   php TESTS/community-db/worker.php <job.json>
 *
 * Ablauf: Auftrag lesen, Verbindung öffnen, Dienste bauen, `ready`-Datei schreiben, auf die Startdatei warten (Startsignal für
 * alle Worker gleichzeitig), Aktion ausführen, das Ergebnis als eine JSON-Zeile auf STDOUT ausgeben.
 *
 * Auftrag (JSON): `db` (Datenbankname), `ready`, `start` (Dateien), `action`, `args`, `settings` (Rohwerte für `Settings::fromArray`),
 * optional `hold` = {`locked`, `release`} (der Worker meldet sich in der Rechteprüfung unter den Sperren und wartet auf `release`;
 * so hält ein Dienstaufruf seine Zeilensperren, während der Test sie von einer zweiten Verbindung aus untersucht) und `lockTimeout`
 * (`innodb_lock_wait_timeout` der Verbindung in Sekunden) und `isolation` (z. B. `READ COMMITTED`, Diagnose).
 *
 * Aktionen: `topic.create` (user, forum, title, body), `post.reply` (user, topic, body), `post.edit` (user, post, body, title),
 * `post.delete` (user, post), `topic.delete` (user, topic), `read.advance` (user, topic, post), `read.mark-forum` (user, forum),
 * `forum.create` (data), `none`.
 *
 * @package CMSv2\Core\Services\Community
 */

declare(strict_types=1);

$jobFile = (string) ($argv[1] ?? '');
$job = json_decode((string) @file_get_contents($jobFile), true);
if (!is_array($job)) {
    fwrite(STDERR, 'Worker: Auftrag nicht lesbar: ' . $jobFile . PHP_EOL);
    exit(2);
}

define('ABSPATH', dirname(__DIR__, 2) . '/CMS/');
define('CMS_DEBUG', false);
define('LOG_LEVEL', 'warning');
define('LOG_PATH', dirname($jobFile) . '/log-' . getmypid() . '/');

date_default_timezone_set('Europe/Berlin');
ini_set('error_log', '/dev/null');

require_once dirname(__DIR__) . '/lib/Mutant.php';
// Ein Worker lädt nicht jede Datei der Mutante; „nie angewendet“ prüft der Haupt-Prozess der Suite.
Mutant::$requireApplied = false;

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'CMS\\')) {
        return;
    }
    $file = ABSPATH . 'core/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($file)) {
        Mutant::load($file);
    }
});

require_once dirname(__DIR__) . '/lib/MariaDbTestDatabase.php';

use CMS\Services\Community\Repository\ForumRepository;
use CMS\Services\Community\Service\AccessDecision;
use CMS\Services\Community\Service\CommunityAccess;
use CMS\Services\Community\Service\PostService;
use CMS\Services\Community\Service\ReadStateService;
use CMS\Services\Community\Service\TopicService;
use CMS\Services\Community\Support\Settings;

$db = new MariaDbTestDatabase(MariaDbTestDatabase::connect((string) $job['db']), (string) $job['db']);
if (isset($job['isolation'])) {
    $db->getPdo()->exec('SET SESSION TRANSACTION ISOLATION LEVEL ' . preg_replace('/[^A-Z ]/', '', strtoupper((string) $job['isolation'])));
}
if (isset($job['lockTimeout'])) {
    $db->getPdo()->exec('SET SESSION innodb_lock_wait_timeout = ' . (int) $job['lockTimeout']);
}
$settings = Settings::fromArray((array) ($job['settings'] ?? []));
$capabilities = ['member' => ['community.view', 'community.participate'], 'moderator' => ['community.view', 'community.participate', 'community.moderate']];
$access = new CommunityAccess(
    $db,
    $settings,
    static fn (string $role, string $capability): bool => $role === 'admin' || in_array($capability, $capabilities[$role] ?? [], true),
    static fn (): bool => true,
    null,
    static fn (int $userId): bool => true
);
$hold = isset($job['hold']) ? (array) $job['hold'] : null;
$held = false;
$can = static function (?int $userId, string $action, array $context) use ($access, $hold, &$held): AccessDecision {
    // Die zweite Entscheidung (fresh) läuft erst, wenn alle Sperren der Aktion gehalten werden: hier anhalten.
    if ($hold !== null && !$held && !empty($context['fresh'])) {
        $held = true;
        file_put_contents((string) $hold['locked'], (string) getmypid());
        $deadline = microtime(true) + 20;
        while (!is_file((string) $hold['release']) && microtime(true) < $deadline) {
            usleep(1000);
        }
    }

    return $access->can($userId, $action, $context);
};
$dispatch = static function (string $hook, mixed ...$args): void {
};
$topics = new TopicService($db, $settings, null, $can, $dispatch);
$posts = new PostService($db, $settings, null, $can, $dispatch);
$reads = new ReadStateService($db, $settings, null, $can);
$forums = new ForumRepository($db);

file_put_contents((string) $job['ready'], (string) getmypid());
$deadline = microtime(true) + 30;
while (!is_file((string) $job['start']) && microtime(true) < $deadline) {
    usleep(200);
}

$args = (array) ($job['args'] ?? []);
$started = microtime(true);
$output = ['pid' => getmypid(), 'action' => (string) $job['action']];
try {
    $output['result'] = match ((string) $job['action']) {
        'topic.create' => $topics->create((int) $args['user'], (int) $args['forum'], (string) $args['title'], (string) $args['body']),
        'post.reply' => $posts->reply((int) $args['user'], (int) $args['topic'], (string) $args['body']),
        'post.edit' => $posts->edit((int) $args['user'], (int) $args['post'], (string) $args['body'], isset($args['title']) ? (string) $args['title'] : null),
        'post.delete' => $posts->delete((int) $args['user'], (int) $args['post']),
        'topic.delete' => $topics->delete((int) $args['user'], (int) $args['topic']),
        'read.advance' => ['success' => true, 'advanced' => $reads->advance((int) $args['user'], (int) $args['topic'], (int) $args['post'])],
        'read.mark-forum' => ['success' => true, 'marked' => $reads->markForumRead((int) $args['user'], (int) $args['forum'])],
        'forum.create' => $forums->create((array) $args['data'], 3, gmdate('Y-m-d H:i:s')),
        'none' => ['success' => true],
        default => throw new \InvalidArgumentException('unbekannte Aktion ' . $job['action']),
    };
} catch (\Throwable $e) {
    $output['exception'] = $e::class . ': ' . $e->getMessage();
    if ($e instanceof \PDOException) {
        $output['sql_error'] = $e->errorInfo[1] ?? null;
    }
}
$output['ms'] = (int) round((microtime(true) - $started) * 1000);
$output['in_transaction'] = $db->getPdo()->inTransaction();
$log = '';
foreach (glob(LOG_PATH . '*') ?: [] as $file) {
    $log .= (string) file_get_contents($file);
    @unlink($file);
}
@rmdir(LOG_PATH);
if ($log !== '') {
    $output['log'] = substr($log, -1500);
}
echo json_encode($output, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), PHP_EOL;
