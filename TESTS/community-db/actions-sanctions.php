<?php
/**
 * Worker-Aktionen der Einheit K (PR #2b, Sanktionen) für `TESTS/community-db/worker.php`; Abschnitte in `sections-sanctions.php`.
 * Geladen im Gültigkeitsbereich des Workers (`$db`, `$settings`, `$access`, `$can`, `$dispatch`, `$job`).
 *
 *   report.create   user, type, target, reason, message   `ReportService::create()` (Halt über `hold`: in can(fresh) unter den Sperren)
 *   report.resolve  moderator, report, status, up_to      `ReportService::resolve()` (Halt über `hold`: nach der Sperre der Meldungen)
 *   ban.create      actor, user, scope, duration, reason  `BanService::ban()` (Halt: in can(fresh) unter der Statistiksperre)
 *   ban.lift        actor, ban                            `BanService::lift()` (Halt: in can(fresh) unter der Statistiksperre)
 *
 * Die Schwelle ruft hier keine echte Moderation (`autoModerate` liefert `changed: false`): `race-report-threshold` mit dem echten
 * `ModerationService` folgt in W4. Hooks zählt der Worker in `hooks` des Ergebnisses.
 *
 * @package CMSv2\Core\Services\Community
 */

declare(strict_types=1);

use CMS\Database;
use CMS\Services\Community\Service\BanService;
use CMS\Services\Community\Service\ReportService;

/** @var MariaDbTestDatabase $db */
/** @var \CMS\Services\Community\Support\Settings $settings */
/** @var \CMS\Services\Community\Service\CommunityAccess $access */
/** @var \Closure $can */
/** @var array<string, mixed> $job */

$sanctionHooks = new \ArrayObject();
$sanctionDispatch = static function (string $hook, mixed ...$args) use ($sanctionHooks): void {
    $sanctionHooks[] = $hook;
};
$noModeration = static fn (string $op, string $type, int $id, array $details): array => ['success' => true, 'changed' => false];

/**
 * Verbindung, die nach der ersten Anweisung, die `$pattern` trifft, anhält (Datei `locked` schreiben, auf `release` warten). So hält
 * `resolve()` die Sperre der Meldungen, während der Test von außen prüft; `resolve()` ruft unter der Sperre kein `can(fresh)`.
 */
$sanctionHoldingDb = static function (MariaDbTestDatabase $inner, array $hold, string $pattern): Database {
    return new class ($inner, $hold, $pattern) extends Database {
        private bool $held = false;

        /** @param array<string, mixed> $hold */
        public function __construct(private MariaDbTestDatabase $inner, private array $hold, private string $pattern)
        {
            $this->prefix = $inner->getPrefix();
        }

        public function execute(string $sql, array $params = []): \PDOStatement
        {
            $stmt = $this->inner->execute($sql, $params);
            if (!$this->held && preg_match($this->pattern, $sql) === 1) {
                $this->held = true;
                file_put_contents((string) $this->hold['locked'], (string) getmypid());
                $deadline = microtime(true) + 20;
                while (!is_file((string) $this->hold['release']) && microtime(true) < $deadline) {
                    usleep(1000);
                }
            }

            return $stmt;
        }

        public function getPdo(): \PDO
        {
            return $this->inner->getPdo();
        }

        public function getConnection(): \PDO
        {
            return $this->inner->getPdo();
        }

        public function getPrefix(): string
        {
            return $this->inner->getPrefix();
        }

        public function lastInsertId(): int
        {
            return $this->inner->lastInsertId();
        }
    };
};

$withHooks = static function (array $result) use ($sanctionHooks): array {
    return $result + ['hooks' => $sanctionHooks->getArrayCopy()];
};

return [
    'report.create' => static function (array $args) use ($db, $settings, $access, $can, $sanctionDispatch, $noModeration, $withHooks): array {
        $service = new ReportService($db, $settings, null, $can, $sanctionDispatch, $access, $noModeration);

        return $withHooks($service->create((int) $args['user'], (string) $args['type'], (int) $args['target'], (string) ($args['reason'] ?? 'spam'), (string) ($args['message'] ?? '')));
    },
    'report.resolve' => static function (array $args) use ($db, $settings, $access, $can, $sanctionDispatch, $noModeration, $withHooks, $sanctionHoldingDb, $job): array {
        $connection = isset($job['resolveHold']) ? $sanctionHoldingDb($db, (array) $job['resolveHold'], '/^SELECT id, reporter_id, forum_id FROM cms_community_reports WHERE id IN \(/') : $db;
        $service = new ReportService($connection, $settings, null, $can, $sanctionDispatch, $access, $noModeration);

        return $withHooks($service->resolve((int) $args['moderator'], (int) $args['report'], (string) $args['status'], '', isset($args['up_to']) ? (int) $args['up_to'] : null));
    },
    'ban.create' => static function (array $args) use ($db, $settings, $access, $can, $sanctionDispatch, $withHooks): array {
        $service = new BanService($db, $settings, null, $can, $sanctionDispatch, $access);
        $duration = $args['duration'] ?? null;

        return $withHooks($service->ban((int) $args['actor'], (int) $args['user'], (string) $args['scope'], $duration === null ? null : (int) $duration, (string) ($args['reason'] ?? 'Wettlauf')));
    },
    'ban.lift' => static function (array $args) use ($db, $settings, $access, $can, $sanctionDispatch, $withHooks): array {
        $service = new BanService($db, $settings, null, $can, $sanctionDispatch, $access);

        return $withHooks($service->lift((int) $args['actor'], (int) $args['ban']));
    },
];
