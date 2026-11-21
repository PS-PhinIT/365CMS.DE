<?php
/**
 * Worker-Aktionen der Einheit D (PR #2b, W2) für `TESTS/community-db/sections-media.php` (geladen von `worker.php`; in W4 kommen die
 * Zuordnungsaktionen für R1 bis R4 dazu).
 *
 *  media.upload  `AttachmentService::upload()` eines Textanhangs auf der Verbindung des Workers. Argumente: `user`, `topic`, `token`,
 *                `per_day` (Tageskontingent), `dir` (Ablage), `delay_ms` (Pause nach der Tageszählung in der Transaktion: macht den
 *                Wettlauf ohne Sperre sichtbar). Rechte, Drossel und Upload-Prüfung sind Fakes (der Abschnitt prüft das Kontingent).
 *
 * @package CMSv2\Core\Services\Community
 */

declare(strict_types=1);

use CMS\Database;
use CMS\Services\Community\Repository\AttachmentRepository;
use CMS\Services\Community\Repository\UserStatsRepository;
use CMS\Services\Community\Service\AccessDecision;
use CMS\Services\Community\Service\AttachmentService;
use CMS\Services\Community\Support\Transaction;

/**
 * Verbindung mit Pause nach einer Anweisung in einer Transaktion (Wettlauf sichtbar machen); sonst wie die Testdatenbank.
 */
final class CdbMediaSlowDatabase extends Database
{
    public function __construct(private readonly MariaDbTestDatabase $inner, private readonly string $pattern, private readonly int $delayMs)
    {
        (new \ReflectionProperty(Database::class, 'pdo'))->setValue($this, $inner->getPdo());
        $this->prefix = $inner->getPrefix();
    }

    public function execute(string $sql, array $params = []): \PDOStatement
    {
        $statement = $this->inner->execute($sql, $params);
        if ($this->delayMs > 0 && $this->getPdo()->inTransaction() && preg_match($this->pattern, $sql) === 1) {
            usleep($this->delayMs * 1000);
        }

        return $statement;
    }

    public function lastInsertId(): int
    {
        return $this->inner->lastInsertId();
    }
}

// Klassen vor dem Startsignal laden: sonst streut das Laden die Prozesse, und der Wettlauf wird zufällig seriell.
foreach ([AttachmentService::class, AttachmentRepository::class, UserStatsRepository::class, AccessDecision::class, Transaction::class] as $class) {
    class_exists($class);
}

return [
    'media.upload' => static function (array $args) use ($db, $settings): array {
        $dir = rtrim((string) $args['dir'], '/') . '/';
        $source = $dir . 'in-' . getmypid() . '.upload';
        file_put_contents($source, "Ein kurzer Text.\n");
        $slow = new CdbMediaSlowDatabase($db, '/^SELECT COUNT\(\*\) FROM cms_community_attachments WHERE user_id = \? AND created_at >= \?$/', (int) ($args['delay_ms'] ?? 0));
        $service = new AttachmentService(
            $slow,
            $settings,
            null,
            static fn (?int $userId, string $action, array $context): AccessDecision => AccessDecision::allow(),
            static fn (int $userId): array => ['attachments_per_post' => -1, 'attachments_per_day' => (int) $args['per_day']],
            static fn (string $action, ?int $userId): array => ['allowed' => true, 'retry_after' => 0],
            static function (string $relDir) use ($dir): string {
                $path = $dir . $relDir . '/';
                if (!is_dir($path)) {
                    @mkdir($path, 0700, true);
                }

                return $path;
            },
            static fn (string $relPath): ?string => is_file($dir . $relPath) ? $dir . $relPath : null,
            static fn (string $tmp): bool => is_file($tmp),
            static fn (string $tmp, string $dest): bool => rename($tmp, $dest)
        );

        return $service->upload((int) $args['user'], ['name' => 'notiz.txt', 'tmp_name' => $source, 'error' => UPLOAD_ERR_OK, 'size' => 17], ['topic_id' => (int) $args['topic']], (string) $args['token']);
    },
];
