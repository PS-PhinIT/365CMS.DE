<?php
/**
 * Lesebereich einer Seitenanfrage (PR #5, Abfragebudget): innerhalb von `ReadScope::run()` merken sich `ForumRepository::find()`/`findBySlug()`
 * und `TopicRepository::find()` ihre Zeilen, sodass dieselbe Zeile in einer Anfrage nur einmal gelesen wird (die Dienste einer Seite lesen
 * Forum und Thema sonst je mehrfach: Themenseite 13 statt 6 Abfragen).
 *
 * - Nur die Lesecontroller (`Http\PublicController`: Startseite, Forum, Thema, Listen, Suche) öffnen einen Bereich; Schreibwege, Dienste und
 *   Tests außerhalb eines Bereichs lesen wie bisher jedes Mal aus der Datenbank.
 * - Jede Schreibanweisung eines Repositories (`AbstractRepository::run()`, `insertRow()`) verwirft die gemerkten Zeilen der betroffenen Tabelle
 *   (`forget()`): wer im Bereich Beiträge schreibt, liest sie danach frisch; ein Eintrag im Lesestand lässt die Beitragszeilen stehen. Am Ende des äußersten Bereichs ist alles vergessen (auch bei einer Ausnahme).
 * - Schlüssel enthalten die Kennung der Datenbankverbindung: zwei Verbindungen (Tests) teilen nichts.
 *
 * @package CMSv2\Core\Services\Community
 */

declare(strict_types=1);

namespace CMS\Services\Community\Support;

if (!defined('ABSPATH')) {
    exit;
}

final class ReadScope
{
    private static int $depth = 0;

    /** @var array<string, mixed> */
    private static array $memo = [];

    /**
     * @template T
     * @param \Closure(): T $work
     * @return T
     */
    public static function run(\Closure $work): mixed
    {
        self::$depth++;
        try {
            return $work();
        } finally {
            if (--self::$depth === 0) {
                self::$memo = [];
            }
        }
    }

    public static function active(): bool
    {
        return self::$depth > 0;
    }

    /** Schlüssel für eine Zeile einer Verbindung, z. B. `key($db, 'topic', 7)`. */
    public static function key(object $db, string $what, int|string $id): string
    {
        return spl_object_id($db) . ':' . $what . ':' . $id;
    }

    /**
     * Wert aus dem Merkzettel oder, falls es keinen gibt, aus `$load` (auch `null` wird gemerkt). Außerhalb eines Bereichs immer `$load`.
     *
     * @template T
     * @param \Closure(): T $load
     * @return T
     */
    public static function remember(string $key, \Closure $load): mixed
    {
        if (self::$depth === 0) {
            return $load();
        }
        if (!array_key_exists($key, self::$memo)) {
            self::$memo[$key] = $load();
        }

        return self::$memo[$key];
    }

    /** Legt einen Wert unter einem Schlüssel ab (nur im Bereich), z. B. die Forumszeile nach `findBySlug()` für `find()`. */
    public static function put(string $key, mixed $value): void
    {
        if (self::$depth > 0) {
            self::$memo[$key] = $value;
        }
    }

    /**
     * Welche gemerkten Arten eine Schreibanweisung auf eine Tabelle verwirft (Endung des Tabellennamens → Arten). Tabellen, aus denen
     * nichts gemerkt wird (Lesestand, Abos, Reaktionen, Protokoll, …), verwerfen nichts.
     */
    private const AFFECTS = [
        'community_posts' => ['post'],
        'community_topics' => ['topic'],
        'community_forums' => ['forum', 'forum-slug', 'forum-tree'],
        'community_categories' => ['forum-tree'],
        'community_user_stats' => ['stats'],
    ];

    /**
     * Verwirft gemerkte Zeilen nach einer Schreibanweisung: mit dem SQL nur die Arten der betroffenen Tabelle (ein Eintrag im Lesestand lässt
     * die Beitragszeilen der Seite stehen), ohne SQL oder bei einer Anweisung, die sich nicht lesen lässt, alles.
     */
    public static function forget(string $sql = ''): void
    {
        if (self::$depth === 0 || self::$memo === []) {
            return;
        }
        if ($sql === '' || preg_match('/^\s*(?:INSERT(?:\s+IGNORE)?\s+INTO|REPLACE\s+INTO|UPDATE|DELETE\s+FROM)\s+`?([A-Za-z0-9_]+)`?/i', $sql, $match) !== 1) {
            self::$memo = [];

            return;
        }
        $table = strtolower($match[1]);
        $kinds = [];
        foreach (self::AFFECTS as $suffix => $affected) {
            if (str_ends_with($table, $suffix)) {
                $kinds = $affected;
            }
        }
        if ($kinds === []) {
            return;
        }
        foreach (array_keys(self::$memo) as $key) {
            $parts = explode(':', (string) $key, 3);
            if (in_array($parts[1] ?? '', $kinds, true)) {
                unset(self::$memo[$key]);
            }
        }
    }
}
