<?php
/**
 * Transaktion über getPdo() (wie InvoiceService::issue()): äußerste Ebene = BEGIN/COMMIT, verschachtelt = SAVEPOINT.
 * Wiederholt bei Sperrkonflikt (Deadlock 1213, Lock-Timeout 1205, SQLSTATE 40001) nur auf der äußersten Ebene.
 *
 * Regeln für den Inhalt einer Transaktion der Community-Dienste (Befunde B2 und B3 der Integrationssuite):
 *
 * - Mitgliedersperre vor der ersten nicht sperrenden Leseanweisung, sonst sieht `can(fresh)` einen veralteten Schnappschuss:
 *   Unter REPEATABLE READ (Standard von InnoDB) entsteht der Lese-Schnappschuss der Transaktion bei der ersten Abfrage ohne
 *   Sperre. Alles, was eine gleichzeitige Transaktion bis dahin committet hat, ist sichtbar, alles danach nicht mehr. Liest der
 *   Dienst also vor der Mitgliedersperre (etwa die Dublettenprüfung), wartet er danach zwar auf den Mitbewerber desselben
 *   Mitglieds in einem anderen Forum, sieht dessen Beitrag aber nicht und umgeht den Abstand `rate_post_seconds` und die
 *   Zähl- und Tagesgrenzen. Reihenfolge deshalb: erst alle Sperren (Forum → Thema → Beitrag → Mitglied, über die
 *   `lockForUpdate()`-Methoden der Repositories, die selbst nichts ohne Sperre lesen), dann Dublettenprüfung, Rechteprüfung
 *   mit `fresh = true` und Schreiben. Kein Rückfall auf READ COMMITTED als Ausweg: die Reihenfolge gilt für jede Stufe.
 * - Zeilen, die in der Transaktion gesperrt werden, müssen vorher existieren: die Dienste legen die Statistikzeile des
 *   Mitglieds VOR `run()` im Autocommit an (`UserStatsRepository::ensure()`). Eine Sperre auf eine fehlende Zeile ist unter
 *   REPEATABLE READ eine Lückensperre; zwei Transaktionen mit Lückensperren auf derselben Lücke geraten beim anschließenden
 *   Einfügen in einen Deadlock. Dasselbe gilt für die Mutex-Zeile der Forum-Slugs (`ForumRepository`).
 * - Lesen, das den Zustand für die Entscheidung liefert, gehört nach den Sperren; Lesen, das nur die Sperrziele bestimmt (etwa
 *   die Forum-ID eines Themas), läuft vorher im Autocommit außerhalb der Transaktion.
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
