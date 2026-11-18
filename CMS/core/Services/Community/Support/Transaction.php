<?php
/**
 * Transaktion über getPdo() (wie InvoiceService::issue()): äußerste Ebene = BEGIN/COMMIT, verschachtelt = SAVEPOINT.
 * Wiederholt bei Sperrkonflikt (Deadlock 1213, Lock-Timeout 1205, SQLSTATE 40001) nur auf der äußersten Ebene.
 *
 * @package CMSv2\Core\Services\Community
 */

declare(strict_types=1);

namespace CMS\Services\Community\Support;

use CMS\Database;

if (!defined('ABSPATH')) {
    exit;
}

final class Transaction
{
    public const MAX_ATTEMPTS = 3;

    /**
     * @template T
     * @param \Closure(): T $work
     * @return T
     */
    public static function run(Database $db, \Closure $work): mixed
    {
        $pdo = $db->getPdo();
        for ($attempt = 1; ; $attempt++) {
            $nested = $pdo->inTransaction();
            $nested ? $pdo->exec('SAVEPOINT cms_community_tx') : $pdo->beginTransaction();
            try {
                $result = $work();
                $nested ? $pdo->exec('RELEASE SAVEPOINT cms_community_tx') : $pdo->commit();

                return $result;
            } catch (\Throwable $e) {
                try {
                    if ($nested) {
                        $pdo->exec('ROLLBACK TO SAVEPOINT cms_community_tx');
                        $pdo->exec('RELEASE SAVEPOINT cms_community_tx');
                    } elseif ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                } catch (\Throwable) {
                }
                if (!$nested && $attempt < self::MAX_ATTEMPTS && self::isRetryable($e)) {
                    usleep(50000 * $attempt);
                    continue;
                }

                throw $e;
            }
        }
    }

    public static function isRetryable(\Throwable $e): bool
    {
        if (!$e instanceof \PDOException) {
            return false;
        }

        return $e->getCode() === '40001' || in_array((int) ($e->errorInfo[1] ?? 0), [1205, 1213], true);
    }
}
