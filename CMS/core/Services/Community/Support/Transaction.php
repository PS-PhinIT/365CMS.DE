<?php
/**
 * Transaktion über getPdo() (wie InvoiceService::issue()): äußerste Ebene = BEGIN/COMMIT, verschachtelt = SAVEPOINT.
 * Wiederholt bei Deadlock (Fehler 1213, SQLSTATE 40001) nur auf der äußersten Ebene. Ein Lock-Timeout (1205) wird nicht
 * wiederholt: er heißt, dass jemand die Zeile `innodb_lock_wait_timeout` lang (Server-Standard 50 s) gehalten hat, eine
 * Wiederholung ändert daran nichts und ließe einen PHP-Worker samt Verbindung dreimal so lange warten (Befund B34).
 *
 * Savepoints heißen je Verschachtelungstiefe verschieden (`cms_community_tx_1`, `cms_community_tx_2`, …): ein gleichnamiger
 * Savepoint ersetzt den äußeren, das RELEASE der inneren Ebene löschte ihn, und das der mittleren scheiterte mit Fehler 1305
 * (Befunde B33 und B40, ab drei Ebenen). Die Tiefe zählt je Verbindung (`\PDO`), unabhängig von anderen Verbindungen im Prozess.
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

    /** Vorsilbe der Savepoint-Namen; angehängt wird die Tiefe (1 = erste Ebene innerhalb der äußersten Transaktion). */
    private const SAVEPOINT = 'cms_community_tx_';

    /** @var \WeakMap<\PDO, int>|null Tiefe der laufenden `run()`-Aufrufe je Verbindung (0 = äußerste Ebene) */
    private static ?\WeakMap $depths = null;

    /**
     * @template T
     * @param \Closure(): T $work
     * @return T
     */
    public static function run(Database $db, \Closure $work): mixed
    {
        $pdo = $db->getPdo();
        self::$depths ??= new \WeakMap();
        $outer = self::$depths[$pdo] ?? 0;
        for ($attempt = 1; ; $attempt++) {
            $nested = $pdo->inTransaction();
            $depth = $nested ? $outer + 1 : 0;
            $savepoint = self::SAVEPOINT . $depth;
            $nested ? $pdo->exec('SAVEPOINT ' . $savepoint) : $pdo->beginTransaction();
            self::$depths[$pdo] = $depth;
            try {
                $result = $work();
                $nested ? $pdo->exec('RELEASE SAVEPOINT ' . $savepoint) : $pdo->commit();

                return $result;
            } catch (\Throwable $e) {
                try {
                    if ($nested) {
                        $pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
                        $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
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
            } finally {
                self::$depths[$pdo] = $outer;
            }
        }
    }

    /** Deadlock (1213, SQLSTATE 40001) ja, Lock-Timeout (1205) nein: siehe Kopfkommentar. */
    public static function isRetryable(\Throwable $e): bool
    {
        if (!$e instanceof \PDOException) {
            return false;
        }

        return $e->getCode() === '40001' || (int) ($e->errorInfo[1] ?? 0) === 1213;
    }
}
