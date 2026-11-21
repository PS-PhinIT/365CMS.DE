<?php
/**
 * Community-Repositories (PR #2a): Kategorien, Foren (Baum, Rechte-Zeilen, Moderatoren, Zähler), Themen (Keyset-Liste, Pins,
 * Doppelt-Absenden), Beiträge (Verlauf, Platzhalter, Position), Lesestatus (nur steigend, Foren-Marken), Mitglieder, Sperren
 * und Benutzerstatistik, dazu das SQLite-Double und Quelltext-Wächter über `CMS/core/Services/Community/Repository/`.
 *
 * DB-frei: SQLite-Double (`TESTS/lib/CommunityTestDatabase.php`, Tabellen aus den echten CREATE-Texten), läuft in der CI.
 * Mit CMS_TEST_DB_HOST und CMS_TEST_DB_USER laufen dieselben Abläufe zusätzlich gegen eine echte MariaDB (frische Datenbank
 * `pr2a_*` je Ablauf, Sitzungszone +01:00), dazu die Fälle, die nur dort gelten (UTC-Werte bei Ortszeit der Sitzung, UNIQUE 1062).
 *
 * Teildateien `part-*.php` (je Repository-Gruppe ein Besitzer, PR #2b) lädt die Suite am Ende vor `T::finish()`.
 *
 *   php TESTS/community-repositories/run.php [-v]
 *   CMS_TEST_DB_HOST=localhost CMS_TEST_DB_USER=root php TESTS/community-repositories/run.php [-v]
 */

declare(strict_types=1);

define('ABSPATH', dirname(__DIR__, 2) . '/CMS/');
define('CMS_DEBUG', false);
define('LOG_LEVEL', 'emergency');
define('LOG_PATH', sys_get_temp_dir() . '/cms-tests-logs/');

// PHP-Zone der Suite: Europe/Berlin, damit jede Verwendung von date() statt gmdate() auffällt (Entwurfsvertrag §6).
date_default_timezone_set('Europe/Berlin');
ini_set('error_log', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null');

// Gegenprobe-Haken (TESTS/lib/Mutant.php): mit CMS_TEST_MUTANT=<name> lädt der Autoloader eine Mutante statt des Originals.
require_once dirname(__DIR__) . '/lib/Mutant.php';

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'CMS\\')) {
        return;
    }
    $file = ABSPATH . 'core/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($file)) {
        Mutant::load($file);
    }
});

require_once dirname(__DIR__) . '/lib/TestRunner.php';
require_once dirname(__DIR__) . '/lib/MariaDbTestDatabase.php';

use CMS\Database;
use CMS\Services\Community\Repository\BanRepository;
use CMS\Services\Community\Repository\CategoryRepository;
use CMS\Services\Community\Repository\ForumRepository;
use CMS\Services\Community\Repository\MemberRepository;
use CMS\Services\Community\Repository\PostRepository;
use CMS\Services\Community\Repository\ReadStateRepository;
use CMS\Services\Community\Repository\TopicRepository;
use CMS\Services\Community\Repository\UserStatsRepository;
use CMS\Services\Community\Support\Cursor;
use CMS\Services\Community\Support\Page;
use CMS\Services\Community\Support\Transaction;

T::$verbose = in_array('-v', $argv ?? [], true);

/** UTC-Zeit der Suite: 2026-11-15 10:00:00 plus Sekunden. */
function rt(int $seconds = 0): string
{
    return gmdate('Y-m-d H:i:s', gmmktime(10, 0, 0, 11, 15, 2026) + $seconds);
}

/**
 * @param list<array<string, mixed>> $rows
 * @return list<int>
 */
function rIds(array $rows): array
{
    return array_map(static fn (array $row): int => (int) $row['id'], $rows);
}

/**
 * Liest alle Seiten einer Keyset-Liste und liefert [Zeilen, Seitenzahl].
 *
 * @param \Closure(?Cursor): Page $fetch
 * @return array{0: list<array<string, mixed>>, 1: int}
 */
function rCollect(\Closure $fetch, int $maxPages = 40): array
{
    $rows = [];
    $cursor = null;
    $pages = 0;
    do {
        $page = $fetch($cursor);
        $pages++;
        foreach ($page->items as $row) {
            $rows[] = $row;
        }
        $cursor = $page->next === null ? null : Cursor::decode($page->next);
    } while ($cursor !== null && $pages < $maxPages);

    return [$rows, $pages];
}

function rSource(string $relative): string
{
    return (string) file_get_contents(ABSPATH . $relative);
}

/**
 * Verstöße gegen die Vorgaben für Repository-Quelltext (Entwurfsvertrag §1): Zeit und Dialekt der Datenbank, Datenbank-
 * Schreibhelfer, Uhren, Singleton-Zugriff.
 *
 * @return list<string>
 */
function rSourceViolations(string $source): array
{
    $rules = [
        'NOW()' => '/\bNOW\s*\(/i',
        'CURRENT_TIMESTAMP' => '/\bCURRENT_TIMESTAMP\b/i',
        'CURDATE' => '/\bCURDATE\b/i',
        'UTC_TIMESTAMP' => '/\bUTC_TIMESTAMP\b/i',
        'OFFSET' => '/\bOFFSET\b/i',
        'ON DUPLICATE' => '/\bON\s+DUPLICATE\b/i',
        'GREATEST' => '/\bGREATEST\b/i',
        'LEAST' => '/\bLEAST\s*\(/i',
        'FIELD()' => '/\bFIELD\s*\(/i',
        'JSON_*()' => '/\bJSON_[A-Z_]+\s*\(/',
        'UNION' => '/\bUNION\b/i',
        'date()' => '/(?<![A-Za-z_>])(?:gm)?date\s*\(/',
        'Uhr' => '/(?<![A-Za-z_>])(?:time|strtotime|microtime|hrtime|mktime|gmmktime)\s*\(|\bnew\s+\\\\?DateTime/',
        'Database::insert/update/delete' => '/\$(?:this->)?db->(?:insert|update|delete)\s*\(/',
        'affected_rows' => '/\baffected_rows\b/',
    ];
    $found = [];
    foreach ($rules as $label => $pattern) {
        if (preg_match($pattern, $source) === 1) {
            $found[] = $label;
        }
    }

    return $found;
}

// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
// Abläufe: laufen gegen jedes Backend (SQLite-Double, optional MariaDB)
// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

function rScenarioCategories(Database $db): void
{
    $repo = new CategoryRepository($db);
    $a = $repo->create(['title' => 'Allgemein', 'slug' => 'allgemein', 'sort_order' => 2], rt());
    $b = $repo->create(['title' => 'Technik', 'title_en' => 'Tech', 'slug' => 'technik', 'slug_en' => 'tech', 'sort_order' => 1], rt(5));
    $hidden = $repo->create(['title' => 'Intern', 'slug' => 'intern', 'is_visible' => 0], rt());
    T::check($a['success'] && $b['success'] && $hidden['success'] && ($a['id'] ?? 0) > 0, 'create liefert success und id');
    $aId = (int) $a['id'];
    $bId = (int) $b['id'];

    $row = $repo->find($aId);
    T::same('Allgemein', $row['title'] ?? null, 'find liefert die Zeile');
    T::same(rt(), $row['created_at'] ?? null, 'created_at ist der übergebene UTC-Wert');
    T::same(rt(), $row['updated_at'] ?? null, 'updated_at ist der übergebene UTC-Wert');
    T::same(null, $repo->find(99999), 'find: unbekannte ID → null');

    T::same($aId, (int) ($repo->findBySlug('allgemein')['id'] ?? 0), 'findBySlug (de) über slug');
    T::same($bId, (int) ($repo->findBySlug('tech', 'en')['id'] ?? 0), 'findBySlug (en) über slug_en');
    T::same($aId, (int) ($repo->findBySlug('allgemein', 'en')['id'] ?? 0), 'findBySlug (en): ohne slug_en gilt slug');
    T::same(null, $repo->findBySlug('tech', 'de'), 'findBySlug (de) sucht nicht in slug_en');
    T::same(null, $repo->findBySlug('gibt-es-nicht'), 'findBySlug: unbekannt → null');

    T::same([$bId, $aId], rIds($repo->listVisible()), 'listVisible: sichtbare Kategorien nach sort_order, ID; unsichtbare fehlen');

    T::same(['success' => false, 'error' => 'slug_taken'], $repo->create(['title' => 'Doppelt', 'slug' => 'allgemein'], rt()), 'Slug doppelt → slug_taken');
    T::same(['success' => false, 'error' => 'invalid_input'], $repo->create(['title' => 'X', 'slug' => 'Großes Foo'], rt()), 'ungültiger Slug → invalid_input');
    T::same(['success' => false, 'error' => 'invalid_input'], $repo->create(['title' => 'X', 'slug' => ''], rt()), 'leerer Slug → invalid_input');

    $updated = $repo->update($aId, ['title' => 'Allgemeines', 'slug_en' => 'general'], rt(60));
    T::same(['success' => true], $updated, 'update gelingt');
    $row = $repo->find($aId);
    T::check(($row['title'] ?? '') === 'Allgemeines' && ($row['slug_en'] ?? '') === 'general', 'update schreibt die Spalten');
    T::same([rt(), rt(60)], [$row['created_at'] ?? null, $row['updated_at'] ?? null], 'update setzt updated_at, created_at bleibt');
    T::same(['success' => false, 'error' => 'slug_taken'], $repo->update($aId, ['slug' => 'technik'], rt(61)), 'update: Slug einer anderen Kategorie → slug_taken');
    T::same(['success' => true], $repo->update($aId, ['slug' => 'allgemein'], rt(62)), 'update: eigener Slug ist kein Konflikt');
    T::same(['success' => false, 'error' => 'invalid_input'], $repo->update(99999, ['title' => 'x'], rt()), 'update: unbekannte ID → invalid_input');
    T::same(['success' => true], $repo->update($aId, ['slug_en' => ''], rt(63)), 'update: leerer slug_en erlaubt');
    $row = $repo->find($aId);
    T::check(is_array($row) && array_key_exists('slug_en', $row) && $row['slug_en'] === null, 'leerer slug_en wird NULL');

    try {
        $repo->create(['title' => 'x', 'slug' => 'x', 'bogus' => 1], rt());
        T::check(false, 'unbekannte Spalte wirft');
    } catch (\InvalidArgumentException) {
        T::check(true, 'unbekannte Spalte wirft InvalidArgumentException');
    }
}

function rScenarioForumTree(Database $db): void
{
    $repo = new ForumRepository($db);
    $root = $repo->create(['title' => 'Allgemein', 'slug' => 'allgemein', 'slug_en' => 'general', 'category_id' => 4], 3, rt());
    T::check($root['success'] && ($root['depth'] ?? -1) === 0, 'Forum der obersten Ebene: depth 0');
    $rootId = (int) $root['id'];
    T::same('/' . $rootId . '/', $root['path'] ?? null, 'path enthält die eigene ID');
    $row = $repo->find($rootId);
    T::check(array_key_exists('parent_id', $row) && $row['parent_id'] === null && (int) ($row['depth'] ?? -1) === 0 && ($row['path'] ?? '') === '/' . $rootId . '/', 'Zeile: parent_id NULL, depth 0, path gesetzt');
    T::same([rt(), rt(), 1], [$row['created_at'] ?? null, $row['updated_at'] ?? null, (int) ($row['version'] ?? 0)], 'created_at/updated_at aus dem Parameter, version 1');

    $child = $repo->create(['title' => 'Hilfe', 'slug' => 'hilfe', 'parent_id' => $rootId], 3, rt(10));
    $childId = (int) $child['id'];
    T::same([1, '/' . $rootId . '/' . $childId . '/'], [$child['depth'] ?? null, $child['path'] ?? null], 'Unterforum: depth 1, path = Elternpfad + ID');
    T::same(4, (int) ($repo->find($childId)['category_id'] ?? -1), 'Unterforum erbt die Kategorie des Elternforums');
    $grand = $repo->create(['title' => 'Fragen', 'slug' => 'fragen', 'parent_id' => $childId], 3, rt(20));
    $grandId = (int) $grand['id'];
    T::same([2, '/' . $rootId . '/' . $childId . '/' . $grandId . '/'], [$grand['depth'] ?? null, $grand['path'] ?? null], 'dritte Ebene: depth 2');

    $second = $repo->create(['title' => 'Zweite', 'slug' => 'zweite', 'parent_id' => $rootId], 3, rt());
    $deeper = $repo->create(['title' => 'Vierte', 'slug' => 'vierte', 'parent_id' => $grandId], 4, rt());
    T::same(['success' => false, 'error' => 'forum_not_found'], $repo->create(['title' => 'Waise', 'slug' => 'waise', 'parent_id' => 99999], 3, rt()), 'unbekanntes Elternforum → forum_not_found');
    T::same(['success' => false, 'error' => 'invalid_input'], $repo->create(['title' => '', 'slug' => 'leer'], 3, rt()), 'leerer Titel → invalid_input');
    T::same(['success' => false, 'error' => 'invalid_input'], $repo->create(['title' => 'X', 'slug' => 'a_b'], 3, rt()), 'Slug mit Unterstrich → invalid_input');
    T::same(['success' => false, 'error' => 'invalid_input'], $repo->create(['title' => 'X', 'slug' => 'ok', 'status' => 'kaputt'], 3, rt()), 'unbekannter Status → invalid_input');
    T::same(['success' => false, 'error' => 'invalid_input'], $repo->create(['title' => 'X', 'slug' => 'ok', 'type' => 'kaputt'], 3, rt()), 'unbekannter Typ → invalid_input');

    // Slug-Eindeutigkeit über beide Spalten
    T::same(['success' => false, 'error' => 'slug_taken'], $repo->create(['title' => 'A', 'slug' => 'allgemein'], 3, rt()), 'slug = vorhandener slug → slug_taken');
    T::same(['success' => false, 'error' => 'slug_taken'], $repo->create(['title' => 'A', 'slug' => 'general'], 3, rt()), 'slug = vorhandener slug_en → slug_taken');
    T::same(['success' => false, 'error' => 'slug_taken'], $repo->create(['title' => 'A', 'slug' => 'frei-1', 'slug_en' => 'allgemein'], 3, rt()), 'slug_en = vorhandener slug → slug_taken');
    T::same(['success' => false, 'error' => 'slug_taken'], $repo->create(['title' => 'A', 'slug' => 'frei-2', 'slug_en' => 'general'], 3, rt()), 'slug_en = vorhandener slug_en → slug_taken');
    T::check($repo->create(['title' => 'Gleich', 'slug' => 'gleich', 'slug_en' => 'gleich'], 3, rt())['success'], 'slug = eigener slug_en ist kein Konflikt');
    $en1 = $repo->create(['title' => 'E1', 'slug' => 'e-eins', 'slug_en' => ''], 3, rt());
    $en2 = $repo->create(['title' => 'E2', 'slug' => 'e-zwei', 'slug_en' => ''], 3, rt());
    T::check($en1['success'] && $en2['success'] && $repo->find((int) $en1['id'])['slug_en'] === null, 'leerer slug_en wird NULL (mehrere erlaubt)');
    T::same(1, $db->count('community_forums', ['slug' => 'allgemein']), 'abgelehnte Anlage schreibt nichts');

    // Auflösen
    T::same($rootId, (int) ($repo->findBySlug('allgemein', 'de')['id'] ?? 0), 'findBySlug de');
    T::same($rootId, (int) ($repo->findBySlug('general', 'en')['id'] ?? 0), 'findBySlug en über slug_en');
    T::same($childId, (int) ($repo->findBySlug('hilfe', 'en')['id'] ?? 0), 'findBySlug en: slug_en NULL → slug');
    T::same(null, $repo->findBySlug('general', 'de'), 'findBySlug de sucht nicht in slug_en');
    T::same($rootId, (int) ($repo->findBySlug('general')['id'] ?? 0), 'findBySlug ohne Sprache: beide Spalten (slug_en)');
    T::same($rootId, (int) ($repo->findBySlug('allgemein')['id'] ?? 0), 'findBySlug ohne Sprache: beide Spalten (slug)');
    T::same(null, $repo->findBySlug('nirgends'), 'findBySlug: unbekannt → null');

    // Baum und Zwischenspeicher
    $tree = $repo->tree();
    T::check(isset($tree[$rootId], $tree[$childId], $tree[$grandId]) && (int) $tree[$childId]['id'] === $childId, 'tree: Schlüssel = ID');
    $inCategory = array_keys(array_filter($tree, static fn (array $row): bool => (int) $row['category_id'] === 4));
    T::same([$rootId, $childId, (int) $second['id'], $grandId, (int) $deeper['id']], $inCategory, 'tree: je Kategorie nach Tiefe, Sortierung, ID');
    $before = count($db->log);
    $repo->tree();
    T::same($before, count($db->log), 'tree: zweiter Aufruf liest nicht erneut (Instanz-Zwischenspeicher)');
    $new = $repo->create(['title' => 'Neu', 'slug' => 'neu-im-baum'], 3, rt());
    T::check(isset($repo->tree()[(int) $new['id']]), 'create verwirft den Zwischenspeicher');
    $repo->update((int) $new['id'], ['title' => 'Neu umbenannt'], rt(1));
    T::same('Neu umbenannt', $repo->tree()[(int) $new['id']]['title'] ?? null, 'update verwirft den Zwischenspeicher');
    $db->execute('UPDATE ' . $db->getPrefix() . 'community_forums SET title = ? WHERE id = ?', ['Direkt', (int) $new['id']]);
    $repo->forgetTree();
    T::same('Direkt', $repo->tree()[(int) $new['id']]['title'] ?? null, 'forgetTree erzwingt Neulesen');

    // Pfad-Abfragen
    T::same([$grandId, $childId, $rootId], $repo->ancestorIds($grandId), 'ancestorIds: Forum zuerst, dann Vorfahren');
    T::same([$rootId], $repo->ancestorIds($rootId), 'ancestorIds der Wurzel');
    T::same([], $repo->ancestorIds(99999), 'ancestorIds: unbekannt → []');
    $descendants = $repo->descendantIds($rootId);
    T::same([$childId, $grandId, (int) $second['id'], (int) $deeper['id']], $descendants, 'descendantIds: alle Unterforen ohne das Forum, aufsteigend');
    T::same([$grandId, (int) $deeper['id']], $repo->descendantIds($childId), 'descendantIds eines mittleren Forums');
    T::same([], $repo->descendantIds($grandId + 1000), 'descendantIds: unbekannt → []');
    // Präfix-Falle: Forum 1 darf nicht die Unterforen von Forum 10 liefern
    $fixtures = $db->forum(['title' => 'Sonder']);
    T::check(!in_array($fixtures, $repo->descendantIds($rootId), true), 'descendantIds trifft nur den echten Pfadpräfix');

    // chainIds mit inherit_permissions
    $a = $db->forum(['title' => 'A']);
    $b = $db->forum(['title' => 'B', 'parent' => $a]);
    $c = $db->forum(['title' => 'C', 'parent' => $b, 'inherit' => false]);
    $d = $db->forum(['title' => 'D', 'parent' => $c]);
    $fresh = new ForumRepository($db);
    T::same([$d, $c], $fresh->chainIds($d), 'chainIds endet am Forum mit inherit_permissions = 0');
    T::same([$c], $fresh->chainIds($c), 'chainIds: Forum ohne Vererbung steht allein');
    T::same([$b, $a], $fresh->chainIds($b), 'chainIds: Vererbung bis zur Wurzel');
    T::same([$a], $fresh->chainIds($a), 'chainIds der Wurzel');
    T::same([], $fresh->chainIds(99999), 'chainIds: unbekannt → []');
    T::same([$d, $c, $b, $a], $fresh->ancestorIds($d), 'ancestorIds ignoriert inherit_permissions');

    // update
    $u = $repo->update($rootId, ['title' => 'Allgemeines', 'status' => 'readonly', 'require_approval' => true, 'slug_en' => 'common'], rt(100));
    T::same(['success' => true], $u, 'update gelingt');
    $row = $repo->find($rootId);
    T::check($row['title'] === 'Allgemeines' && $row['status'] === 'readonly' && (int) $row['require_approval'] === 1 && $row['slug_en'] === 'common', 'update schreibt Spalten (Schalter als 0/1)');
    T::same([2, rt(100)], [(int) $row['version'], $row['updated_at']], 'update erhöht version und setzt updated_at');
    T::same(['success' => true], $repo->update($rootId, [], rt(101)), 'update ohne Änderungen ist ein No-op');
    T::same(2, (int) $repo->find($rootId)['version'], 'No-op erhöht version nicht');
    T::same(['success' => false, 'error' => 'slug_taken'], $repo->update($childId, ['slug' => 'allgemein'], rt()), 'update: slug eines anderen → slug_taken');
    T::same(['success' => false, 'error' => 'slug_taken'], $repo->update($childId, ['slug_en' => 'common'], rt()), 'update: slug_en = slug_en eines anderen → slug_taken');
    T::same(['success' => false, 'error' => 'slug_taken'], $repo->update($childId, ['slug_en' => 'allgemein'], rt()), 'update: slug_en = slug eines anderen → slug_taken');
    T::same(['success' => true], $repo->update($rootId, ['slug' => 'allgemein', 'slug_en' => 'general'], rt(102)), 'update: eigene Slugs sind kein Konflikt');
    T::same(['success' => false, 'error' => 'invalid_input'], $repo->update($rootId, ['status' => 'kaputt'], rt()), 'update: unbekannter Status → invalid_input');
    T::same(['success' => false, 'error' => 'invalid_input'], $repo->update($rootId, ['title' => ' '], rt()), 'update: leerer Titel → invalid_input');
    T::same(['success' => false, 'error' => 'forum_not_found'], $repo->update(99999, ['title' => 'x'], rt()), 'update: unbekannte ID → forum_not_found');
    foreach (['parent_id', 'path', 'depth', 'topic_count', 'version'] as $column) {
        try {
            $repo->update($rootId, [$column => 1], rt());
            T::check(false, "update lehnt Spalte {$column} ab");
        } catch (\InvalidArgumentException) {
            T::check(true, "update lehnt Spalte {$column} ab (Umhängen und Zähler gehören nicht dazu)");
        }
    }

    // Zeilensperre
    $db->log = [];
    T::same($rootId, (int) ($repo->lockForUpdate($rootId)['id'] ?? 0), 'lockForUpdate liefert die Zeile');
    T::same(null, $repo->lockForUpdate(99999), 'lockForUpdate: unbekannt → null');
    T::check(count(array_filter($db->log, static fn (string $sql): bool => str_ends_with($sql, ' LIMIT 1 FOR UPDATE'))) === 2, 'lockForUpdate hängt FOR UPDATE an');
}

/**
 * Tiefengrenze: der Wert (Zahl der Ebenen) wird als `$maxDepth` an `ForumRepository::create()`
 * übergeben und entscheidet, wie tief Foren angelegt werden dürfen.
 */
function rScenarioForumMaxDepth(Database $db): void
{
    $repo = new ForumRepository($db);
    $root = (int) $repo->create(['title' => 'Wurzel', 'slug' => 'wurzel'], 3, rt())['id'];
    $levelTwo = $repo->create(['title' => 'Ebene 2', 'slug' => 'ebene-2', 'parent_id' => $root], 3, rt());
    $levelThree = $repo->create(['title' => 'Ebene 3', 'slug' => 'ebene-3', 'parent_id' => (int) $levelTwo['id']], 3, rt());
    T::same([1, 2], [$levelTwo['depth'] ?? null, $levelThree['depth'] ?? null], 'Tiefengrenze 3 erlaubt drei Ebenen (depth 0, 1, 2)');
    $three = (int) $levelThree['id'];
    $two = (int) $levelTwo['id'];

    T::same(['success' => false, 'error' => 'forum_depth'], $repo->create(['title' => 'Ebene 4', 'slug' => 'ebene-4', 'parent_id' => $three], 3, rt()), 'Tiefengrenze 3 lehnt die vierte Ebene ab');
    $four = $repo->create(['title' => 'Ebene 4', 'slug' => 'ebene-4', 'parent_id' => $three], 4, rt());
    T::check($four['success'] && ($four['depth'] ?? 0) === 3, 'Tiefengrenze 4 erlaubt die vierte Ebene (depth 3)');
    T::same(['success' => false, 'error' => 'forum_depth'], $repo->create(['title' => 'Ebene 3b', 'slug' => 'ebene-3b', 'parent_id' => $two], 2, rt()), 'Tiefengrenze 2 lehnt die dritte Ebene ab');
    $twoLevels = $repo->create(['title' => 'Ebene 2b', 'slug' => 'ebene-2b', 'parent_id' => $root], 2, rt());
    T::check($twoLevels['success'] && ($twoLevels['depth'] ?? 0) === 1, 'Tiefengrenze 2 erlaubt die zweite Ebene');
    T::same(['success' => false, 'error' => 'forum_depth'], $repo->create(['title' => 'Ebene 2c', 'slug' => 'ebene-2c', 'parent_id' => $root], 1, rt()), 'Tiefengrenze 1 erlaubt nur die oberste Ebene');
    T::same(['success' => false, 'error' => 'forum_depth'], $repo->create(['title' => 'Ebene 2c', 'slug' => 'ebene-2c', 'parent_id' => $root], 0, rt()), 'Tiefengrenze unter 1 wirkt wie 1');
    T::check($repo->create(['title' => 'Oben', 'slug' => 'oben'], 1, rt())['success'], 'Tiefengrenze 1 lässt die oberste Ebene zu');
    T::same(0, $db->count('community_forums', ['slug' => 'ebene-2c']), 'abgelehnte Tiefe schreibt nichts');
}

/** PDOException wie bei einer UNIQUE-Verletzung (SQLSTATE 23000, MariaDB-Fehler 1062), z. B. aus einem Wettlauf zweier Anlegevorgänge. */
final class RepoDuplicateKeyException extends \PDOException
{
    public function __construct()
    {
        parent::__construct("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'x' for key 'uq'");
        $this->code = '23000';
        $this->errorInfo = ['23000', 1062, 'Duplicate entry'];
    }
}

/** Wettlauf: der UNIQUE-Index schlägt zu, nachdem die Vorprüfung den Slug als frei gesehen hat. */
function rScenarioUniqueRace(Database $db): void
{
    $forums = new ForumRepository($db);
    $categories = new CategoryRepository($db);
    $existing = $forums->create(['title' => 'Vorhanden', 'slug' => 'vorhanden'], 3, rt());
    $forumCount = $db->count('community_forums');
    $db->throwOn = ['/^INSERT INTO \S*community_forums\b/' => new RepoDuplicateKeyException()];
    T::same(['success' => false, 'error' => 'slug_taken'], $forums->create(['title' => 'Rennen', 'slug' => 'rennen'], 3, rt()), 'create: UNIQUE-Verletzung beim Einfügen → slug_taken');
    T::same([$forumCount, false], [$db->count('community_forums'), $db->getPdo()->inTransaction()], 'create: nichts geschrieben, keine Transaktion offen');
    $db->throwOn = ['/^UPDATE \S*community_forums SET\b/' => new RepoDuplicateKeyException()];
    T::same(['success' => false, 'error' => 'slug_taken'], $forums->update((int) $existing['id'], ['title' => 'Neu', 'slug' => 'anders'], rt(5)), 'update: UNIQUE-Verletzung beim Schreiben → slug_taken');
    T::same('Vorhanden', $forums->find((int) $existing['id'])['title'] ?? null, 'update: Zeile unverändert');
    $db->throwOn = ['/^INSERT INTO \S*community_categories\b/' => new RepoDuplicateKeyException()];
    T::same(['success' => false, 'error' => 'slug_taken'], $categories->create(['title' => 'Rennen', 'slug' => 'rennen'], rt()), 'Kategorie create: UNIQUE-Verletzung → slug_taken');
    $db->throwOn = [];
    $other = $categories->create(['title' => 'Andere', 'slug' => 'andere'], rt());
    $db->throwOn = ['/^UPDATE \S*community_categories SET\b/' => new RepoDuplicateKeyException()];
    T::same(['success' => false, 'error' => 'slug_taken'], $categories->update((int) $other['id'], ['slug' => 'noch-anders'], rt(5)), 'Kategorie update: UNIQUE-Verletzung → slug_taken');
    $db->throwOn = ['/^INSERT INTO \S*community_forums\b/' => new \PDOException('anderer Fehler')];
    try {
        $forums->create(['title' => 'Fehler', 'slug' => 'fehler'], 3, rt());
        T::check(false, 'andere Datenbankfehler werden nicht verschluckt');
    } catch (\PDOException $e) {
        T::same('anderer Fehler', $e->getMessage(), 'andere Datenbankfehler werden unverändert weitergegeben');
    }
    $db->throwOn = [];
    T::same(['success' => false, 'error' => 'invalid_input'], $forums->create(['title' => 'Negativ', 'slug' => 'negativ', 'parent_id' => -3], 3, rt()), 'negatives Elternforum → invalid_input');
}

function rScenarioForumCounters(Database $db): void
{
    $repo = new ForumRepository($db);
    $other = new ForumRepository($db);
    $forum = $db->forum(['title' => 'Zähler']);
    $at = rt(30);

    T::check($repo->bumpCounters($forum, 1, 1, $at, 11, 5, 3, $at), 'bumpCounters liefert true');
    $row = $repo->find($forum);
    T::same([1, 1, 11, $at, 5, 3, 2, $at], [(int) $row['topic_count'], (int) $row['post_count'], (int) $row['last_post_id'], $row['last_post_at'], (int) $row['last_topic_id'], (int) $row['last_poster_id'], (int) $row['version'], $row['updated_at']], 'bumpCounters: Zähler, letzter Beitrag, version, updated_at');

    T::check($other->applyCounters($forum, 0, 2, rt(31)), 'applyCounters (zweite Instanz)');
    $row = $repo->find($forum);
    T::same([1, 3, 11, 3], [(int) $row['topic_count'], (int) $row['post_count'], (int) $row['last_post_id'], (int) $row['version']], 'applyCounters ohne last: last_* bleibt, version + 1');

    // atomar: ein Schreiber hinter dem Rücken des Repositories (kein Lesen-Rechnen-Schreiben)
    $db->execute('UPDATE ' . $db->getPrefix() . 'community_forums SET topic_count = 10, post_count = 20 WHERE id = ?', [$forum]);
    $repo->applyCounters($forum, 1, 1, rt(32));
    $row = $repo->find($forum);
    T::same([11, 21], [(int) $row['topic_count'], (int) $row['post_count']], 'applyCounters rechnet auf dem aktuellen Datenbankwert (atomar)');

    // Verminderung, nie unter 0
    $repo->applyCounters($forum, -3, -30, rt(33));
    $row = $repo->find($forum);
    T::same([8, 0], [(int) $row['topic_count'], (int) $row['post_count']], 'negative Deltas; nie unter 0');
    $repo->applyCounters($forum, -100, 0, rt(34));
    T::same(0, (int) $repo->find($forum)['topic_count'], 'Untergrenze 0 bei UNSIGNED-Spalten');

    // last_* neu setzen und auf NULL
    $repo->applyCounters($forum, 0, 0, rt(35), ['post_id' => 99, 'post_at' => rt(20), 'topic_id' => 7, 'poster_id' => 4]);
    $row = $repo->find($forum);
    T::same([99, rt(20), 7, 4], [(int) $row['last_post_id'], $row['last_post_at'], (int) $row['last_topic_id'], (int) $row['last_poster_id']], 'applyCounters mit last setzt alle vier Felder');
    $repo->applyCounters($forum, 0, 0, rt(36), ['post_id' => null, 'post_at' => null, 'topic_id' => null, 'poster_id' => null]);
    $row = $repo->find($forum);
    T::same([null, null, null, null], [$row['last_post_id'], $row['last_post_at'], $row['last_topic_id'], $row['last_poster_id']], 'applyCounters mit last = null-Werten leert „letzter Beitrag“');
    T::same(false, $repo->applyCounters(99999, 1, 1, rt()), 'applyCounters: unbekanntes Forum → false');
}

function rScenarioPermissions(Database $db): void
{
    $repo = new ForumRepository($db);
    $a = $db->forum(['title' => 'A']);
    $b = $db->forum(['title' => 'B', 'parent' => $a]);
    $c = $db->forum(['title' => 'C', 'parent' => $b, 'inherit' => false]);
    $d = $db->forum(['title' => 'D', 'parent' => $c]);
    $e = $db->forum(['title' => 'E']);
    $f = $db->forum(['title' => 'F']);

    $repo->savePermissions($a, [
        ['subject_type' => 'guest', 'permissions' => ['read' => 0]],
        ['subject_type' => 'role', 'subject_key' => 'member', 'permissions' => ['read' => 1, 'reply' => 1]],
        ['subject_type' => 'role', 'subject_key' => 'editor', 'permissions' => ['read' => 1]],
        ['subject_type' => 'group', 'subject_key' => '7', 'permissions' => ['read' => 1]],
        ['subject_type' => 'group', 'subject_key' => '8', 'permissions' => ['read' => 1]],
        ['subject_type' => 'trust', 'subject_key' => '1', 'permissions' => ['create_topic' => 1]],
        ['subject_type' => 'trust', 'subject_key' => '3', 'permissions' => ['edit_own' => 1]],
    ], rt());
    $repo->savePermissions($b, [['subject_type' => 'role', 'subject_key' => 'member', 'permissions' => ['read' => 0]]], rt());
    $repo->savePermissions($c, [['subject_type' => 'guest', 'permissions' => ['read' => 1]]], rt());
    $repo->savePermissions($f, [['subject_type' => 'group', 'subject_key' => '5', 'permissions' => ['read' => 1]]], rt());

    /** @param list<array<string, mixed>> $rows */
    $by = static function (array $rows): array {
        $map = [];
        foreach ($rows as $row) {
            $map[$row['subject_type'] . ':' . $row['subject_key']] = (int) $row['forum_id'];
        }
        ksort($map);

        return $map;
    };

    $chainB = $repo->chainIds($b);
    T::same([$b, $a], $chainB, 'Kette von B = [B, A]');
    $rows = $repo->permissionRows($chainB, 'member', [7], 1);
    T::same(['group:7' => $a, 'guest:' => $a, 'role:member' => $b, 'trust:1' => $a], $by($rows), 'je Subjekt die Zeile des ersten Forums; eigene Zeile (role:member in B) ersetzt die geerbte');
    $memberRow = array_values(array_filter($rows, static fn (array $r): bool => $r['subject_type'] === 'role'))[0] ?? [];
    T::same('{"read":0}', $memberRow['permissions_json'] ?? null, 'permissions_json der ersetzenden Zeile (B)');
    T::same(['guest:' => $a, 'role:member' => $b], $by($repo->permissionRows($chainB, 'member', [], 0)), 'Stufe 0: keine trust:1-Zeile; ohne Gruppen keine group-Zeilen');
    T::same(['group:7' => $a, 'group:8' => $a, 'guest:' => $a, 'role:member' => $b, 'trust:1' => $a], $by($repo->permissionRows($chainB, 'member', [7, 8, 9], 2)), 'mehrere Gruppen; Stufe 2 schließt trust:1 ein');
    T::same(['guest:' => $a, 'role:member' => $b, 'trust:1' => $a, 'trust:3' => $a], $by($repo->permissionRows($chainB, 'member', [], 4)), 'Stufe 4 schließt trust:1 und trust:3 ein (Mindeststufe)');
    T::same(['guest:' => $a, 'role:editor' => $a], $by($repo->permissionRows($chainB, 'editor', [], 0)), 'andere Rolle: nur ihre Zeile');
    T::same(['guest:' => $a], $by($repo->permissionRows($chainB, 'author', [], 0)), 'Rolle ohne Zeile: nur guest');
    T::same(['guest:' => $a], $by($repo->permissionRows($chainB, '', [], -1)), 'Gast: nur die guest-Zeile (keine Rollen, keine Stufen)');
    T::same([], $repo->permissionRows([], 'member', [7], 1), 'leere Kette → []');
    T::same([['forum_id' => $a, 'subject_type' => 'guest', 'subject_key' => '', 'permissions_json' => '{"read":0}']], $repo->permissionRows([$a], '', [], -1), 'Zeilenform');

    $chainD = $repo->chainIds($d);
    T::same([$d, $c], $chainD, 'Kette von D endet bei C (inherit_permissions = 0)');
    T::same(['guest:' => $c], $by($repo->permissionRows($chainD, 'member', [7], 3)), 'Vererbung endet bei C: keine Zeilen aus A oder B');

    // Zeilen vorhanden? (D3: Kette ohne Zeilen → Standard-Rechte)
    T::check($repo->hasPermissionRows($chainD), 'hasPermissionRows: Kette mit Zeilen');
    T::check(!$repo->hasPermissionRows([$d]), 'hasPermissionRows: Forum ohne Zeilen');
    T::check(!$repo->hasPermissionRows($repo->chainIds($e)), 'hasPermissionRows: Forum ohne Zeilen in der ganzen Kette');
    T::check(!$repo->hasPermissionRows([]), 'hasPermissionRows: leere Kette');
    T::same([], $repo->permissionRows($repo->chainIds($f), 'member', [], 0), 'Forum nur mit Gruppenzeile: Mitglied ohne Gruppe hat keine passende Zeile …');
    T::check($repo->hasPermissionRows($repo->chainIds($f)), '… aber hasPermissionRows ist wahr (dann gelten die Standards nicht)');

    // decodePermissions
    T::same([], ForumRepository::decodePermissions(null), 'decodePermissions: NULL → []');
    T::same([], ForumRepository::decodePermissions('{}'), 'decodePermissions: {} → []');
    T::same([], ForumRepository::decodePermissions('kein json'), 'decodePermissions: ungültig → []');
    T::same(['read' => 1, 'reply' => 0], ForumRepository::decodePermissions('{"read":1,"reply":0,"bogus":1}'), 'decodePermissions: nur bekannte Schlüssel, Werte 0/1');
    T::same(['read' => 1], ForumRepository::decodePermissions('{"read":"yes"}'), 'decodePermissions: Werte werden zu 0/1');

    // savePermissions: ersetzen, NULL, Prüfungen ohne Teilschreiben
    $repo->savePermissions($f, [['subject_type' => 'guest', 'permissions' => null], ['subject_type' => 'trust', 'subject_key' => '0', 'permissions' => []]], rt(5));
    $rows = $db->rows('community_forum_permissions', ['forum_id' => $f], 'subject_type');
    T::same([['guest', null], ['trust', '{}']], array_map(static fn (array $r): array => [$r['subject_type'], $r['permissions_json']], $rows), 'savePermissions ersetzt alle Zeilen; null → NULL, leere Matrix → {}');
    T::same(rt(5), $rows[0]['updated_at'], 'savePermissions setzt updated_at');
    $count = $db->count('community_forum_permissions');
    $bad = [
        'unbekannter Typ' => [['subject_type' => 'planet', 'subject_key' => 'x']],
        'trust 5' => [['subject_type' => 'trust', 'subject_key' => '5']],
        'Gruppe nicht numerisch' => [['subject_type' => 'group', 'subject_key' => 'abc']],
        'guest mit Schlüssel' => [['subject_type' => 'guest', 'subject_key' => 'x']],
        'Rolle leer' => [['subject_type' => 'role', 'subject_key' => '']],
        'doppelt' => [['subject_type' => 'guest'], ['subject_type' => 'guest']],
        'unbekannter Rechte-Schlüssel' => [['subject_type' => 'guest', 'permissions' => ['fliegen' => 1]]],
    ];
    foreach ($bad as $label => $rowsIn) {
        try {
            $repo->savePermissions($f, [['subject_type' => 'guest', 'permissions' => ['read' => 1]], ...$rowsIn], rt());
            T::check(false, "savePermissions lehnt ab: {$label}");
        } catch (\InvalidArgumentException) {
            T::check(true, "savePermissions lehnt ab: {$label}");
        }
    }
    T::same($count, $db->count('community_forum_permissions'), 'abgelehnte Eingaben schreiben nichts (auch nicht die gültige erste Zeile)');
    T::same([['guest', null], ['trust', '{}']], array_map(static fn (array $r): array => [$r['subject_type'], $r['permissions_json']], $db->rows('community_forum_permissions', ['forum_id' => $f], 'subject_type')), 'bestehende Zeilen bleiben bei Fehlern erhalten');
}

function rScenarioModerators(Database $db): void
{
    $repo = new ForumRepository($db);
    $a = $db->forum(['title' => 'A']);
    $b = $db->forum(['title' => 'B', 'parent' => $a]);
    $c = $db->forum(['title' => 'C', 'parent' => $b]);
    $x = $db->forum(['title' => 'X']);

    $repo->saveModerators($a, [
        ['subject_type' => 'user', 'subject_id' => 7, 'include_children' => 1],
        ['subject_type' => 'user', 'subject_id' => 8, 'include_children' => 0],
        ['subject_type' => 'group', 'subject_id' => 3],
    ]);
    $repo->saveModerators($b, [['subject_type' => 'user', 'subject_id' => 9, 'include_children' => 0]]);

    $anc = $repo->ancestorIds($c);
    T::same([$c, $b, $a], $anc, 'Vorfahren von C');
    /** @param list<array<string, mixed>> $rows */
    $keys = static fn (array $rows): array => array_map(static fn (array $r): string => $r['forum_id'] . ':' . $r['subject_type'] . ':' . $r['subject_id'] . ':' . $r['include_children'], $rows);

    T::same(["{$a}:user:7:1"], $keys($repo->moderatorRows($anc, 7, [], $c)), 'Moderator des Elternforums mit include_children gilt im Unterforum');
    T::same([], $repo->moderatorRows($anc, 8, [], $c), 'include_children = 0: gilt nicht im Unterforum');
    T::same(["{$a}:user:8:0"], $keys($repo->moderatorRows([$a], 8, [], $a)), 'include_children = 0: gilt im Forum selbst');
    T::same([], $repo->moderatorRows($anc, 9, [], $c), 'include_children = 0 auf B gilt nicht in C');
    T::same(["{$b}:user:9:0"], $keys($repo->moderatorRows([$b, $a], 9, [], $b)), 'include_children = 0 auf B gilt in B');
    T::same(["{$a}:group:3:1"], $keys($repo->moderatorRows($anc, 5, [3], $c)), 'Gruppen-Moderator über eine Gruppe des Mitglieds');
    T::same([], $repo->moderatorRows($anc, 5, [4], $c), 'andere Gruppe: kein Moderator');
    T::same(["{$a}:group:3:1", "{$a}:user:7:1"], $keys($repo->moderatorRows($anc, 7, [3], $c)), 'Mitglied und Gruppe: beide Zeilen');
    T::same([], $repo->moderatorRows($anc, 0, [], $c), 'ohne Mitglied und Gruppen: leer');
    T::same([], $repo->moderatorRows($repo->ancestorIds($x), 7, [3], $x), 'Moderatoren eines anderen Zweigs gelten nicht');
    T::same([], $repo->moderatorRows([], 7, [3], $c), 'leere Vorfahrenliste → []');

    $repo->saveModerators($a, [['subject_type' => 'user', 'subject_id' => 11]]);
    T::same(["{$a}:user:11:1"], $keys($repo->moderatorRows([$a], 11, [], $a)), 'saveModerators ersetzt alle Zeilen; include_children Standard 1');
    T::same([], $repo->moderatorRows([$a], 7, [], $a), 'ersetzte Moderatoren sind weg');
    $count = $db->count('community_forum_moderators');
    foreach ([
        'unbekannter Typ' => [['subject_type' => 'role', 'subject_id' => 1]],
        'ID 0' => [['subject_type' => 'user', 'subject_id' => 0]],
        'doppelt' => [['subject_type' => 'user', 'subject_id' => 5], ['subject_type' => 'user', 'subject_id' => 5]],
    ] as $label => $rowsIn) {
        try {
            $repo->saveModerators($a, $rowsIn);
            T::check(false, "saveModerators lehnt ab: {$label}");
        } catch (\InvalidArgumentException) {
            T::check(true, "saveModerators lehnt ab: {$label}");
        }
    }
    T::same($count, $db->count('community_forum_moderators'), 'abgelehnte Eingaben schreiben nichts');
}

function rScenarioTopics(Database $db): void
{
    $repo = new TopicRepository($db);
    $forum = $db->forum(['title' => 'Themen']);
    $user = $db->user();

    $id = $repo->insert(['forum_id' => $forum, 'user_id' => $user, 'title' => 'Hallo Welt', 'slug' => 'hallo-welt', 'status' => 'published', 'last_post_at' => rt(), 'created_at' => rt(), 'updated_at' => rt()]);
    $row = $repo->find($id);
    T::check($id > 0 && ($row['title'] ?? '') === 'Hallo Welt', 'insert liefert die ID, find die Zeile');
    T::same([1, 0, 0, rt(), $forum, $user], [(int) $row['version'], (int) $row['reply_count'], (int) $row['is_pinned'], $row['last_post_at'], (int) $row['forum_id'], (int) $row['user_id']], 'Zeile: version 1, Zähler 0, Zeiten und IDs als Ganzzahlen');
    T::same(null, $repo->find(99999), 'find: unbekannt → null');
    foreach ([['bogus' => 1], ['id' => 5], ['version' => 3]] as $bad) {
        try {
            $repo->insert([...$bad, 'forum_id' => $forum, 'created_at' => rt(), 'updated_at' => rt()]);
            T::check(false, 'insert lehnt ' . key($bad) . ' ab');
        } catch (\InvalidArgumentException) {
            T::check(true, 'insert lehnt ' . key($bad) . ' ab');
        }
    }
    try {
        $repo->insert([]);
        T::check(false, 'insert ohne Spalten wirft');
    } catch (\InvalidArgumentException) {
        T::check(true, 'insert ohne Spalten wirft');
    }

    T::check($repo->update($id, ['title' => 'Neuer Titel', 'slug' => 'neuer-titel', 'is_locked' => 1, 'updated_at' => rt(60)]), 'update: Zeile gefunden');
    $row = $repo->find($id);
    T::same(['Neuer Titel', 1, 2, rt(60)], [$row['title'], (int) $row['is_locked'], (int) $row['version'], $row['updated_at']], 'update ändert Spalten und erhöht version');
    T::check($repo->update($id, ['title' => 'Neuer Titel']), 'update mit gleichem Wert: version ändert sich trotzdem, also „gefunden“');
    T::same(3, (int) $repo->find($id)['version'], 'version steigt bei jedem Treffer');
    T::same(false, $repo->update(99999, ['title' => 'x']), 'update: unbekannte ID → false');
    T::same(false, $repo->update($id, []), 'update ohne Spalten → false');
    foreach (['user_id', 'reply_count', 'created_at', 'version', 'id'] as $column) {
        try {
            $repo->update($id, [$column => 1]);
            T::check(false, "update lehnt Spalte {$column} ab");
        } catch (\InvalidArgumentException) {
            T::check(true, "update lehnt Spalte {$column} ab");
        }
    }

    // Zähler atomar
    $db->execute('UPDATE ' . $db->getPrefix() . 'community_topics SET reply_count = 10 WHERE id = ?', [$id]);
    T::check($repo->bumpCounters($id, 1, rt(90), 77, $user, rt(90)), 'bumpCounters: Thema gefunden');
    $row = $repo->find($id);
    T::same([11, 77, rt(90), $user, rt(90)], [(int) $row['reply_count'], (int) $row['last_post_id'], $row['last_post_at'], (int) $row['last_poster_id'], $row['updated_at']], 'bumpCounters rechnet auf dem Datenbankwert und setzt last_*');
    $repo->applyCounters($id, -20, rt(91));
    T::same(0, (int) $repo->find($id)['reply_count'], 'applyCounters: nie unter 0');
    $before = (int) $repo->find($id)['version'];
    $repo->applyCounters($id, 0, rt(92), ['post_id' => null, 'post_at' => null, 'poster_id' => null]);
    $row = $repo->find($id);
    T::same([null, null, null, $before + 1], [$row['last_post_id'], $row['last_post_at'], $row['last_poster_id'], (int) $row['version']], 'applyCounters mit last = null leert „letzter Beitrag“, version + 1');
    T::same(false, $repo->applyCounters(99999, 1, rt()), 'applyCounters: unbekannt → false');

    // Zeilensperre
    $db->log = [];
    T::same($id, (int) ($repo->lockForUpdate($id)['id'] ?? 0), 'lockForUpdate liefert die Zeile');
    T::same(null, $repo->lockForUpdate(99999), 'lockForUpdate: unbekannt → null');
    T::check(count(array_filter($db->log, static fn (string $sql): bool => str_ends_with($sql, ' LIMIT 1 FOR UPDATE'))) === 2, 'lockForUpdate hängt FOR UPDATE an');

    // Abfragen für Grenzen
    $rate = $db->user();
    $db->topic(['forum' => $forum, 'user' => $rate, 'created_at' => rt(-7200)]);
    $db->topic(['forum' => $forum, 'user' => $rate, 'created_at' => rt(-1800)]);
    $t5 = $db->topic(['forum' => $forum, 'user' => $rate, 'created_at' => rt(-300), 'status' => 'deleted']);
    $t4 = $db->topic(['forum' => $forum, 'user' => $rate, 'created_at' => rt(-600), 'status' => 'pending']);
    T::same(2, $repo->countSince($rate, rt(-3600)), 'countSince: veröffentlicht und wartend seit der Grenze, gelöscht nicht');
    T::same(3, $repo->countSince($rate, rt(-7200)), 'countSince: Grenze ist einschließlich (>=)');
    T::same(0, $repo->countSince($rate, rt(0)), 'countSince: nichts seit später Grenze');
    T::same(0, $repo->countSince($rate + 99, rt(-7200)), 'countSince: anderes Mitglied');
    T::same(['id' => $t4, 'forum_id' => $forum, 'created_at' => rt(-600)], $repo->lastOfUser($rate, rt(-3600)), 'lastOfUser: jüngstes zählendes Thema (das neuere gelöschte zählt nicht)');
    T::same(null, $repo->lastOfUser($rate, rt(0)), 'lastOfUser: nichts seit der Grenze → null');
    // mit Statusliste (die Rechteprüfung zählt auch Gelöschtes: Löschen gibt kein Kontingent und keinen Abstand frei)
    $quota = ['published', 'pending', 'hidden', 'deleted', 'trash'];
    T::same(3, $repo->countSince($rate, rt(-3600), $quota), 'countSince mit Statusliste: veröffentlicht (-1800), wartend (-600) und gelöscht (-300) seit der Grenze');
    T::same(1, $repo->countSince($rate, rt(-7200), ['deleted']), 'countSince nur gelöscht');
    T::same(0, $repo->countSince($rate, rt(-7200), ['trash']), 'countSince nur im Papierkorb');
    T::same(4, $repo->countSince($rate, rt(-7200), $quota), 'countSince mit Statusliste und früherer Grenze (vier Themen)');
    T::same(['id' => $t5, 'forum_id' => $forum, 'created_at' => rt(-300)], $repo->lastOfUser($rate, rt(-3600), $quota), 'lastOfUser mit Statusliste: das gelöschte Thema ist das jüngste');
    T::same($t5, $repo->lastOfUser($rate, rt(-3600), ['deleted'])['id'] ?? 0, 'lastOfUser nur gelöscht');
    T::same(null, $repo->lastOfUser($rate, rt(-3600), ['trash']), 'lastOfUser nur im Papierkorb → null');
    foreach ([['kaputt'], []] as $bad) {
        foreach (['countSince' => static fn () => $repo->countSince($rate, rt(-7200), $bad), 'lastOfUser' => static fn () => $repo->lastOfUser($rate, rt(-7200), $bad)] as $name => $call) {
            try {
                $call();
                T::check(false, $name . ' lehnt ungültige Status ab');
            } catch (\InvalidArgumentException) {
                T::check(true, $name . ' lehnt ungültige Status ab');
            }
        }
    }

    // letztes veröffentlichtes Thema
    $lp = $db->forum(['title' => 'Letzte']);
    T::same(null, $repo->lastPublished($lp), 'lastPublished: leeres Forum → null');
    $x1 = $db->topic(['forum' => $lp, 'user' => $user, 'last_post_at' => rt(100), 'last_post_id' => 5, 'last_poster_id' => $user]);
    $x2 = $db->topic(['forum' => $lp, 'user' => $user, 'last_post_at' => rt(200), 'last_post_id' => 9, 'last_poster_id' => $rate]);
    $db->topic(['forum' => $lp, 'user' => $user, 'last_post_at' => rt(300), 'status' => 'hidden']);
    T::same(['id' => $x2, 'user_id' => $user, 'last_post_id' => 9, 'last_post_at' => rt(200), 'last_poster_id' => $rate], $repo->lastPublished($lp), 'lastPublished: jüngste Aktivität unter den veröffentlichten');
    T::check($x1 > 0, 'Fixture');
}

function rScenarioTopicKeyset(Database $db): void
{
    $repo = new TopicRepository($db);
    $forum = $db->forum(['title' => 'Liste']);
    $other = $db->forum(['title' => 'Anderes']);
    $user = $db->user();
    // Sieben veröffentlichte Themen mit nur drei verschiedenen Zeitstempeln; dazu Themen, die fehlen müssen.
    $times = [0, 0, 60, 60, 60, 120, 120];
    $topics = [];
    foreach ($times as $seconds) {
        $topics[] = $db->topic(['forum' => $forum, 'user' => $user, 'created_at' => rt(-1000), 'last_post_at' => rt($seconds)]);
    }
    $db->topic(['forum' => $forum, 'user' => $user, 'last_post_at' => rt(120), 'status' => 'hidden']);
    $db->topic(['forum' => $forum, 'user' => $user, 'last_post_at' => rt(120), 'status' => 'pending']);
    $db->topic(['forum' => $forum, 'user' => $user, 'last_post_at' => rt(120), 'status' => 'deleted']);
    $db->topic(['forum' => $other, 'user' => $user, 'last_post_at' => rt(120)]);
    $expected = array_reverse($topics);   // gleiche Zeit: größere ID zuerst; Zeit absteigend

    [$all, $pages] = rCollect(static fn (?Cursor $c): Page => $repo->listForum($forum, rt(500), $c, 100));
    T::same($expected, rIds($all), 'eine große Seite: Reihenfolge Zeit absteigend, ID absteigend; ausgeblendet/wartend/gelöscht/fremdes Forum fehlen');
    T::same(1, $pages, 'eine große Seite ist die letzte (next = null)');

    foreach ([1, 2, 3, 4] as $size) {
        [$rows, $pages] = rCollect(static fn (?Cursor $c): Page => $repo->listForum($forum, rt(500), $c, $size));
        $ids = rIds($rows);
        T::same($expected, $ids, "Seitengröße {$size}: Reihenfolge gleich der Gesamtliste");
        T::same(count($ids), count(array_unique($ids)), "Seitengröße {$size}: keine Zeile doppelt");
        T::same((int) ceil(7 / $size), $pages, "Seitengröße {$size}: " . (int) ceil(7 / $size) . ' Seiten');
    }

    $first = $repo->listForum($forum, rt(500), null, 3);
    T::same(3, count($first->items), 'erste Seite hat limit Zeilen');
    $cursor = Cursor::decode($first->next);
    T::check($cursor !== null && $cursor->at === rt(60) && $cursor->id === $topics[4], 'Cursor = (last_post_at, ID) der letzten Zeile');
    $last = $repo->listForum($forum, rt(500), Cursor::decode($repo->listForum($forum, rt(500), $cursor, 3)->next), 3);
    T::same([$topics[0]], rIds($last->items), 'letzte Seite mit einer Zeile');
    T::same(null, $last->next, 'letzte Seite: next = null');
    T::same(null, $repo->listForum($forum, rt(500), null, 7)->next, 'genau eine volle Seite: kein Cursor');
    T::check($repo->listForum($forum, rt(500), null, 6)->next !== null, 'eine Zeile mehr als limit → Cursor');

    // Cursor hinter allen Zeilen, vor allen Zeilen
    T::same([], $repo->listForum($forum, rt(500), new Cursor(rt(-5), 1), 5)->items, 'Cursor hinter der ältesten Zeile: leere Seite');
    T::same($expected, rIds($repo->listForum($forum, rt(500), new Cursor(rt(500), 999999), 100)->items), 'Cursor vor der jüngsten Zeile: alles');

    // ungültige Cursor → erste Seite
    $firstIds = rIds($repo->listForum($forum, rt(500), null, 3)->items);
    $junk = ['', 'abc', str_repeat('A', 70), rtrim(strtr(base64_encode("x' OR 1=1|5"), '+/', '-_'), '='), rtrim(strtr(base64_encode('2026-11-15 10:00:00|0'), '+/', '-_'), '='), '%%%', 'MjAyNi0xMS0xNSAxMDowMDowMHxhYmM'];
    // B22: ein unmöglicher Zeitpunkt oder eine ID außerhalb von INT UNSIGNED ist ein ungültiger Cursor (erste Seite), keine leere Seite
    foreach (['9999-99-99 99:99:99|5', '0000-00-00 00:00:00|5', '2036-13-45 25:61:61|5', '2026-02-30 10:00:00|5', '2026-11-15 24:00:00|5', '2026-11-15 10:00:00|4294967296'] as $impossible) {
        $junk[] = rtrim(strtr(base64_encode($impossible), '+/', '-_'), '=');
    }
    $allNull = true;
    $sameAsFirst = true;
    foreach ($junk as $raw) {
        $decoded = Cursor::decode($raw);
        $allNull = $allNull && $decoded === null;
        $sameAsFirst = $sameAsFirst && rIds($repo->listForum($forum, rt(500), $decoded, 3)->items) === $firstIds;
    }
    T::check($allNull, 'ungültige Cursor-Texte dekodieren zu null');
    T::check($sameAsFirst, 'ungültige Cursor → erste Seite (nie Fehler, nie leere Seite)');

    // Grenzen und Status
    T::same(1, count($repo->listForum($forum, rt(500), null, 0)->items), 'limit 0 wird auf 1 geklemmt');
    T::same(7, count($repo->listForum($forum, rt(500), null, 100000)->items), 'limit wird auf 100 geklemmt (hier: alle 7)');
    T::same(1, count($repo->listForum($forum, rt(500), null, 100, 'pending')->items), 'Status pending liefert nur wartende Themen');
    T::same(7, count($repo->listForum($forum, rt(500), null, 100, 'kaputt')->items), 'unbekannter Status → published');
    T::same([], $repo->listForum(99999, rt(500))->items, 'unbekanntes Forum: leere Liste');
}

function rScenarioTopicPins(Database $db): void
{
    $repo = new TopicRepository($db);
    $forum = $db->forum(['title' => 'Pins']);
    $user = $db->user();
    $now = rt(1000);

    $normal = [];
    foreach ([100, 200, 300, 400, 500] as $seconds) {
        $normal[$seconds] = $db->topic(['forum' => $forum, 'user' => $user, 'last_post_at' => rt($seconds)]);
    }
    $pinForever = $db->topic(['forum' => $forum, 'user' => $user, 'last_post_at' => rt(50), 'pinned' => true]);
    $pinFuture = $db->topic(['forum' => $forum, 'user' => $user, 'last_post_at' => rt(150), 'pinned' => true, 'pinned_until' => rt(5000)]);
    $pinExpired = $db->topic(['forum' => $forum, 'user' => $user, 'last_post_at' => rt(350), 'pinned' => true, 'pinned_until' => rt(900)]);
    $lockedExpired = $db->topic(['forum' => $forum, 'user' => $user, 'last_post_at' => rt(250), 'locked' => true, 'locked_until' => rt(900)]);
    $lockedFuture = $db->topic(['forum' => $forum, 'user' => $user, 'last_post_at' => rt(450), 'locked' => true, 'locked_until' => rt(5000)]);
    $lockedForever = $db->topic(['forum' => $forum, 'user' => $user, 'last_post_at' => rt(10), 'locked' => true]);

    $first = $repo->listForum($forum, $now, null, 100);
    $ids = rIds($first->items);
    // Angeheftet zuerst (nach Aktivität): pinFuture (150) vor pinForever (50); dann der Strom nach Aktivität absteigend,
    // darin der abgelaufene Pin (350) an seiner Stelle.
    $expected = [$pinFuture, $pinForever, $normal[500], $lockedFuture, $normal[400], $pinExpired, $normal[300], $lockedExpired, $normal[200], $normal[100], $lockedForever];
    T::same($expected, $ids, 'erste Seite: wirksam angeheftete Themen zuerst, dann der Strom; abgelaufener Pin sortiert sich nach Aktivität ein');
    $byId = [];
    foreach ($first->items as $row) {
        $byId[(int) $row['id']] = $row;
    }
    T::same([1, 1, 0], [(int) $byId[$pinFuture]['is_pinned'], (int) $byId[$pinForever]['is_pinned'], (int) $byId[$pinExpired]['is_pinned']], 'is_pinned ist der wirksame Wert (abgelaufen → 0)');
    T::same([0, 1, 1], [(int) $byId[$lockedExpired]['is_locked'], (int) $byId[$lockedFuture]['is_locked'], (int) $byId[$lockedForever]['is_locked']], 'is_locked ist der wirksame Wert (locked_until abgelaufen → 0)');
    T::same(1, (int) $db->row('community_topics', ['id' => $pinExpired])['is_pinned'], 'die Datenbankzeile bleibt unverändert (Aufräumen macht ein Job)');

    // Seiten: Pins nur auf der ersten Seite, jede Zeile genau einmal
    foreach ([2, 3, 4] as $size) {
        $firstPage = $repo->listForum($forum, $now, null, $size);
        $pinnedCount = count(array_filter($firstPage->items, static fn (array $r): bool => (int) $r['is_pinned'] === 1));
        T::same(2, $pinnedCount, "Seitengröße {$size}: erste Seite zeigt beide wirksamen Pins zusätzlich zu {$size} Stromzeilen");
        T::same($size + 2, count($firstPage->items), "Seitengröße {$size}: erste Seite = Pins + limit Stromzeilen");
        [$rows] = rCollect(static fn (?Cursor $c): Page => $repo->listForum($forum, $now, $c, $size));
        T::same($expected, rIds($rows), "Seitengröße {$size}: Reihenfolge gleich der Gesamtliste, nichts doppelt oder verloren");
        $second = $repo->listForum($forum, $now, Cursor::decode((string) $firstPage->next), $size);
        T::same(0, count(array_filter($second->items, static fn (array $r): bool => (int) $r['is_pinned'] === 1)), "Seitengröße {$size}: Folgeseiten ohne angeheftete Themen");
    }

    // die Frist wird zur Abfragezeit ausgewertet: früher ist derselbe Pin noch wirksam
    $early = rIds($repo->listForum($forum, rt(800), null, 100)->items);
    T::same([$pinExpired, $pinFuture, $pinForever], array_slice($early, 0, 3), 'vor Ablauf von pinned_until ist das Thema angeheftet (Pins nach Aktivität absteigend: 350, 150, 50)');
    T::same(array_slice($early, 0, 3), rIds($repo->pinned($forum, rt(800))), 'pinned() liefert dieselben wirksamen Pins');
    T::same([$pinFuture, $pinForever], rIds($repo->pinned($forum, $now)), 'pinned(): nur wirksame Pins');
    T::same([$pinForever], rIds($repo->pinned($forum, rt(9000))), 'pinned(): später sind alle befristeten Pins abgelaufen, der unbefristete bleibt');
    T::same([], $repo->pinned(99999, $now), 'pinned(): unbekanntes Forum → []');
}

function rScenarioTopicDuplicates(Database $db): void
{
    $repo = new TopicRepository($db);
    $forum = $db->forum(['title' => 'Doppelt']);
    $otherForum = $db->forum(['title' => 'Anders']);
    $user = $db->user();
    $stranger = $db->user();

    $topic = $db->topic(['forum' => $forum, 'user' => $user, 'title' => 'Hilfe gesucht', 'created_at' => rt(0)]);
    $post = $db->post(['topic' => $topic, 'user' => $user, 'first' => true, 'body' => 'Mein Text', 'created_at' => rt(0)]);
    $db->execute('UPDATE ' . $db->getPrefix() . 'community_topics SET first_post_id = ? WHERE id = ?', [$post, $topic]);

    $hit = $repo->findRecentDuplicate($user, $forum, 'Hilfe gesucht', rt(-120));
    T::same(['id' => $topic, 'first_post_id' => $post, 'status' => 'published', 'forum_id' => $forum, 'title' => 'Hilfe gesucht'], $hit, 'gleicher Titel im Fenster → Treffer mit Kerndaten');
    T::same($topic, $repo->findRecentDuplicate($user, $forum, 'Hilfe gesucht', rt(-120), 'Mein Text')['id'] ?? 0, 'mit gleichem Text → Treffer');
    T::same(null, $repo->findRecentDuplicate($user, $forum, 'Hilfe gesucht', rt(-120), 'Anderer Text'), 'gleicher Titel, anderer Text → kein Treffer');
    T::same(null, $repo->findRecentDuplicate($user, $forum, 'Hilfe gesucht', rt(1)), 'außerhalb des Fensters → kein Treffer');
    T::same($topic, $repo->findRecentDuplicate($user, $forum, 'Hilfe gesucht', rt(0))['id'] ?? 0, 'Fenstergrenze ist einschließlich');
    T::same(null, $repo->findRecentDuplicate($user, $otherForum, 'Hilfe gesucht', rt(-120)), 'anderes Forum → kein Treffer');
    T::same(null, $repo->findRecentDuplicate($stranger, $forum, 'Hilfe gesucht', rt(-120)), 'anderes Mitglied → kein Treffer');
    T::same(null, $repo->findRecentDuplicate($user, $forum, 'Hilfe Gesucht', rt(-120)), 'Titel exakt verglichen (Groß-/Kleinschreibung zählt, auch in MariaDB)');
    T::same(null, $repo->findRecentDuplicate($user, $forum, 'Anderer Titel', rt(-120)), 'anderer Titel → kein Treffer');
    $db->topic(['forum' => $otherForum, 'user' => $user, 'title' => 'Neueres Thema', 'created_at' => rt(30)]);
    for ($i = 0; $i < 4; $i++) {
        $db->topic(['forum' => $forum, 'user' => $user, 'title' => 'Anderes ' . $i, 'created_at' => rt(40 + $i)]);
    }
    T::same($topic, $repo->findRecentDuplicate($user, $forum, 'Hilfe gesucht', rt(-120))['id'] ?? 0, 'neuere Themen desselben Mitglieds verdecken das ältere Duplikat nicht');
    $db->execute('UPDATE ' . $db->getPrefix() . 'community_topics SET status = ? WHERE id = ?', ['deleted', $topic]);
    T::same(null, $repo->findRecentDuplicate($user, $forum, 'Hilfe gesucht', rt(-120)), 'gelöschtes Thema zählt nicht');
    $db->execute('UPDATE ' . $db->getPrefix() . 'community_topics SET status = ? WHERE id = ?', ['pending', $topic]);
    T::same('pending', $repo->findRecentDuplicate($user, $forum, 'Hilfe gesucht', rt(-120))['status'] ?? '', 'wartendes Thema zählt');
}

function rScenarioPosts(Database $db): void
{
    $repo = new PostRepository($db);
    $forum = $db->forum(['title' => 'Beiträge']);
    $user = $db->user();
    $topic = $db->topic(['forum' => $forum, 'user' => $user]);

    $id = $repo->insert(['topic_id' => $topic, 'forum_id' => $forum, 'user_id' => $user, 'is_first_post' => 1, 'status' => 'published', 'body' => 'Erster Beitrag', 'created_at' => rt(), 'updated_at' => rt()]);
    $row = $repo->find($id);
    T::check($id > 0 && ($row['body'] ?? '') === 'Erster Beitrag', 'insert liefert die ID, find die Zeile');
    T::same([null, 0, null, 0, 'markdown', rt()], [$row['body_html'], (int) $row['rendered_version'], $row['ip_hash'], (int) $row['edit_count'], $row['format'], $row['created_at']], 'Standardwerte: body_html NULL, rendered_version 0, ip_hash NULL, Zeit wie übergeben');
    T::same([$topic, $forum, $user, 1], [(int) $row['topic_id'], (int) $row['forum_id'], (int) $row['user_id'], (int) $row['is_first_post']], 'IDs als Ganzzahlen');
    T::same(null, $repo->find(99999), 'find: unbekannt → null');
    try {
        $repo->insert(['topic_id' => $topic, 'bogus' => 1]);
        T::check(false, 'insert lehnt unbekannte Spalte ab');
    } catch (\InvalidArgumentException) {
        T::check(true, 'insert lehnt unbekannte Spalte ab');
    }

    T::check($repo->update($id, ['status' => 'hidden', 'status_reason' => 'Test', 'updated_at' => rt(5)]), 'update: geändert → true');
    $row = $repo->find($id);
    T::same(['hidden', 'Test', rt(5)], [$row['status'], $row['status_reason'], $row['updated_at']], 'update schreibt die Spalten');
    T::same(false, $repo->update($id, []), 'update ohne Spalten → false');
    T::same(false, $repo->update(99999, ['status' => 'hidden']), 'update: unbekannte ID → false');
    // Text und Cache nur über insert(), applyEdit(), storeRendered() und clearRendered() (PR #2b: Wächter des Nachrenderns).
    foreach (['user_id', 'edit_count', 'created_at', 'id', 'body', 'body_html', 'rendered_version', 'ip_hash'] as $column) {
        try {
            $repo->update($id, [$column => 1]);
            T::check(false, "update lehnt Spalte {$column} ab");
        } catch (\InvalidArgumentException) {
            T::check(true, "update lehnt Spalte {$column} ab");
        }
    }
    $db->log = [];
    T::same($id, (int) ($repo->lockForUpdate($id)['id'] ?? 0), 'lockForUpdate liefert die Zeile');
    T::same(null, $repo->lockForUpdate(99999), 'lockForUpdate: unbekannt → null');
    T::check(count(array_filter($db->log, static fn (string $sql): bool => str_ends_with($sql, ' LIMIT 1 FOR UPDATE'))) === 2, 'lockForUpdate hängt FOR UPDATE an');

    // Bearbeitung
    $post = $repo->insert(['topic_id' => $topic, 'forum_id' => $forum, 'user_id' => $user, 'status' => 'published', 'body' => 'Alt', 'body_html' => '<p>Alt</p>', 'rendered_version' => 3, 'created_at' => rt(), 'updated_at' => rt()]);
    T::check($repo->applyEdit($post, 'Neu', $user, 'Tippfehler', rt(60)), 'applyEdit: Beitrag gefunden');
    $row = $repo->find($post);
    T::same(['Neu', null, 0, 1, rt(60), $user, 'Tippfehler', rt(60)], [$row['body'], $row['body_html'], (int) $row['rendered_version'], (int) $row['edit_count'], $row['edited_at'], (int) $row['edited_by'], $row['edit_reason'], $row['updated_at']], 'applyEdit: Text neu, Cache verworfen, edit_count 1, Bearbeiter, Grund, Zeit');
    $db->execute('UPDATE ' . $db->getPrefix() . 'community_posts SET edit_count = 5 WHERE id = ?', [$post]);
    $repo->applyEdit($post, 'Neuer', null, null, rt(120));
    $row = $repo->find($post);
    T::same([6, null, null], [(int) $row['edit_count'], $row['edited_by'], $row['edit_reason']], 'applyEdit rechnet auf dem Datenbankwert (atomar); Bearbeiter und Grund dürfen NULL sein');
    T::same(false, $repo->applyEdit(99999, 'x', $user, null, rt()), 'applyEdit: unbekannt → false');

    // Verlauf
    $r1 = $repo->revisionInsert(['post_id' => $post, 'title' => 'Alter Titel', 'body' => 'Alt', 'format' => 'markdown', 'edited_by' => $user, 'edit_reason' => 'Tippfehler', 'created_at' => rt(60)]);
    $r2 = $repo->revisionInsert(['post_id' => $post, 'body' => 'Neu', 'edited_by' => $user, 'created_at' => rt(120)]);
    $r3 = $repo->revisionInsert(['post_id' => $post, 'body' => 'Dritte', 'edited_by' => null, 'created_at' => rt(120)]);
    $other = $repo->revisionInsert(['post_id' => $post + 1000, 'body' => 'Fremd', 'created_at' => rt(500)]);
    T::check($r1 > 0 && $r2 > $r1 && $other > $r3, 'revisionInsert liefert IDs');
    $revisions = $repo->revisions($post);
    T::same([$r3, $r2, $r1], rIds($revisions), 'revisions: jüngste zuerst (gleiche Zeit: größere ID zuerst), nur dieser Beitrag');
    T::same(['Alter Titel', null], [$revisions[2]['title'], $revisions[1]['title']], 'title nur bei der ersten Revision');
    T::same(['markdown', null, 'Tippfehler'], [$revisions[2]['format'], $revisions[0]['edited_by'], $revisions[2]['edit_reason']], 'Revisionsspalten');
    T::same([$r3], rIds($repo->revisions($post, 1)), 'revisions: limit');
    T::same([], $repo->revisions(99999), 'revisions: unbekannter Beitrag → []');
    try {
        $repo->revisionInsert(['post_id' => $post, 'bogus' => 1]);
        T::check(false, 'revisionInsert lehnt unbekannte Spalte ab');
    } catch (\InvalidArgumentException) {
        T::check(true, 'revisionInsert lehnt unbekannte Spalte ab');
    }
}

function rScenarioPostLists(Database $db): void
{
    $repo = new PostRepository($db);
    $forum = $db->forum(['title' => 'Listen']);
    $user = $db->user();
    $topic = $db->topic(['forum' => $forum, 'user' => $user]);
    $other = $db->topic(['forum' => $forum, 'user' => $user]);

    $p1 = $db->post(['topic' => $topic, 'user' => $user, 'first' => true]);
    $p2 = $db->post(['topic' => $topic, 'user' => $user, 'status' => 'hidden', 'status_reason' => 'Spam', 'body' => 'geheim']);
    $p3 = $db->post(['topic' => $topic, 'user' => $user]);
    $p4 = $db->post(['topic' => $topic, 'user' => $user, 'status' => 'pending']);
    $p5 = $db->post(['topic' => $topic, 'user' => $user]);
    $p6 = $db->post(['topic' => $topic, 'user' => $user, 'status' => 'deleted']);
    $p7 = $db->post(['topic' => $topic, 'user' => $user]);
    $p8 = $db->post(['topic' => $topic, 'user' => $user, 'status' => 'rejected']);
    $o1 = $db->post(['topic' => $other, 'user' => $user, 'first' => true]);

    $page = $repo->listPublished($topic, 0, 2);
    T::same([$p1, $p3], rIds($page->items), 'listPublished: nur veröffentlichte, nach ID');
    T::same((string) $p3, $page->next, 'next = ID des letzten Beitrags als Text');
    $page = $repo->listPublished($topic, (int) $page->next, 2);
    T::same([$p5, $p7], rIds($page->items), 'nächste Seite ab der ID');
    T::same(null, $page->next, 'letzte Seite: next = null (genau eine volle Seite)');
    T::same([], $repo->listPublished($topic, $p7, 2)->items, 'hinter dem letzten Beitrag: leer');
    $all = $repo->listPublished($topic, 0, 100);
    T::same([$p1, $p3, $p5, $p7], rIds($all->items), 'alle veröffentlichten Beiträge');
    T::check(isset($all->items[0]['body']) && array_key_exists('body_html', $all->items[0]), 'listPublished liefert vollständige Zeilen');
    T::same(null, $all->next, 'eine Seite: kein next');
    T::same([$o1], rIds($repo->listPublished($other, 0, 10)->items), 'andere Themen getrennt');
    T::same(1, count($repo->listPublished($topic, 0, 0)->items), 'limit 0 → 1');
    T::same([$p1, $p3, $p5, $p7], rIds($repo->listPublished($topic, -5, 100)->items), 'negative afterId → 0');

    // Platzhalter über den ID-Bereich der Seite
    $holes = $repo->placeholdersInRange($topic, $p1, $p7);
    T::same([$p2, $p6], rIds($holes), 'placeholdersInRange: hidden und deleted im Bereich, aufsteigend; pending/rejected nicht');
    T::same(['hidden', 'Spam'], [$holes[0]['status'], $holes[0]['status_reason']], 'Platzhalter tragen Status und Grund');
    T::check(!array_key_exists('body', $holes[0]) && !array_key_exists('body_html', $holes[0]), 'Platzhalter enthalten keinen Text');
    T::same([$p2], rIds($repo->placeholdersInRange($topic, $p2, $p5)), 'Bereich einschließlich der Grenzen');
    T::same([$p6], rIds($repo->placeholdersInRange($topic, $p5 + 1, $p7)), 'Bereich ohne Platzhalter davor');
    T::same([], $repo->placeholdersInRange($other, $p1, $p8), 'Platzhalter eines anderen Themas fehlen');
    T::same([], $repo->placeholdersInRange($topic, $p7, $p1), 'umgekehrter Bereich → leer');

    // Position
    T::same([1, 2, 3, 4], [$repo->positionOf($topic, $p1), $repo->positionOf($topic, $p3), $repo->positionOf($topic, $p5), $repo->positionOf($topic, $p7)], 'positionOf: 1-basiert über die veröffentlichten Beiträge');
    T::same(2, $repo->positionOf($topic, $p2), 'positionOf eines ausgeblendeten Beitrags: Stelle, an der er stünde');
    T::same(3, $repo->positionOf($topic, $p4), 'positionOf eines wartenden Beitrags');
    T::same(1, $repo->positionOf($other, $o1), 'positionOf: eigenes Thema zählt');
    T::same(1, $repo->positionOf($topic, 0), 'positionOf: ID 0 → 1');
}

function rScenarioPostQueries(Database $db): void
{
    $repo = new PostRepository($db);
    $forum = $db->forum(['title' => 'Abfragen']);
    $sub = $db->forum(['title' => 'Unterforum', 'parent' => $forum]);
    $user = $db->user();
    $stranger = $db->user();
    $topic = $db->topic(['forum' => $forum, 'user' => $user]);
    $topic2 = $db->topic(['forum' => $forum, 'user' => $user]);
    $subTopic = $db->topic(['forum' => $sub, 'user' => $user]);

    // letzter Beitrag: Thema nach ID, Forum nach Zeit (die ID-Reihenfolge ist hier absichtlich anders als die Zeit)
    T::same(null, $repo->lastPublishedInTopic($topic), 'lastPublishedInTopic: ohne Beiträge → null');
    T::same(null, $repo->lastPublishedInForum($forum), 'lastPublishedInForum: ohne Beiträge → null');
    $late = $db->post(['topic' => $topic, 'user' => $user, 'created_at' => rt(500)]);
    $early = $db->post(['topic' => $topic, 'user' => $stranger, 'created_at' => rt(100)]);
    $hidden = $db->post(['topic' => $topic, 'user' => $user, 'created_at' => rt(900), 'status' => 'hidden']);
    $db->post(['topic' => $subTopic, 'user' => $user, 'created_at' => rt(950)]);
    T::same(['id' => $early, 'topic_id' => $topic, 'forum_id' => $forum, 'user_id' => $stranger, 'created_at' => rt(100)], $repo->lastPublishedInTopic($topic), 'lastPublishedInTopic: größte ID unter den veröffentlichten');
    T::same(['id' => $late, 'topic_id' => $topic, 'forum_id' => $forum, 'user_id' => $user, 'created_at' => rt(500)], $repo->lastPublishedInForum($forum), 'lastPublishedInForum: jüngste Zeit; Unterforum und ausgeblendet zählen nicht');
    T::check($hidden > $late, 'Fixture: ausgeblendeter Beitrag hat die größte ID');

    // Zählen und Abstände
    $rate = $db->user();
    $db->post(['topic' => $topic, 'user' => $rate, 'created_at' => rt(-86400 - 10)]);
    $a = $db->post(['topic' => $topic, 'user' => $rate, 'created_at' => rt(-3600)]);
    $b = $db->post(['topic' => $topic2, 'user' => $rate, 'created_at' => rt(-1800), 'status' => 'pending']);
    $c = $db->post(['topic' => $subTopic, 'user' => $rate, 'created_at' => rt(-900), 'status' => 'hidden']);
    $d = $db->post(['topic' => $topic, 'user' => $rate, 'created_at' => rt(-600), 'status' => 'deleted']);
    $db->post(['topic' => $topic, 'user' => $rate, 'created_at' => rt(-300), 'status' => 'rejected']);
    T::same(3, $repo->countSince($rate, rt(-86400)), 'countSince: veröffentlicht, wartend, ausgeblendet; gelöscht, abgelehnt und ältere nicht');
    T::same(4, $repo->countSince($rate, rt(-86400 - 10)), 'countSince: Grenze einschließlich');
    T::same(1, $repo->countSince($rate, rt(-86400), ['deleted']), 'countSince mit eigenem Status');
    T::same(2, $repo->countSince($rate, rt(-86400), ['deleted', 'rejected']), 'countSince mit zwei Status');
    T::same(0, $repo->countSince($rate + 99, rt(-86400)), 'countSince: anderes Mitglied');
    foreach ([['kaputt'], []] as $bad) {
        try {
            $repo->countSince($rate, rt(-86400), $bad);
            T::check(false, 'countSince lehnt ungültige Status ab');
        } catch (\InvalidArgumentException) {
            T::check(true, 'countSince lehnt ungültige Status ab');
        }
    }
    T::same(['id' => $b, 'topic_id' => $topic2, 'forum_id' => $forum, 'created_at' => rt(-1800)], $repo->lastOfUser($rate, rt(-86400)), 'lastOfUser: jüngster veröffentlichter/wartender Beitrag (ausgeblendete und gelöschte zählen nicht)');
    T::same($b, $repo->lastOfUser($rate, rt(-86400), $forum)['id'] ?? 0, 'lastOfUser im Forum');
    T::same($a, $repo->lastOfUser($rate, rt(-86400), null, $topic)['id'] ?? 0, 'lastOfUser im Thema');
    T::same(null, $repo->lastOfUser($rate, rt(-86400), $sub), 'lastOfUser: im Unterforum nur ein ausgeblendeter Beitrag → null');
    T::same(null, $repo->lastOfUser($rate, rt(0)), 'lastOfUser: nichts seit der Grenze');
    T::check($c > 0 && $d > 0, 'Fixtures');
    // mit Statusliste (die Rechteprüfung zählt auch Gelöschtes, damit Löschen keinen Abstand freigibt)
    T::same($d, $repo->lastOfUser($rate, rt(-86400), null, null, ['published', 'pending', 'hidden', 'deleted'])['id'] ?? 0, 'lastOfUser mit Statusliste: der gelöschte Beitrag ist der jüngste');
    T::same($c, $repo->lastOfUser($rate, rt(-86400), null, null, ['hidden'])['id'] ?? 0, 'lastOfUser nur ausgeblendet');
    T::same($c, $repo->lastOfUser($rate, rt(-86400), $sub, null, ['hidden', 'deleted'])['id'] ?? 0, 'lastOfUser mit Statusliste im Unterforum');
    T::same($d, $repo->lastOfUser($rate, rt(-86400), null, $topic, ['deleted'])['id'] ?? 0, 'lastOfUser mit Statusliste im Thema');
    T::same(null, $repo->lastOfUser($rate, rt(-86400), $sub, null, ['deleted']), 'lastOfUser: im Unterforum kein gelöschter Beitrag → null');
    foreach ([['kaputt'], []] as $bad) {
        try {
            $repo->lastOfUser($rate, rt(-86400), null, null, $bad);
            T::check(false, 'lastOfUser lehnt ungültige Status ab');
        } catch (\InvalidArgumentException) {
            T::check(true, 'lastOfUser lehnt ungültige Status ab');
        }
    }

    // doppeltes Absenden
    $dupUser = $db->user();
    $dup = $db->post(['topic' => $topic, 'user' => $dupUser, 'body' => 'Gleicher Text', 'created_at' => rt(0)]);
    T::same(['id' => $dup, 'topic_id' => $topic, 'status' => 'published', 'is_first_post' => 0], $repo->findRecentDuplicate($dupUser, $topic, 'Gleicher Text', rt(-120)), 'gleicher Text im Thema im Fenster → Treffer');
    T::same(null, $repo->findRecentDuplicate($dupUser, $topic, 'Gleicher Text', rt(1)), 'außerhalb des Fensters → null');
    T::same($dup, $repo->findRecentDuplicate($dupUser, $topic, 'Gleicher Text', rt(0))['id'] ?? 0, 'Fenstergrenze einschließlich');
    T::same(null, $repo->findRecentDuplicate($dupUser, $topic2, 'Gleicher Text', rt(-120)), 'anderes Thema → null');
    T::same(null, $repo->findRecentDuplicate($stranger, $topic, 'Gleicher Text', rt(-120)), 'anderes Mitglied → null');
    T::same(null, $repo->findRecentDuplicate($dupUser, $topic, 'gleicher text', rt(-120)), 'Text exakt verglichen (Groß-/Kleinschreibung zählt)');
    T::same(null, $repo->findRecentDuplicate($dupUser, $topic, 'Anderer Text', rt(-120)), 'anderer Text → null');
    foreach ([$topic2, $topic2, $topic2, $topic2, $topic2, $topic2] as $i => $other) {
        $db->post(['topic' => $other, 'user' => $dupUser, 'body' => 'Anderer Beitrag ' . $i, 'created_at' => rt(10 + $i)]);
    }
    T::same($dup, $repo->findRecentDuplicate($dupUser, $topic, 'Gleicher Text', rt(-120))['id'] ?? 0, 'neuere Beiträge desselben Mitglieds in anderen Themen verdecken das Duplikat nicht');
    $db->execute('UPDATE ' . $db->getPrefix() . 'community_posts SET status = ? WHERE id = ?', ['deleted', $dup]);
    T::same(null, $repo->findRecentDuplicate($dupUser, $topic, 'Gleicher Text', rt(-120)), 'gelöschter Beitrag zählt nicht');
}

function rScenarioReadStates(Database $db): void
{
    $repo = new ReadStateRepository($db);
    $forums = new ForumRepository($db);
    $user = $db->user();
    $user2 = $db->user();
    $forum = $db->forum(['title' => 'Lesen']);
    $topic = $db->topic(['forum' => $forum, 'user' => $user]);
    $topic2 = $db->topic(['forum' => $forum, 'user' => $user]);
    $state = static fn (int $u, int $t): ?array => $db->row('community_read_states', ['user_id' => $u, 'topic_id' => $t]);

    T::same(true, $repo->advance($user, $topic, 10, rt(1)), 'advance: neue Marke → true');
    T::same([10, rt(1)], [(int) $state($user, $topic)['last_read_post_id'], $state($user, $topic)['updated_at']], 'Marke angelegt');
    T::same(false, $repo->advance($user, $topic, 10, rt(2)), 'advance mit gleicher ID → false');
    T::same(false, $repo->advance($user, $topic, 5, rt(3)), 'advance mit kleinerer ID → false');
    T::same([10, rt(1)], [(int) $state($user, $topic)['last_read_post_id'], $state($user, $topic)['updated_at']], 'kleinere oder gleiche ID ändert nichts (auch updated_at nicht)');
    T::same(true, $repo->advance($user, $topic, 12, rt(4)), 'advance mit größerer ID → true');
    T::same([12, rt(4)], [(int) $state($user, $topic)['last_read_post_id'], $state($user, $topic)['updated_at']], 'Marke steigt');
    T::same(true, $repo->advance($user, $topic2, 3, rt(5)), 'andere Themen getrennt');
    T::same(true, $repo->advance($user2, $topic, 2, rt(5)), 'andere Mitglieder getrennt');
    T::same([12, 2], [(int) $state($user, $topic)['last_read_post_id'], (int) $state($user2, $topic)['last_read_post_id']], 'Marken je Mitglied');
    T::same(1, $db->count('community_read_states', ['user_id' => $user, 'topic_id' => $topic]), 'genau eine Zeile je Mitglied und Thema');
    // Reihenfolge der Anweisungen: INSERT IGNORE zuerst. Ein UPDATE auf eine fehlende Zeile sperrt die Lücke im Index, und zwei
    // Transaktionen mit Lückensperren auf derselben Lücke geraten beim Einfügen in einen Deadlock (Befund B3: Anlegen eines Themas).
    $fresh = $db->topic(['forum' => $forum, 'user' => $user]);
    $verbs = static fn (): array => array_map(static fn (string $sql): string => strtok(trim($sql), ' '), $db->log);
    $db->log = [];
    T::same(true, $repo->advance($user, $fresh, 7, rt(10)), 'advance: neue Marke für ein Thema ohne Lesezeile');
    T::same(['INSERT'], $verbs(), 'neue Marke: nur ein INSERT IGNORE, kein UPDATE auf die fehlende Zeile davor (keine Lückensperre)');
    $db->log = [];
    T::same(true, $repo->advance($user, $fresh, 9, rt(11)), 'advance: bestehende Marke steigt');
    T::same(['INSERT', 'UPDATE'], $verbs(), 'bestehende Marke: INSERT IGNORE ohne Wirkung, dann UPDATE');
    $db->log = [];
    T::same(false, $repo->advance($user, $fresh, 9, rt(12)), 'advance: gleiche ID → false');
    T::same(['INSERT', 'UPDATE'], $verbs(), 'Wiederbesuch: INSERT IGNORE ohne Wirkung und ein UPDATE ohne Treffer');
    T::same([9, rt(11)], [(int) $state($user, $fresh)['last_read_post_id'], $state($user, $fresh)['updated_at']], 'Wiederbesuch schreibt nichts (Stand und updated_at bleiben)');
    T::same([false, false, false], [$repo->advance($user, $topic, 0, rt()), $repo->advance(0, $topic, 5, rt()), $repo->advance($user, 0, 5, rt())], 'ungültige IDs → false, nichts geschrieben');
    T::same(4, $db->count('community_read_states'), 'keine Zeilen für ungültige Aufrufe');

    T::same([$topic => 12, $topic2 => 3], $repo->forTopics($user, [$topic, $topic2, 99999]), 'forTopics: Map Thema → ID, unbekannte fehlen');
    T::same([], $repo->forTopics($user, []), 'forTopics: leere Liste → []');
    T::same([], $repo->forTopics(0, [$topic]), 'forTopics: Gast → []');
    T::same([$topic => 2], $repo->forTopics($user2, [$topic, $topic2]), 'forTopics je Mitglied');

    // Foren-Marken
    $parent = $db->forum(['title' => 'Eltern']);
    $child = $db->forum(['title' => 'Kind', 'parent' => $parent]);
    $unrelated = $db->forum(['title' => 'Fremd']);
    T::same([], $repo->marks($user, [$child]), 'marks: keine Marken');
    T::same(null, $repo->latestMark($user, [$child]), 'latestMark: keine Marke → null');
    T::same(true, $repo->setMark($user, $parent, rt(100)), 'setMark: neue Marke → true');
    T::same(true, $repo->setMark($user, 0, rt(50)), 'setMark: globale Marke (forum_id 0) → true');
    T::same(true, $repo->setMark($user, $unrelated, rt(300)), 'setMark: fremdes Forum');
    $ancestors = $forums->ancestorIds($child);
    T::same([0 => rt(50), $parent => rt(100)], $repo->marks($user, $ancestors), 'marks: Vorfahren und global (0), fremdes Forum fehlt');
    T::same(rt(100), $repo->latestMark($user, $ancestors), 'latestMark: jüngste Marke über Vorfahren und global');
    T::same(rt(300), $repo->latestMark($user, [$unrelated]), 'latestMark eines anderen Forums');
    T::same([0 => rt(50)], $repo->marks($user, []), 'marks ohne Foren: nur global');
    T::same([], $repo->marks($user2, $ancestors), 'marks: anderes Mitglied');
    T::same([], $repo->marks(0, $ancestors), 'marks: Gast');
    T::same(false, $repo->setMark($user, $parent, rt(80)), 'setMark mit älterer Zeit → false');
    T::same(false, $repo->setMark($user, $parent, rt(100)), 'setMark mit gleicher Zeit → false');
    T::same(rt(100), $repo->marks($user, [$parent])[$parent] ?? null, 'ältere Marke ändert nichts');
    T::same(true, $repo->setMark($user, $parent, rt(150)), 'setMark mit neuerer Zeit → true');
    T::same(rt(150), $repo->latestMark($user, $ancestors), 'Marke steigt');
    T::same([false, false], [$repo->setMark(0, $parent, rt()), $repo->setMark($user, -1, rt())], 'setMark: ungültige Aufrufe → false');
    T::same(1, $db->count('community_forum_read_marks', ['user_id' => $user, 'forum_id' => $parent]), 'genau eine Zeile je Mitglied und Forum');

    // erster ungelesener Beitrag
    $t = $db->topic(['forum' => $forum, 'user' => $user]);
    $p1 = $db->post(['topic' => $t, 'user' => $user, 'first' => true]);
    $p2 = $db->post(['topic' => $t, 'user' => $user, 'status' => 'hidden']);
    $p3 = $db->post(['topic' => $t, 'user' => $user]);
    $p4 = $db->post(['topic' => $t, 'user' => $user, 'status' => 'pending']);
    $p5 = $db->post(['topic' => $t, 'user' => $user]);
    T::same($p1, $repo->firstUnread($t, 0), 'firstUnread: erster veröffentlichter Beitrag');
    T::same($p3, $repo->firstUnread($t, $p1), 'firstUnread überspringt ausgeblendete');
    T::same($p5, $repo->firstUnread($t, $p3), 'firstUnread überspringt wartende');
    T::same(null, $repo->firstUnread($t, $p5), 'firstUnread: nichts mehr → null');
    T::same(null, $repo->firstUnread(99999, 0), 'firstUnread: unbekanntes Thema → null');
    T::check($p2 > 0 && $p4 > 0, 'Fixtures');
}

function rScenarioUnreadCount(Database $db): void
{
    $repo = new ReadStateRepository($db);
    $user = $db->user();
    $forum = $db->forum(['title' => 'Ungelesen']);
    $sub = $db->forum(['title' => 'Unter', 'parent' => $forum]);
    $elsewhere = $db->forum(['title' => 'Woanders']);
    $since = rt(100);

    $read = $db->topic(['forum' => $forum, 'user' => $user, 'last_post_at' => rt(200), 'last_post_id' => 10]);
    $partly = $db->topic(['forum' => $forum, 'user' => $user, 'last_post_at' => rt(210), 'last_post_id' => 20]);
    $never = $db->topic(['forum' => $forum, 'user' => $user, 'last_post_at' => rt(220), 'last_post_id' => 30]);
    $old = $db->topic(['forum' => $forum, 'user' => $user, 'last_post_at' => rt(100), 'last_post_id' => 40]);
    $hidden = $db->topic(['forum' => $forum, 'user' => $user, 'last_post_at' => rt(230), 'last_post_id' => 50, 'status' => 'hidden']);
    $inSub = $db->topic(['forum' => $sub, 'user' => $user, 'last_post_at' => rt(240), 'last_post_id' => 60]);
    $db->topic(['forum' => $elsewhere, 'user' => $user, 'last_post_at' => rt(250), 'last_post_id' => 70]);
    $repo->advance($user, $read, 10, rt(300));
    $repo->advance($user, $partly, 15, rt(300));

    T::same(2, $repo->unreadCount($user, [$forum], $since), 'unreadCount: teilweise und nie gelesen; gelesen, alt, ausgeblendet, Unterforum und fremdes Forum nicht');
    T::same(3, $repo->unreadCount($user, [$forum, $sub], $since), 'unreadCount mit Unterforum');
    T::same(1, $repo->unreadCount($user, [$forum], $since, 1), 'unreadCount: Obergrenze');
    T::same(2, $repo->unreadCount($user, [$forum, $sub], $since, 2), 'Obergrenze 2 von 3');
    T::same(3, $repo->unreadCount($user, [$forum], rt(99)), 'unreadCount: Untergrenze einschließlich der Zeit darunter (old zählt ab rt(99))');
    T::same(0, $repo->unreadCount($user, [$forum], rt(500)), 'unreadCount: Untergrenze nach aller Aktivität');
    T::same(3, $repo->unreadCount($db->user(), [$forum], $since), 'anderes Mitglied ohne Lesezeilen: alles ungelesen');
    T::same(0, $repo->unreadCount(0, [$forum], $since), 'Gast: 0');
    T::same(0, $repo->unreadCount($user, [], $since), 'keine Foren: 0');
    $repo->advance($user, $never, 30, rt(301));
    T::same(1, $repo->unreadCount($user, [$forum], $since), 'nach dem Lesen sinkt die Zahl');
    T::check($old > 0 && $hidden > 0 && $inSub > 0, 'Fixtures');

    // is_pinned IN (0, 1) hält die Abfrage auf dem Index (forum_id, status, is_pinned, last_post_at), filtert aber nichts: angeheftete Themen zählen
    $pinned = $db->topic(['forum' => $forum, 'user' => $user, 'last_post_at' => rt(260), 'last_post_id' => 80, 'pinned' => true]);
    $expired = $db->topic(['forum' => $forum, 'user' => $user, 'last_post_at' => rt(270), 'last_post_id' => 90, 'pinned' => true, 'pinned_until' => rt(-60)]);
    T::same(3, $repo->unreadCount($user, [$forum], $since), 'angeheftete Themen (auch mit abgelaufener Frist) zählen als ungelesen');
    $db->log = [];
    $repo->unreadCount($user, [$forum], $since);
    T::check(count(array_filter($db->log, static fn (string $sql): bool => str_contains($sql, 't.is_pinned IN (0, 1)'))) === 1, 'unreadCount fragt über beide Werte von is_pinned (Index idx_community_topics_forum_list)');
    T::check($pinned > 0 && $expired > 0, 'Fixtures (angeheftet)');
}

function rScenarioMembers(Database $db): void
{
    $repo = new MemberRepository($db);
    $editor = $db->user(['role' => 'editor']);
    $banned = $db->user(['status' => 'banned']);
    T::same(['id' => $editor, 'role' => 'editor', 'status' => 'active'], $repo->find($editor), 'find: id, role, status mit festen Typen');
    T::same('banned', $repo->find($banned)['status'] ?? '', 'find liefert den Status (die Entscheidung trifft CommunityAccess)');
    T::same(null, $repo->find(99999), 'find: unbekannt → null');
    T::same(['id', 'role', 'status'], array_keys($repo->find($editor) ?? []), 'find liefert nur id, role, status (keine Hashes oder E-Mail)');

    $g1 = $db->group('Team');
    $g2 = $db->group('Beirat');
    $off = $db->group('Archiv', false);
    $other = $db->group('Andere');
    foreach ([$g2, $g1, $off] as $group) {
        $db->join($editor, $group);
    }
    $db->join($banned, $other);
    T::same([$g1, $g2], $repo->groupIds($editor), 'groupIds: aktive Gruppen, aufsteigend; inaktive Gruppe fehlt');
    T::same([$other], $repo->groupIds($banned), 'groupIds je Mitglied');
    T::same([], $repo->groupIds($db->user()), 'groupIds: keine Gruppe → []');
    T::same([], $repo->groupIds(99999), 'groupIds: unbekannt → []');
}

function rScenarioBans(Database $db): void
{
    $repo = new BanRepository($db);
    $user = $db->user();
    $now = rt(1000);
    T::same(null, $repo->activeFor($user, $now), 'keine Sperre → null');

    $future = $db->ban(['user' => $user, 'starts_at' => rt(2000)]);
    T::same(null, $repo->activeFor($user, $now), 'Sperre mit späterem Beginn ist noch nicht wirksam');
    $db->execute('DELETE FROM ' . $db->getPrefix() . 'community_bans WHERE id = ?', [$future]);
    $db->ban(['user' => $user, 'ends_at' => rt(500)]);
    T::same(null, $repo->activeFor($user, $now), 'abgelaufene Sperre zählt nicht');
    $db->ban(['user' => $user, 'lifted_at' => rt(900)]);
    T::same(null, $repo->activeFor($user, $now), 'aufgehobene Sperre zählt nicht');
    $db->ban(['user' => $user, 'starts_at' => rt(1000), 'ends_at' => rt(1000)]);
    T::same(null, $repo->activeFor($user, $now), 'ends_at = jetzt: abgelaufen (ends_at muss später sein)');

    $write = $db->ban(['user' => $user, 'scope' => 'write', 'reason' => 'Bitte ruhig', 'internal_note' => 'GEHEIM-123', 'starts_at' => rt(1000), 'ends_at' => rt(5000)]);
    $db->log = [];
    $ban = $repo->activeFor($user, $now);
    T::same(['id' => $write, 'scope' => 'write', 'reason' => 'Bitte ruhig', 'ends_at' => rt(5000)], $ban, 'wirksame befristete write-Sperre; starts_at = jetzt zählt');
    T::same(['id', 'scope', 'reason', 'ends_at'], array_keys($ban ?? []), 'Ergebnis nur mit den für das Mitglied sichtbaren Feldern');
    T::check(!str_contains(json_encode($ban) ?: '', 'GEHEIM-123'), 'die interne Notiz steht nie im Ergebnis');
    T::check($db->log !== [] && count(array_filter($db->log, static fn (string $sql): bool => stripos($sql, 'internal_note') !== false)) === 0, 'die Abfrage selektiert die interne Notiz nicht');
    T::same(null, $repo->activeFor($user, rt(5000)), 'Ende der Frist: nicht mehr wirksam');
    T::same($write, $repo->activeFor($user, rt(4999))['id'] ?? 0, 'eine Sekunde vor dem Ende: wirksam');
    T::same(null, $repo->activeFor($db->user(), $now), 'Sperre eines anderen Mitglieds zählt nicht');

    $forever = $db->ban(['user' => $user, 'scope' => 'full', 'reason' => 'Dauerhaft']);
    T::same(['id' => $forever, 'scope' => 'full', 'reason' => 'Dauerhaft', 'ends_at' => null], $repo->activeFor($user, $now), 'bei mehreren gilt die strengste: full vor write; unbefristet (ends_at NULL)');
    $newerWrite = $db->ban(['user' => $user, 'scope' => 'write', 'reason' => 'Neuer', 'starts_at' => rt(1000)]);
    T::same($forever, $repo->activeFor($user, $now)['id'] ?? 0, 'ein neueres write ändert nichts an full');
    T::check($newerWrite > $forever, 'Fixture');

    $user2 = $db->user();
    $db->ban(['user' => $user2, 'scope' => 'write', 'reason' => 'Alt', 'starts_at' => rt(0)]);
    $newer = $db->ban(['user' => $user2, 'scope' => 'write', 'reason' => 'Neu', 'starts_at' => rt(500)]);
    T::same($newer, $repo->activeFor($user2, $now)['id'] ?? 0, 'gleicher Umfang: die jüngste Sperre');

    $user3 = $db->user();
    $db->ban(['user' => $user3, 'scope' => 'write', 'reason' => 'Nur schreiben']);
    $weird = $db->ban(['user' => $user3, 'scope' => 'seltsam', 'reason' => 'Unbekannt', 'starts_at' => rt(0)]);
    T::same(['id' => $weird, 'scope' => 'full'], array_intersect_key($repo->activeFor($user3, $now) ?? [], ['id' => 1, 'scope' => 1]), 'unbekannter Umfang wird als full geliefert und hat Vorrang vor write (fail closed)');
    $user4 = $db->user();
    $only = $db->ban(['user' => $user4, 'scope' => 'seltsam']);
    T::same(['id' => $only, 'scope' => 'full'], array_intersect_key($repo->activeFor($user4, $now) ?? [], ['id' => 1, 'scope' => 1]), 'unbekannter Umfang allein: full');
}

function rScenarioUserStats(Database $db): void
{
    $repo = new UserStatsRepository($db);
    $user = $db->user();
    T::same(null, $repo->get($user), 'get: Zeile fehlt → null');
    $repo->ensure($user, rt(1));
    $row = $repo->get($user);
    T::same([0, 0, 0, 0, null, rt(1)], [(int) $row['topic_count'], (int) $row['post_count'], (int) $row['trust_level'], (int) $row['trust_level_locked'], $row['last_post_at'], $row['updated_at']], 'ensure legt die Zeile mit Nullwerten an');
    T::check(!array_key_exists('signature', $row) && !array_key_exists('settings_json', $row), 'get liefert Signatur und Einstellungen nicht');
    $repo->ensure($user, rt(99));
    T::same([1, rt(1)], [$db->count('community_user_stats', ['user_id' => $user]), $repo->get($user)['updated_at']], 'ensure ist idempotent und ändert nichts');

    $repo->bump($user, 1, 2, rt(10));
    $row = $repo->get($user);
    T::same([1, 2, rt(10)], [(int) $row['topic_count'], (int) $row['post_count'], $row['updated_at']], 'bump erhöht die Zähler und setzt updated_at');
    $db->execute('UPDATE ' . $db->getPrefix() . 'community_user_stats SET topic_count = 10, post_count = 20 WHERE user_id = ?', [$user]);
    $repo->bump($user, 1, 1, rt(11));
    $row = $repo->get($user);
    T::same([11, 21], [(int) $row['topic_count'], (int) $row['post_count']], 'bump rechnet auf dem Datenbankwert (atomar)');
    $repo->bump($user, -50, -3, rt(12));
    $row = $repo->get($user);
    T::same([0, 18], [(int) $row['topic_count'], (int) $row['post_count']], 'bump mit negativen Deltas: nie unter 0');

    $fresh = $db->user();
    $repo->bump($fresh, 1, 1, rt(20));
    T::same([1, 1], [(int) $repo->get($fresh)['topic_count'], (int) $repo->get($fresh)['post_count']], 'bump legt die Zeile bei Bedarf an');
    $touch = $db->user();
    $repo->touchLastPost($touch, rt(30));
    $row = $repo->get($touch);
    T::same([rt(30), rt(30), 0], [$row['last_post_at'], $row['updated_at'], (int) $row['post_count']], 'touchLastPost legt an und setzt last_post_at, Zähler unverändert');
    $repo->touchLastPost($touch, rt(40));
    T::same(rt(40), $repo->get($touch)['last_post_at'], 'touchLastPost setzt den neuen Zeitpunkt');

    // lockForUpdate: legt an, wenn die Zeile fehlt; ändert eine vorhandene nicht
    $lock = $db->user();
    $db->log = [];
    $row = $repo->lockForUpdate($lock, rt(50));
    T::same([0, rt(50)], [(int) $row['post_count'], $row['updated_at']], 'lockForUpdate legt eine fehlende Zeile an und liefert sie');
    T::check(count(array_filter($db->log, static fn (string $sql): bool => str_contains($sql, 'INSERT IGNORE'))) === 1, 'fehlende Zeile: genau ein INSERT IGNORE');
    $db->log = [];
    $row = $repo->lockForUpdate($lock, rt(60));
    T::same(rt(50), $row['updated_at'], 'lockForUpdate ändert eine vorhandene Zeile nicht');
    T::check(count(array_filter($db->log, static fn (string $sql): bool => str_contains($sql, 'INSERT IGNORE'))) === 0, 'vorhandene Zeile: kein Schreibzugriff (keine Sperrkonflikte durch INSERT)');
    T::check(count(array_filter($db->log, static fn (string $sql): bool => str_ends_with($sql, ' FOR UPDATE'))) === 1, 'lockForUpdate sperrt mit FOR UPDATE');
}

function rScenarioRollback(Database $db): void
{
    $forums = new ForumRepository($db);
    $topics = new TopicRepository($db);
    $stats = new UserStatsRepository($db);
    $forum = $db->forum(['title' => 'Rücknahme']);
    $user = $db->user();

    $write = function () use ($forums, $topics, $stats, $forum, $user): void {
        Transaction::run($forums->database(), function () use ($forums, $topics, $stats, $forum, $user): void {
            $stats->lockForUpdate($user, rt());
            $topic = $topics->insert(['forum_id' => $forum, 'user_id' => $user, 'title' => 'T', 'slug' => 't', 'last_post_at' => rt(), 'created_at' => rt(), 'updated_at' => rt()]);
            $forums->bumpCounters($forum, 1, 1, rt(), 1, $topic, $user, rt());
            $stats->bump($user, 1, 1, rt());
        });
    };

    $db->failOn = ['/UPDATE \S*community_forums SET/'];
    try {
        $write();
        T::check(false, 'Fehlerinjektion wirft');
    } catch (\PDOException) {
        T::check(true, 'Fehler im letzten Schritt wirft die PDOException');
    }
    T::same([0, 0], [$db->count('community_topics'), $db->count('community_user_stats')], 'Rücknahme: weder Thema noch Statistik-Zeile bleiben stehen');
    T::same(false, $db->getPdo()->inTransaction(), 'nach dem Fehler ist keine Transaktion offen');
    T::same([0, 1], [(int) $forums->find($forum)['topic_count'], (int) $forums->find($forum)['version']], 'Forumzähler und version unverändert');

    $db->failOn = [];
    $write();
    T::same([1, 1, 1], [$db->count('community_topics'), (int) $forums->find($forum)['topic_count'], (int) $stats->get($user)['topic_count']], 'danach geht derselbe Ablauf');

    // create() verschachtelt sich über SAVEPOINT in eine Dienst-Transaktion und wird mit ihr zurückgerollt
    $before = $db->count('community_forums');
    try {
        Transaction::run($forums->database(), function () use ($forums): void {
            $created = $forums->create(['title' => 'Nur in der Transaktion', 'slug' => 'nur-in-der-transaktion'], 3, rt());
            T::check($created['success'], 'create innerhalb einer Transaktion gelingt');
            T::same(['success' => false, 'error' => 'slug_taken'], $forums->create(['title' => 'Doppelt', 'slug' => 'nur-in-der-transaktion'], 3, rt()), 'Fachfehler innerhalb der Transaktion bricht sie nicht ab');
            throw new \RuntimeException('Rücknahme');
        });
    } catch (\RuntimeException) {
    }
    T::same($before, $db->count('community_forums'), 'create wird mit der umgebenden Transaktion zurückgerollt');
    T::same(false, $db->getPdo()->inTransaction(), 'danach ist keine Transaktion offen');
    $forums->forgetTree();
    T::same(null, $forums->findBySlug('nur-in-der-transaktion'), 'das zurückgerollte Forum ist weg');
}

/**
 * Anweisungen des Protokolls ab Index `$from`: Text, Art (`lock` = Zeilensperre, `read` = nicht sperrendes SELECT, `write`),
 * Tabelle ohne Präfix und ob die Anweisung innerhalb einer Transaktion lief (`txAt()` beider Doubles).
 *
 * @return list<array{sql: string, kind: string, table: string, tx: bool}>
 */
function rTrace(Database $db, int $from = 0): array
{
    $trace = [];
    for ($i = $from, $n = count($db->log); $i < $n; $i++) {
        $sql = trim($db->log[$i]);
        $kind = str_ends_with($sql, ' FOR UPDATE') ? 'lock' : (str_starts_with($sql, 'SELECT') ? 'read' : 'write');
        preg_match('/\b(?:FROM|INTO|UPDATE)\s+' . preg_quote($db->getPrefix(), '/') . '(\w+)/i', $sql, $m);
        $trace[] = ['sql' => $sql, 'kind' => $kind, 'table' => $m[1] ?? '?', 'tx' => $db->txAt($i)];
    }

    return $trace;
}

/**
 * B4: Slug-Eindeutigkeit über beide Spalten unter einer Mutex-Zeile in `settings` (`community_lock_forum_slugs`, ohne Punkt,
 * damit sie nicht zur Gruppe `community` gehört). Die Zeile entsteht vor der Transaktion im Autocommit; in der Transaktion ist
 * die Sperre dieser Zeile die erste Anweisung, danach laufen die Prüfung und das Schreiben.
 */
function rScenarioForumSlugMutex(Database $db): void
{
    $repo = new ForumRepository($db);
    $name = 'community_lock_forum_slugs';
    $mutex = static fn (): array => $db->rows('settings', ['option_name' => $name]);
    $settingsStatements = static fn (array $trace): array => array_values(array_filter($trace, static fn (array $s): bool => $s['table'] === 'settings'));

    T::same(0, count($mutex()), 'Voraussetzung: noch keine Mutex-Zeile');
    $from = count($db->log);
    $first = $repo->create(['title' => 'Erstes', 'slug' => 'erstes', 'slug_en' => 'first'], 3, rt());
    $trace = rTrace($db, $from);
    T::check($first['success'], 'create gelingt');
    $row = $mutex();
    T::same([1, '0', 0], [count($row), (string) ($row[0]['option_value'] ?? '?'), (int) ($row[0]['autoload'] ?? -1)], 'Mutex-Zeile: genau eine, Wert 0, autoload 0');
    T::check(!str_contains($name, '.') && !str_starts_with($name, 'community.'), 'der Name hat keinen Punkt: die Zeile gehört nicht zur Einstellungsgruppe community');
    T::same(1, count($db->rows('settings')), 'in settings steht nur die Mutex-Zeile (create schreibt keine Einstellung)');
    T::same(0, (int) $db->get_var('SELECT COUNT(*) FROM ' . $db->getPrefix() . 'settings WHERE option_name LIKE ?', ['community.%']), 'die Einstellungsgruppe community sieht die Zeile nicht (LIKE community.%)');

    $outside = array_values(array_filter($trace, static fn (array $s): bool => !$s['tx']));
    $inside = array_values(array_filter($trace, static fn (array $s): bool => $s['tx']));
    T::check(
        $outside !== [] && str_starts_with($outside[0]['sql'], 'INSERT IGNORE INTO ' . $db->getPrefix() . 'settings') && $outside[0]['kind'] === 'write',
        'vor der Transaktion: INSERT IGNORE der Mutex-Zeile im Autocommit (erste Anweisung von create)',
        substr((string) ($outside[0]['sql'] ?? ''), 0, 100)
    );
    T::same(1, count(array_filter($outside, static fn (array $s): bool => $s['table'] === 'settings')), 'nur ein Zugriff auf settings außerhalb der Transaktion');
    T::check(
        $inside !== [] && $inside[0]['kind'] === 'lock' && $inside[0]['table'] === 'settings' && str_contains($inside[0]['sql'], 'option_name = ?'),
        'in der Transaktion ist die Sperre der Mutex-Zeile die ERSTE Anweisung',
        substr((string) ($inside[0]['sql'] ?? '(keine)'), 0, 120)
    );
    T::same(['settings'], array_values(array_unique(array_column(array_filter($inside, static fn (array $s): bool => $s['kind'] === 'lock'), 'table'))), 'in der Transaktion wird nur die Mutex-Zeile gesperrt');
    $kinds = array_map(static fn (array $s): string => $s['kind'] . ':' . $s['table'], $inside);
    $slugCheck = array_search('read:community_forums', $kinds, true);
    $insert = array_search('write:community_forums', $kinds, true);
    T::check($slugCheck !== false && $insert !== false && $slugCheck > 0 && $slugCheck < $insert, 'danach die Prüfung auf vergebene Slugs, dann das Einfügen', implode(' | ', $kinds));

    // Zweiter Aufruf: die Zeile bleibt eine Zeile; abgelehnte Eingaben fassen settings nicht an
    $from = count($db->log);
    T::same(['success' => false, 'error' => 'slug_taken'], $repo->create(['title' => 'Doppelt', 'slug' => 'first'], 3, rt(1)), 'slug gleich slug_en eines anderen Forums → slug_taken');
    T::same(['success' => false, 'error' => 'slug_taken'], $repo->create(['title' => 'Doppelt', 'slug' => 'zweites', 'slug_en' => 'erstes'], 3, rt(1)), 'slug_en gleich slug eines anderen Forums → slug_taken');
    $trace = rTrace($db, $from);
    T::same(1, count($mutex()), 'weiterhin genau eine Mutex-Zeile');
    $inside = array_values(array_filter($trace, static fn (array $s): bool => $s['tx']));
    T::check($inside !== [] && $inside[0]['kind'] === 'lock' && $inside[0]['table'] === 'settings', 'auch bei slug_taken: erste Anweisung der Transaktion ist die Sperre der Mutex-Zeile');
    T::same(false, $db->getPdo()->inTransaction(), 'keine Transaktion bleibt offen');
    $from = count($db->log);
    T::same(['success' => false, 'error' => 'invalid_input'], $repo->create(['title' => '', 'slug' => 'leer'], 3, rt(2)), 'ungültige Eingabe → invalid_input');
    T::same(['success' => false, 'error' => 'forum_depth'], $repo->create(['title' => 'Tief', 'slug' => 'tief', 'parent_id' => (int) $first['id']], 1, rt(2)), 'zu tief → forum_depth');
    T::same([], $settingsStatements(rTrace($db, $from)), 'abgelehnte Eingaben (vor der Transaktion) fassen settings nicht an');

    // Unterforum: das Elternforum wird vor der Transaktion gelesen (kein nicht sperrender Zugriff in der Transaktion vor der Sperre)
    $from = count($db->log);
    $child = $repo->create(['title' => 'Kind', 'slug' => 'kind', 'parent_id' => (int) $first['id']], 3, rt(3));
    T::check($child['success'], 'Unterforum angelegt');
    $inside = array_values(array_filter(rTrace($db, $from), static fn (array $s): bool => $s['tx']));
    T::check($inside !== [] && $inside[0]['kind'] === 'lock' && $inside[0]['table'] === 'settings', 'Unterforum: auch hier ist die Mutex-Sperre die erste Anweisung der Transaktion');

    // update: nur wenn slug oder slug_en geändert werden
    $second = $repo->create(['title' => 'Zweites', 'slug' => 'zweites'], 3, rt(4));
    $id = (int) $second['id'];
    $before = count($mutex());
    $from = count($db->log);
    T::same(['success' => true], $repo->update($id, ['title' => 'Zweites, umbenannt', 'sort_order' => 3], rt(5)), 'update ohne Slug-Änderung');
    T::same([], $settingsStatements(rTrace($db, $from)), 'update ohne Slug-Änderung: keine Mutex-Sperre (kein Zugriff auf settings)');
    $from = count($db->log);
    T::same(['success' => true], $repo->update($id, ['slug' => 'zweites'], rt(6)), 'update mit demselben Slug');
    T::same([], $settingsStatements(rTrace($db, $from)), 'update mit unverändertem Slug: keine Mutex-Sperre');
    foreach ([['slug' => 'zweites-neu'], ['slug_en' => 'second']] as $change) {
        $from = count($db->log);
        T::same(['success' => true], $repo->update($id, $change, rt(7)), 'update ändert ' . key($change));
        $inside = array_values(array_filter(rTrace($db, $from), static fn (array $s): bool => $s['tx']));
        $kinds = array_map(static fn (array $s): string => $s['kind'] . ':' . $s['table'], $inside);
        T::check(($kinds[0] ?? '') === 'lock:settings' && in_array('read:community_forums', $kinds, true) && in_array('write:community_forums', $kinds, true), 'update ' . key($change) . ': Transaktion mit Mutex-Sperre zuerst, dann Prüfung und Schreiben', implode(' | ', $kinds));
    }
    T::same(['success' => false, 'error' => 'slug_taken'], $repo->update($id, ['slug_en' => 'erstes'], rt(8)), 'update: slug_en gleich slug eines anderen Forums → slug_taken');
    T::same(['success' => false, 'error' => 'slug_taken'], $repo->update($id, ['slug' => 'first'], rt(8)), 'update: slug gleich slug_en eines anderen Forums → slug_taken');
    T::same('second', $repo->find($id)['slug_en'] ?? null, 'abgelehnte Änderung lässt die Zeile unverändert');
    T::same([$before, false], [count($mutex()), $db->getPdo()->inTransaction()], 'eine Mutex-Zeile, keine offene Transaktion');
}

/**
 * B5: `lastPublished()` fragt je Wert von `is_pinned` (0 und 1) mit Gleichheit auf `forum_id`, `status` und `is_pinned`
 * (Index `idx_community_topics_forum_list`, `ORDER BY last_post_at DESC, id DESC LIMIT 1`) und wählt in PHP das spätere
 * Paar (`last_post_at`, `id`). Den Plan prüft `TESTS/community-db` (EXPLAIN, Handler-Zähler).
 */
function rScenarioLastPublished(Database $db): void
{
    $repo = new TopicRepository($db);
    $user = $db->user();
    $poster = $db->user();
    $forum = $db->forum(['title' => 'Letzte Themen']);
    $other = $db->forum(['title' => 'Anderes Forum']);
    $expect = static fn (int $id, int $at, int $postId, int $lastPoster): array => ['id' => $id, 'user_id' => $user, 'last_post_id' => $postId, 'last_post_at' => rt($at), 'last_poster_id' => $lastPoster];
    $topic = static fn (array $o): int => $db->topic($o + ['forum' => $forum, 'user' => $user, 'last_poster_id' => $user]);

    T::same(null, $repo->lastPublished($forum), 'leeres Forum → null');
    $from = count($db->log);
    $repo->lastPublished($forum);
    $selects = array_values(array_filter(rTrace($db, $from), static fn (array $s): bool => $s['kind'] === 'read'));
    T::same(2, count($selects), 'zwei Abfragen (je Wert von is_pinned)');
    foreach ($selects as $i => $statement) {
        T::check(
            preg_match("/WHERE forum_id = \\? AND status = 'published' AND is_pinned = \\? ORDER BY last_post_at DESC, id DESC LIMIT 1$/", $statement['sql']) === 1,
            'Abfrage ' . ($i + 1) . ': Gleichheit auf forum_id, status und is_pinned, Sortierung nach Aktivität und ID, LIMIT 1',
            substr($statement['sql'], -140)
        );
    }

    // nur nicht angeheftete Themen
    $t1 = $topic(['last_post_at' => rt(100), 'last_post_id' => 5]);
    $t2 = $topic(['last_post_at' => rt(200), 'last_post_id' => 9, 'last_poster_id' => $poster]);
    T::same($expect($t2, 200, 9, $poster), $repo->lastPublished($forum), 'nur nicht angeheftete Themen: das mit der jüngsten Aktivität');
    // ein älteres angeheftetes Thema ändert nichts, ein jüngeres gewinnt
    $p1 = $topic(['last_post_at' => rt(150), 'last_post_id' => 7, 'pinned' => true]);
    T::same($expect($t2, 200, 9, $poster), $repo->lastPublished($forum), 'älteres angeheftetes Thema: das nicht angeheftete mit der jüngeren Aktivität gewinnt');
    $p2 = $topic(['last_post_at' => rt(250), 'last_post_id' => 11, 'pinned' => true]);
    T::same($expect($p2, 250, 11, $user), $repo->lastPublished($forum), 'jüngeres angeheftetes Thema gewinnt');
    // abgelaufene Anheftung (is_pinned bleibt 1 bis zum Aufräumen): das Thema zählt, gleich wie
    $p3 = $topic(['last_post_at' => rt(260), 'last_post_id' => 12, 'pinned' => true, 'pinned_until' => rt(-3600)]);
    T::same($expect($p3, 260, 12, $user), $repo->lastPublished($forum), 'angeheftet mit abgelaufener Frist: zählt als veröffentlichtes Thema');
    // Gleichstand der Zeit: die größere ID gewinnt, über die beiden Teilabfragen hinweg
    $u4 = $topic(['last_post_at' => rt(300), 'last_post_id' => 13]);
    $p4 = $topic(['last_post_at' => rt(300), 'last_post_id' => 14, 'pinned' => true]);
    T::same($expect($p4, 300, 14, $user), $repo->lastPublished($forum), 'gleiche Zeit: das angeheftete Thema mit der größeren ID gewinnt');
    $u5 = $topic(['last_post_at' => rt(300), 'last_post_id' => 15]);
    T::same($expect($u5, 300, 15, $user), $repo->lastPublished($forum), 'gleiche Zeit: das nicht angeheftete Thema mit der größeren ID gewinnt');
    T::check($u4 < $p4 && $p4 < $u5, 'Voraussetzung: IDs in der Reihenfolge u4 < p4 < u5');
    // Status: nur veröffentlicht; andere Foren zählen nicht
    foreach (['hidden', 'pending', 'deleted', 'trash', 'moved'] as $i => $status) {
        $topic(['last_post_at' => rt(400 + $i), 'last_post_id' => 20 + $i, 'status' => $status]);
        $topic(['last_post_at' => rt(500 + $i), 'last_post_id' => 30 + $i, 'status' => $status, 'pinned' => true]);
    }
    $db->topic(['forum' => $other, 'user' => $user, 'last_post_at' => rt(900), 'last_post_id' => 99]);
    T::same($expect($u5, 300, 15, $user), $repo->lastPublished($forum), 'ausgeblendete, wartende, gelöschte und verschobene Themen (auch angeheftete) und andere Foren zählen nicht');
    // nur angeheftete Themen
    $solo = $db->forum(['title' => 'Nur angeheftet']);
    $s1 = $db->topic(['forum' => $solo, 'user' => $user, 'last_post_at' => rt(10), 'last_post_id' => 1, 'last_poster_id' => $user, 'pinned' => true]);
    T::same($expect($s1, 10, 1, $user), $repo->lastPublished($solo), 'nur angeheftete Themen: das jüngste davon');
    T::check($t1 > 0 && $p1 > 0, 'Fixtures');
}

// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
// Nachprüfung (B22, B24, B28, B33/B34/B40, B37, B38, B43): Savepoints, Wiederholung, Spaltengrenzen, reservierte Slugs, UNIQUE
// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

/** PDOException wie ein Treiberfehler (SQLSTATE, Treibercode, Text) für die Fehlerinjektion über `throwOn` und für Transaction::run. */
final class RepoDriverException extends \PDOException
{
    public function __construct(string $sqlState, int $driverCode, string $message)
    {
        parent::__construct("SQLSTATE[{$sqlState}]: {$message}");
        $this->code = $sqlState;
        $this->errorInfo = [$sqlState, $driverCode, $message];
    }
}

/**
 * B33/B40: Verschachtelung über SAVEPOINT mit je Tiefe eigenem Namen. Vorher hieß jede Ebene `cms_community_tx`: ab drei Ebenen
 * löschte das RELEASE der inneren den Savepoint der äußeren, und das der mittleren scheiterte mit Fehler 1305.
 */
function rScenarioTransactionNesting(Database $db): void
{
    $titles = static fn (): array => array_map(static fn (array $row): string => (string) $row['title'], $db->rows('community_forums', [], 'id'));
    $pdo = $db->getPdo();

    // Drei und vier Ebenen, alles gelingt
    Transaction::run($db, function () use ($db): void {
        $db->forum(['title' => 'E1']);
        Transaction::run($db, function () use ($db): void {
            $db->forum(['title' => 'E2']);
            Transaction::run($db, function () use ($db): void {
                $db->forum(['title' => 'E3']);
                Transaction::run($db, static function () use ($db): void {
                    $db->forum(['title' => 'E4']);
                });
            });
        });
    });
    T::same(['E1', 'E2', 'E3', 'E4'], $titles(), 'vier Ebenen: alles gespeichert (vorher scheiterte schon die dritte mit „SAVEPOINT does not exist“)');
    T::same(false, $pdo->inTransaction(), 'danach ist keine Transaktion offen');
    $db->execute('DELETE FROM ' . $db->getPrefix() . 'community_forums');

    // Die mittlere Ebene scheitert, die äußere fängt: nur die innere Schreibung der mittleren und der dritten Ebene entfällt
    Transaction::run($db, function () use ($db): void {
        $db->forum(['title' => 'E1']);
        try {
            Transaction::run($db, function () use ($db): void {
                $db->forum(['title' => 'E2']);
                Transaction::run($db, function () use ($db): void {
                    $db->forum(['title' => 'E3']);
                });
                throw new \RuntimeException('Mitte');
            });
        } catch (\RuntimeException) {
        }
        $db->forum(['title' => 'E1b']);
    });
    T::same(['E1', 'E1b'], $titles(), 'mittlere Ebene scheitert: ihre Schreibung und die der dritten Ebene sind zurückgerollt, die äußere bleibt und macht weiter');
    $db->execute('DELETE FROM ' . $db->getPrefix() . 'community_forums');

    // Die innerste Ebene scheitert, die mittlere fängt und schreibt weiter
    Transaction::run($db, function () use ($db): void {
        $db->forum(['title' => 'E1']);
        Transaction::run($db, function () use ($db): void {
            $db->forum(['title' => 'E2']);
            try {
                Transaction::run($db, function () use ($db): void {
                    $db->forum(['title' => 'E3']);
                    throw new \RuntimeException('Innen');
                });
            } catch (\RuntimeException) {
            }
            $db->forum(['title' => 'E2b']);
        });
    });
    T::same(['E1', 'E2', 'E2b'], $titles(), 'innerste Ebene scheitert: nur ihre Schreibung entfällt, die mittlere schreibt weiter');
    $db->execute('DELETE FROM ' . $db->getPrefix() . 'community_forums');

    // Geschwister auf derselben Ebene (die Tiefe fällt nach jedem Aufruf zurück), eines davon scheitert
    Transaction::run($db, function () use ($db): void {
        Transaction::run($db, function () use ($db): void {
            Transaction::run($db, function () use ($db): void {
                $db->forum(['title' => 'A']);
            });
            try {
                Transaction::run($db, function () use ($db): void {
                    $db->forum(['title' => 'B']);
                    throw new \RuntimeException('Geschwister');
                });
            } catch (\RuntimeException) {
            }
            Transaction::run($db, function () use ($db): void {
                $db->forum(['title' => 'C']);
            });
        });
    });
    T::same(['A', 'C'], $titles(), 'Geschwister auf derselben Ebene: das gescheiterte entfällt, die übrigen bleiben');
    $db->execute('DELETE FROM ' . $db->getPrefix() . 'community_forums');

    // Ein Fehler reißt alle drei Ebenen mit
    try {
        Transaction::run($db, function () use ($db): void {
            $db->forum(['title' => 'E1']);
            Transaction::run($db, function () use ($db): void {
                $db->forum(['title' => 'E2']);
                Transaction::run($db, function () use ($db): void {
                    $db->forum(['title' => 'E3']);
                    throw new \RuntimeException('Ganz innen');
                });
            });
        });
        T::check(false, 'der Fehler der innersten Ebene erreicht den Aufrufer');
    } catch (\RuntimeException $e) {
        T::same('Ganz innen', $e->getMessage(), 'der Fehler der innersten Ebene erreicht den Aufrufer unverändert (kein 1305)');
    }
    T::same([[], false], [$titles(), $pdo->inTransaction()], 'Fehler in der dritten Ebene: alles zurückgerollt, keine Transaktion offen');

    // Danach beginnt die Zählung wieder bei der äußersten Ebene; eine von außen geöffnete Transaktion zählt als Ebene 0
    $pdo->beginTransaction();
    Transaction::run($db, function () use ($db): void {
        $db->forum(['title' => 'X1']);
        Transaction::run($db, function () use ($db): void {
            $db->forum(['title' => 'X2']);
            Transaction::run($db, function () use ($db): void {
                $db->forum(['title' => 'X3']);
            });
        });
    });
    $pdo->rollBack();
    T::same([], $titles(), 'in einer von außen geöffneten Transaktion: drei Ebenen laufen, die äußere Rücknahme nimmt alles zurück');
    T::same('ok', Transaction::run($db, static fn (): string => 'ok'), 'die äußerste Ebene arbeitet danach wieder normal');
}

/** B34: Wiederholung nur bei Deadlock (1213, SQLSTATE 40001), nicht bei Lock-Timeout (1205). */
function rScenarioTransactionRetry(Database $db): void
{
    $titles = static fn (): array => array_map(static fn (array $row): string => (string) $row['title'], $db->rows('community_forums', [], 'id'));
    $pdo = $db->getPdo();
    $run = static function (\Closure $fail, int $failures) use ($db): array {
        $attempts = 0;
        try {
            $result = Transaction::run($db, static function () use ($db, $fail, $failures, &$attempts): string {
                $attempts++;
                $db->forum(['title' => 'Versuch ' . $attempts]);
                if ($attempts <= $failures) {
                    throw $fail();
                }

                return 'fertig';
            });
        } catch (\Throwable $e) {
            return [$attempts, $e::class . ': ' . $e->getMessage()];
        }

        return [$attempts, $result];
    };

    $deadlock = static fn (): \PDOException => new RepoDriverException('40001', 1213, 'Deadlock found when trying to get lock; try restarting transaction');
    $serialization = static fn (): \PDOException => new RepoDriverException('40001', 0, 'Serialization failure');
    $timeout = static fn (): \PDOException => new RepoDriverException('HY000', 1205, 'Lock wait timeout exceeded; try restarting transaction');

    T::same([3, 'fertig'], $run($deadlock, 2), 'Deadlock (1213): zweimal wiederholt, der dritte Versuch gelingt');
    T::same(['Versuch 3'], $titles(), 'Deadlock: nur der Versuch, der gelingt, ist gespeichert (die früheren sind zurückgerollt)');
    $db->execute('DELETE FROM ' . $db->getPrefix() . 'community_forums');
    T::same([3, 'fertig'], $run($serialization, 2), 'SQLSTATE 40001 ohne Treibercode: wiederholt');
    $db->execute('DELETE FROM ' . $db->getPrefix() . 'community_forums');
    $exhausted = $run($deadlock, 99);
    T::same(Transaction::MAX_ATTEMPTS, $exhausted[0], 'Deadlock bleibt bestehen: genau MAX_ATTEMPTS Versuche');
    T::check(str_starts_with($exhausted[1], RepoDriverException::class . ': SQLSTATE[40001]'), 'danach geht der Fehler an den Aufrufer', $exhausted[1]);
    T::same([[], false], [$titles(), $pdo->inTransaction()], 'nach dem Aufgeben: nichts gespeichert, keine Transaktion offen');

    $started = microtime(true);
    $timedOut = $run($timeout, 99);
    T::same(1, $timedOut[0], 'Lock-Timeout (1205): nicht wiederholt, genau ein Versuch (vorher drei: die Wartezeit des Servers verdreifachte sich)');
    T::check(str_starts_with($timedOut[1], RepoDriverException::class . ': SQLSTATE[HY000]'), 'der Lock-Timeout geht sofort an den Aufrufer', $timedOut[1]);
    T::check(microtime(true) - $started < 0.05, 'ohne Pause zwischen Versuchen (kein usleep)', (string) round(microtime(true) - $started, 3));
    T::same([[], false], [$titles(), $pdo->inTransaction()], 'Lock-Timeout: nichts gespeichert, keine Transaktion offen');

    // Verschachtelt wird nie wiederholt: der Deadlock geht an die äußerste Ebene, die dann selbst wiederholt
    $attempts = 0;
    $inner = 0;
    $result = Transaction::run($db, static function () use ($db, $deadlock, &$attempts, &$inner): string {
        $attempts++;
        Transaction::run($db, static function () use ($db, $deadlock, &$attempts, &$inner): void {
            $inner++;
            $db->forum(['title' => 'innen ' . $attempts]);
            if ($attempts < 2) {
                throw $deadlock();
            }
        });

        return 'fertig';
    });
    T::same(['fertig', 2, 2], [$result, $attempts, $inner], 'verschachtelt: die innere Ebene wiederholt nicht, die äußerste tut es');

    T::same(
        [true, true, false, false, false, false, false],
        [
            Transaction::isRetryable($deadlock()), Transaction::isRetryable($serialization()), Transaction::isRetryable($timeout()),
            Transaction::isRetryable(new RepoDriverException('23000', 1062, 'Duplicate entry')), Transaction::isRetryable(new \PDOException('ohne Treibercode')),
            Transaction::isRetryable(new \RuntimeException('kein PDO')), Transaction::isRetryable(new \LogicException('Programmfehler')),
        ],
        'isRetryable: 1213 und 40001 ja; 1205, Duplicate entry, Fehler ohne Treibercode und fremde Ausnahmen nein'
    );
}

/** B37: Längen und Bereiche der Spalten werden vor dem Schreiben geprüft (invalid_input statt PDOException oder stillem Kürzen). */
function rScenarioColumnLimits(Database $db): void
{
    $forums = new ForumRepository($db);
    $categories = new CategoryRepository($db);
    $row = static fn (int $n): array => ['title' => 'Forum ' . $n, 'slug' => 'forum-' . $n];
    $invalid = ['success' => false, 'error' => 'invalid_input'];

    $bad = [
        'title 151 Zeichen' => ['title' => str_repeat('x', 151)],
        'title_en 151 Zeichen' => ['title_en' => str_repeat('x', 151)],
        'title 151 Umlaute (Zeichen, nicht Bytes)' => ['title' => str_repeat('ä', 151)],
        'title kein Text' => ['title' => ['Liste']],
        'title ungültiges UTF-8' => ['title' => "Titel \xFF"],
        'description über 65.535 Bytes' => ['description' => str_repeat('a', 65536)],
        'description_en über 65.535 Bytes' => ['description_en' => str_repeat('ä', 32768)],
        'icon 51 Zeichen' => ['icon' => str_repeat('i', 51)],
        'color 10 Zeichen' => ['color' => '#112233445'],
        'link_url 501 Zeichen' => ['link_url' => 'https://example.org/' . str_repeat('a', 481)],
        'sort_order negativ' => ['sort_order' => -1],
        'sort_order über INT UNSIGNED' => ['sort_order' => 4294967296],
        'sort_order kein Zahlenwert' => ['sort_order' => 'abc'],
        'sort_order Dezimalzahl' => ['sort_order' => '1.5'],
        'slow_mode_seconds negativ' => ['slow_mode_seconds' => -5],
        'auto_close_days negativ' => ['auto_close_days' => -1],
        'category_id negativ' => ['category_id' => -1],
        'category_id NULL (NOT NULL-Spalte)' => ['category_id' => null],
        'require_approval NULL' => ['require_approval' => null],
        'slug_en zu lang' => ['slug_en' => str_repeat('a', 161)],
    ];
    $count = $db->count('community_forums');
    $n = 0;
    foreach ($bad as $label => $extra) {
        T::same($invalid, $forums->create($extra + $row(++$n), 3, rt()), "Forum create lehnt ab: {$label}");
    }
    T::same(['success' => false, 'error' => 'invalid_input'], $forums->create($row(++$n) + ['parent_id' => -3], 3, rt()), 'Forum create lehnt ab: parent_id negativ');
    T::same($count, $db->count('community_forums'), 'abgelehnte Forum-Eingaben schreiben nichts');

    $ok = [
        'title 150 Umlaute' => ['title' => str_repeat('ä', 150)],
        'title_en 150 Zeichen' => ['title_en' => str_repeat('x', 150)],
        'description 65.535 Bytes' => ['description' => str_repeat('a', 65535)],
        'icon 50 Zeichen' => ['icon' => str_repeat('i', 50)],
        'color 9 Zeichen' => ['color' => '#11223344'],
        'link_url 500 Zeichen' => ['link_url' => 'https://example.org/' . str_repeat('a', 480)],
        'Zahlen am oberen Rand von INT UNSIGNED' => ['sort_order' => 4294967295, 'slow_mode_seconds' => '4294967295', 'auto_close_days' => 0],
        'Texte NULL (nullbare Spalten)' => ['title_en' => null, 'description' => null, 'icon' => null, 'color' => null, 'link_url' => null],
    ];
    foreach ($ok as $label => $extra) {
        $created = $forums->create($extra + $row(++$n), 3, rt());
        T::check($created['success'] === true, "Forum create nimmt an: {$label}", json_encode($created));
    }

    // update: dieselben Grenzen, die Zeile bleibt unverändert
    $id = (int) $forums->create($row(++$n), 3, rt())['id'];
    $before = $forums->find($id);
    foreach ($bad as $label => $extra) {
        if (isset($extra['slug_en']) && strlen((string) $extra['slug_en']) > 160) {
            T::same($invalid, $forums->update($id, $extra, rt(5)), "Forum update lehnt ab: {$label}");
            continue;
        }
        T::same($invalid, $forums->update($id, $extra, rt(5)), "Forum update lehnt ab: {$label}");
    }
    T::same($before, $forums->find($id), 'abgelehnte Änderungen lassen die Zeile unverändert (auch version und updated_at)');
    T::check($forums->update($id, ['title' => str_repeat('ä', 150), 'sort_order' => 4294967295], rt(6))['success'] ?? false, 'update nimmt die Obergrenzen an');

    // Kategorien
    $badCategories = [
        'title 151 Zeichen' => ['title' => str_repeat('x', 151)],
        'title_en 151 Zeichen' => ['title_en' => str_repeat('x', 151)],
        'description über 65.535 Bytes' => ['description' => str_repeat('a', 65536)],
        'description_en über 65.535 Bytes' => ['description_en' => str_repeat('a', 65536)],
        'sort_order negativ' => ['sort_order' => -1],
        'sort_order über INT UNSIGNED' => ['sort_order' => 4294967296],
        'title ungültiges UTF-8' => ['title' => "Titel \xFF"],
        'title NULL (NOT NULL-Spalte)' => ['title' => null],
    ];
    $categoryCount = $db->count('community_categories');
    $k = 0;
    foreach ($badCategories as $label => $extra) {
        $k++;
        T::same($invalid, $categories->create($extra + ['title' => 'Kategorie ' . $k, 'slug' => 'kategorie-' . $k], rt()), "Kategorie create lehnt ab: {$label}");
    }
    T::same($categoryCount, $db->count('community_categories'), 'abgelehnte Kategorie-Eingaben schreiben nichts');
    $category = $categories->create(['title' => str_repeat('ä', 150), 'title_en' => str_repeat('x', 150), 'slug' => 'kategorie-ok', 'description' => str_repeat('a', 65535), 'sort_order' => 4294967295, 'is_visible' => 5], rt());
    T::check($category['success'] === true, 'Kategorie create nimmt die Obergrenzen an', json_encode($category));
    $categoryId = (int) $category['id'];
    T::same(1, (int) $categories->find($categoryId)['is_visible'], 'is_visible wird als Schalter 0/1 gespeichert (5 → 1)');
    $categories->update($categoryId, ['is_visible' => '0'], rt(1));
    T::same(0, (int) $categories->find($categoryId)['is_visible'], 'update: is_visible "0" → 0');
    $beforeCategory = $categories->find($categoryId);
    foreach ($badCategories as $label => $extra) {
        T::same($invalid, $categories->update($categoryId, $extra, rt(9)), "Kategorie update lehnt ab: {$label}");
    }
    T::same($beforeCategory, $categories->find($categoryId), 'abgelehnte Kategorie-Änderungen lassen die Zeile unverändert');
    $db->throwOn = [];
}

/** B28: reservierte Pfadsegmente sind als Forum-Slug gesperrt (slug_taken), auch beim Schreiben über das Repository. */
function rScenarioReservedSlugs(Database $db): void
{
    $forums = new ForumRepository($db);
    $taken = ['success' => false, 'error' => 'slug_taken'];
    $reserved = [...\CMS\Services\Community\Support\Slugger::RESERVED, 'seite-2', 'page-3', 'seite-10'];
    $count = $db->count('community_forums');
    foreach ($reserved as $slug) {
        T::same($taken, $forums->create(['title' => 'Reserviert ' . $slug, 'slug' => $slug], 3, rt()), "create: slug „{$slug}“ ist reserviert");
        T::same($taken, $forums->create(['title' => 'Reserviert en ' . $slug, 'slug' => 'frei-' . $slug, 'slug_en' => $slug], 3, rt()), "create: slug_en „{$slug}“ ist reserviert");
    }
    T::same($count, $db->count('community_forums'), 'abgelehnte Slugs schreiben nichts');
    T::same(0, $db->count('settings', ['option_name' => ForumRepository::SLUG_LOCK]), 'abgelehnte Eingaben fassen die Mutex-Zeile nicht an');

    $id = (int) $forums->create(['title' => 'Normal', 'slug' => 'normal'], 3, rt())['id'];
    $before = $forums->find($id);
    foreach (['neu', 'thema', 'seite-2', 'page-3', 'en', 'api', 'neues-thema', 'unread'] as $slug) {
        T::same($taken, $forums->update($id, ['slug' => $slug], rt(5)), "update: slug „{$slug}“ ist reserviert");
    }
    T::same($taken, $forums->update($id, ['slug_en' => 'new'], rt(5)), 'update: slug_en „new“ ist reserviert');
    T::same($taken, $forums->update($id, ['title' => 'Neu', 'slug' => 'neu', 'slug_en' => 'normal-en'], rt(5)), 'update: ein reservierter Slug unter mehreren Änderungen verwirft alles');
    T::same($before, $forums->find($id), 'abgelehnte Änderungen lassen die Zeile unverändert');

    // Ähnliche, aber nicht reservierte Slugs sind erlaubt
    foreach (['neuigkeiten', 'seite', 'seite-x', 'page-x', 'themen', 'eng', 'new-york', 'seite-2a', 'api-doku'] as $slug) {
        $created = $forums->create(['title' => 'Erlaubt ' . $slug, 'slug' => $slug], 3, rt());
        T::check($created['success'] === true, "create: slug „{$slug}“ ist nicht reserviert", json_encode($created));
    }
    T::check($forums->update($id, ['slug' => 'normal-neu', 'slug_en' => 'normal-new'], rt(6))['success'] ?? false, 'update mit freien Slugs gelingt');
    // Kategorien haben eigene Adressen unterhalb des Community-Pfads und sind von der Sperrliste nicht betroffen
    T::check((new CategoryRepository($db))->create(['title' => 'Neu', 'slug' => 'neu'], rt())['success'] ?? false, 'Kategorie-Slug „neu“ bleibt erlaubt (eigener Adressraum)');
}

/** B38: nur ein UNIQUE-Verstoß wird zu slug_taken; andere Integritätsfehler (NOT NULL, Fremdschlüssel) gehen an den Aufrufer. */
function rScenarioDuplicateKeyCodes(Database $db): void
{
    $forums = new ForumRepository($db);
    $categories = new CategoryRepository($db);
    $existing = $forums->create(['title' => 'Vorhanden', 'slug' => 'vorhanden'], 3, rt());
    $category = $categories->create(['title' => 'Kategorie', 'slug' => 'kategorie'], rt());
    $forumId = (int) $existing['id'];
    $categoryId = (int) $category['id'];

    $unique = [
        'MariaDB 1062' => new RepoDriverException('23000', 1062, "Integrity constraint violation: 1062 Duplicate entry 'x' for key 'uq'"),
        'SQLite UNIQUE' => new RepoDriverException('23000', 19, 'Integrity constraint violation: 19 UNIQUE constraint failed: cms_community_forums.slug'),
    ];
    foreach ($unique as $label => $exception) {
        $db->throwOn = ['/^INSERT INTO \S*community_forums\b/' => $exception];
        T::same(['success' => false, 'error' => 'slug_taken'], $forums->create(['title' => 'Rennen', 'slug' => 'rennen'], 3, rt()), "Forum create: UNIQUE-Verstoß ({$label}) → slug_taken");
        $db->throwOn = ['/^UPDATE \S*community_forums SET\b/' => $exception];
        T::same(['success' => false, 'error' => 'slug_taken'], $forums->update($forumId, ['slug' => 'anders'], rt(5)), "Forum update: UNIQUE-Verstoß ({$label}) → slug_taken");
        $db->throwOn = ['/^INSERT INTO \S*community_categories\b/' => $exception];
        T::same(['success' => false, 'error' => 'slug_taken'], $categories->create(['title' => 'Rennen', 'slug' => 'rennen'], rt()), "Kategorie create: UNIQUE-Verstoß ({$label}) → slug_taken");
        $db->throwOn = ['/^UPDATE \S*community_categories SET\b/' => $exception];
        T::same(['success' => false, 'error' => 'slug_taken'], $categories->update($categoryId, ['title' => 'Neu'], rt(5)), "Kategorie update: UNIQUE-Verstoß ({$label}) → slug_taken");
    }

    $other = [
        'MariaDB NOT NULL (1048)' => new RepoDriverException('23000', 1048, "Integrity constraint violation: 1048 Column 'category_id' cannot be null"),
        'MariaDB Fremdschlüssel (1452)' => new RepoDriverException('23000', 1452, 'Integrity constraint violation: 1452 Cannot add or update a child row: a foreign key constraint fails'),
        'MariaDB Fremdschlüssel (1451)' => new RepoDriverException('23000', 1451, 'Integrity constraint violation: 1451 Cannot delete or update a parent row'),
        'SQLite NOT NULL' => new RepoDriverException('23000', 19, 'Integrity constraint violation: 19 NOT NULL constraint failed: cms_community_forums.category_id'),
        'SQLSTATE 23000 ohne Treibercode' => new RepoDriverException('23000', 0, 'Integrity constraint violation'),
        'Treibercode 1062 ohne SQLSTATE 23000' => new RepoDriverException('HY000', 1062, 'Duplicate entry (anderer Zustand)'),
    ];
    foreach ($other as $label => $exception) {
        foreach ([
            'Forum create' => ['/^INSERT INTO \S*community_forums\b/', static fn () => $forums->create(['title' => 'Fehler', 'slug' => 'fehler'], 3, rt())],
            'Forum update' => ['/^UPDATE \S*community_forums SET\b/', static fn () => $forums->update($forumId, ['slug' => 'fehler'], rt(5))],
            'Kategorie create' => ['/^INSERT INTO \S*community_categories\b/', static fn () => $categories->create(['title' => 'Fehler', 'slug' => 'fehler'], rt())],
            'Kategorie update' => ['/^UPDATE \S*community_categories SET\b/', static fn () => $categories->update($categoryId, ['title' => 'Fehler'], rt(5))],
        ] as $where => [$pattern, $call]) {
            $db->throwOn = [$pattern => $exception];
            try {
                $result = $call();
                T::check(false, "{$where}: {$label} wird nicht als slug_taken gemeldet", json_encode($result));
            } catch (\PDOException $e) {
                T::same($exception, $e, "{$where}: {$label} geht als Datenbankfehler an den Aufrufer (kein slug_taken)");
            }
        }
    }
    $db->throwOn = [];
    T::same([0, false], [$db->count('community_forums', ['slug' => 'fehler']) + $db->count('community_categories', ['slug' => 'fehler']), $db->getPdo()->inTransaction()], 'die Fehler hinterlassen nichts und keine offene Transaktion');
}

/** B24: bis MAX_PINNED wirksame Pins sind alle sichtbar; die Grenze ist eine Invariante des Anheftens (PR #2b), nicht der Liste. */
function rScenarioPinLimit(Database $db): void
{
    $repo = new TopicRepository($db);
    $forum = $db->forum(['title' => 'Viele Pins']);
    $user = $db->user();
    $now = rt(10000);
    $pins = [];
    for ($i = 0; $i < TopicRepository::MAX_PINNED; $i++) {
        $pins[] = $db->topic(['forum' => $forum, 'user' => $user, 'last_post_at' => rt(100 + $i), 'pinned' => true]);
    }
    $normal = [];
    for ($i = 0; $i < 12; $i++) {
        $normal[] = $db->topic(['forum' => $forum, 'user' => $user, 'last_post_at' => rt(5000 + $i)]);
    }
    [$all] = rCollect(static fn (?Cursor $c): Page => $repo->listForum($forum, $now, $c, 5));
    T::same(TopicRepository::MAX_PINNED + 12, count($all), 'genau MAX_PINNED Pins plus Strom: alle Themen sind über die Seiten erreichbar');
    T::same(TopicRepository::MAX_PINNED, count($repo->pinned($forum, $now)), 'pinned() liefert höchstens MAX_PINNED');
    T::same(50, TopicRepository::MAX_PINNED, 'MAX_PINNED ist 50 (Obergrenze, die der Anheft-Dienst in PR #2b erzwingen muss)');

    // Bekannte Grenze (Befund B24): ein Pin über MAX_PINNED hinaus steht in keiner Seite. Deshalb darf das Anheften ihn gar nicht zulassen.
    $extra = $db->topic(['forum' => $forum, 'user' => $user, 'last_post_at' => rt(50), 'pinned' => true]);
    [$more] = rCollect(static fn (?Cursor $c): Page => $repo->listForum($forum, $now, $c, 5));
    T::same(false, in_array($extra, rIds($more), true), 'bekannte Grenze: der 51. wirksame Pin erscheint nirgends (Invariante des Anheftens, siehe TopicRepository::MAX_PINNED)');
    T::same(TopicRepository::MAX_PINNED + 12, count($more), 'die übrigen Themen bleiben vollständig erreichbar');
    // Ablauf der Frist gibt den Platz frei, ohne dass etwas verloren geht (abgelaufene Pins gehören zum Strom)
    $db->execute('UPDATE ' . $db->getPrefix() . 'community_topics SET pinned_until = ? WHERE id = ?', [rt(9000), $extra]);
    [$expired] = rCollect(static fn (?Cursor $c): Page => $repo->listForum($forum, $now, $c, 5));
    T::same(true, in_array($extra, rIds($expired), true), 'ein abgelaufener Pin gehört wieder zum Strom und ist erreichbar');
}

/** B43: Pfad der Foren (`path`) mit beschädigten Segmenten, Rechte-Subjekte mit Grenzfällen. */
function rScenarioForumKeys(Database $db): void
{
    $repo = new ForumRepository($db);
    $a = $db->forum(['title' => 'A']);
    $b = $db->forum(['title' => 'B', 'parent' => $a]);
    $prefix = $db->getPrefix();
    foreach ([
        'Segment 0' => ["/0/{$b}/", [$b]],
        'leeres Segment' => ["/{$a}//{$b}/", [$b, $a]],
        'führende Null' => ["/0{$a}/{$b}/", [$b]],
        'Buchstaben im Segment' => ["/{$a}/x{$b}/", [$b]],
        'nur das Forum' => ["/{$b}/", [$b]],
        'Pfad endet nicht mit dem Forum' => ["/{$b}/{$a}/", [$b]],
    ] as $label => [$path, $expected]) {
        $db->execute("UPDATE {$prefix}community_forums SET path = ? WHERE id = ?", [$path, $b]);
        $repo->forgetTree();
        T::same($expected, $repo->ancestorIds($b), "ancestorIds bei beschädigtem Pfad ({$label}: {$path})");
        T::same(true, $repo->chainIds($b) === $expected || $repo->chainIds($b) === array_slice($expected, 0, 1), "chainIds bei beschädigtem Pfad ({$label})");
    }
    $db->execute("UPDATE {$prefix}community_forums SET path = ? WHERE id = ?", ["/{$a}/{$b}/", $b]);
    $repo->forgetTree();
    T::same([$b, $a], $repo->ancestorIds($b), 'intakter Pfad: Forum, dann Vorfahren');

    // Rechte-Subjekte: Grenzen der Schlüssel
    $bad = [
        'Gruppe 0' => [['subject_type' => 'group', 'subject_key' => '0']],
        'Gruppe mit führender Null' => [['subject_type' => 'group', 'subject_key' => '012']],
        'Gruppe mit elf Stellen' => [['subject_type' => 'group', 'subject_key' => str_repeat('1', 11)]],
        'Gruppe mit Vorzeichen' => [['subject_type' => 'group', 'subject_key' => '-3']],
        'Rolle mit 51 Zeichen' => [['subject_type' => 'role', 'subject_key' => str_repeat('a', 51)]],
        'Rolle mit Großbuchstaben' => [['subject_type' => 'role', 'subject_key' => 'Member']],
        'Rolle mit Leerzeichen' => [['subject_type' => 'role', 'subject_key' => 'a b']],
        'trust mit zwei Stellen' => [['subject_type' => 'trust', 'subject_key' => '10']],
        'trust mit Vorzeichen' => [['subject_type' => 'trust', 'subject_key' => '-1']],
    ];
    $count = $db->count('community_forum_permissions');
    foreach ($bad as $label => $rows) {
        try {
            $repo->savePermissions($a, [['subject_type' => 'guest', 'permissions' => ['read' => 1]], ...$rows], rt());
            T::check(false, "savePermissions lehnt ab: {$label}");
        } catch (\InvalidArgumentException) {
            T::check(true, "savePermissions lehnt ab: {$label}");
        }
    }
    T::same($count, $db->count('community_forum_permissions'), 'abgelehnte Subjekte schreiben nichts');
    $repo->savePermissions($a, [
        ['subject_type' => 'role', 'subject_key' => str_repeat('a', 50), 'permissions' => ['read' => 1]],
        ['subject_type' => 'role', 'subject_key' => 'a-b_c9', 'permissions' => ['read' => 1]],
        ['subject_type' => 'group', 'subject_key' => '1234567890', 'permissions' => ['read' => 1]],
        ['subject_type' => 'group', 'subject_key' => '7', 'permissions' => ['read' => 1]],
        ['subject_type' => 'trust', 'subject_key' => '4', 'permissions' => ['read' => 1]],
    ], rt());
    T::same(5, $db->count('community_forum_permissions', ['forum_id' => $a]), 'Grenzwerte, die gültig sind (50 Zeichen, Gruppe mit zehn Stellen, trust 4), werden gespeichert');
}

// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
// Backends
// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

/** @var array<string, \Closure(): Database> $backends */
$backends = ['SQLite-Double' => static fn (): Database => CommunityTestDatabase::create()];
if (MariaDbTestDatabase::configured()) {
    $backends['MariaDB'] = static fn (): Database => MariaDbTestDatabase::create();
}

$scenarios = [
    'Kategorien: CRUD, Slugs, Sichtbarkeit' => 'rScenarioCategories',
    'Foren: Baum, path/depth, Tiefengrenze, Slugs über beide Spalten, Zwischenspeicher, update' => 'rScenarioForumTree',
    'Foren: Tiefengrenze beim Anlegen' => 'rScenarioForumMaxDepth',
    'Foren und Kategorien: UNIQUE-Verletzung im Wettlauf wird zum Fachfehler' => 'rScenarioUniqueRace',
    'Foren: Slug-Prüfung unter der Mutex-Zeile (B4)' => 'rScenarioForumSlugMutex',
    'Foren: Zähler atomar, nie unter 0, letzter Beitrag' => 'rScenarioForumCounters',
    'Foren: Rechte-Zeilen über die Vererbungskette, mehrere Subjekte' => 'rScenarioPermissions',
    'Foren: Moderatoren mit include_children' => 'rScenarioModerators',
    'Themen: CRUD, Whitelist, version, Zähler, Sperre, Grenzen' => 'rScenarioTopics',
    'Themen: Keyset-Liste mit drei Zeitstempeln, ungültige Cursor' => 'rScenarioTopicKeyset',
    'Themen: angeheftete und befristete Themen' => 'rScenarioTopicPins',
    'Themen: Doppelt-Absenden' => 'rScenarioTopicDuplicates',
    'Themen: letztes veröffentlichtes Thema über zwei Abfragen (B5)' => 'rScenarioLastPublished',
    'Beiträge: CRUD, Bearbeitung, Verlauf' => 'rScenarioPosts',
    'Beiträge: Seiten, Platzhalter, Position' => 'rScenarioPostLists',
    'Beiträge: letzter Beitrag, Zählen, Abstände, Doppelt-Absenden' => 'rScenarioPostQueries',
    'Lesestatus: nur steigend, Foren-Marken, erster ungelesener Beitrag' => 'rScenarioReadStates',
    'Lesestatus: Ungelesen-Zähler mit Obergrenze' => 'rScenarioUnreadCount',
    'Mitglieder: Konto und aktive Gruppen' => 'rScenarioMembers',
    'Sperren: Fenster, aufgehoben, Umfang, interne Notiz' => 'rScenarioBans',
    'Benutzerstatistik: ensure, Sperre, Zähler, letzter Beitrag' => 'rScenarioUserStats',
    'Rücknahme: Fehler im letzten Schritt (Fehlerinjektion)' => 'rScenarioRollback',
    'Transaktionen: Verschachtelung über drei und vier Ebenen (B33/B40)' => 'rScenarioTransactionNesting',
    'Transaktionen: Wiederholung nur bei Deadlock, nicht bei Lock-Timeout (B34)' => 'rScenarioTransactionRetry',
    'Foren und Kategorien: Spaltenlängen und -bereiche (B37)' => 'rScenarioColumnLimits',
    'Foren: reservierte Slugs auch beim Schreiben (B28)' => 'rScenarioReservedSlugs',
    'Foren und Kategorien: nur UNIQUE wird zu slug_taken (B38)' => 'rScenarioDuplicateKeyCodes',
    'Themen: Pins bis MAX_PINNED (B24)' => 'rScenarioPinLimit',
    'Foren: beschädigter Pfad und Rechte-Subjekte an den Grenzen (B43)' => 'rScenarioForumKeys',
];
foreach ($backends as $label => $make) {
    foreach ($scenarios as $title => $function) {
        T::run($title . ' [' . $label . ']', static function () use ($make, $function): void {
            $db = $make();
            try {
                $function($db);
            } finally {
                if ($db instanceof MariaDbTestDatabase) {
                    $db->dispose();
                }
            }
        });
    }
}
if (!MariaDbTestDatabase::configured()) {
    T::run('MariaDB-Läufe derselben Abläufe', static function (): void {
        T::info(MariaDbTestDatabase::skipNotice());
    });
}

// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
// Cursor und Savepoint-Namen (ohne Datenbankserver)
// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

T::run('Cursor: nur wirkliche Zeitpunkte und IDs in INT UNSIGNED (B22), Längengrenze (B43)', static function (): void {
    $encode = static fn (string $plain): string => rtrim(strtr(base64_encode($plain), '+/', '-_'), '=');

    // gültig
    foreach ([['2026-11-15 10:00:00', 1], ['2024-02-29 23:59:59', 4294967295], ['1970-01-01 00:00:00', 7], ['9999-12-31 23:59:59', 999999], ['2026-12-31 00:00:00', 4294967290]] as [$at, $id]) {
        $decoded = Cursor::decode($encode($at . '|' . $id));
        T::same([$at, $id], [$decoded?->at, $decoded?->id], "gültig: {$at} | {$id}");
        T::same($encode($at . '|' . $id), $decoded?->encode(), "encode(decode(x)) = x: {$at} | {$id}");
    }
    // ungültige Zeitpunkte: PHP würde sie umrechnen (30. Februar → 2. März) oder die Datenbank nur mit Warnung vergleichen
    foreach ([
        '9999-99-99 99:99:99', '0000-00-00 00:00:00', '2036-13-45 25:61:61', '2026-02-30 10:00:00', '2025-02-29 12:00:00', '2026-04-31 00:00:00',
        '2026-00-10 10:00:00', '2026-11-00 10:00:00', '2026-13-01 10:00:00', '2026-11-15 24:00:00', '2026-11-15 10:60:00', '2026-11-15 10:00:60', '2026-11-32 10:00:00',
    ] as $at) {
        T::same(null, Cursor::decode($encode($at . '|5')), "ungültiger Zeitpunkt {$at} → null (erste Seite)");
    }
    // IDs: nur 1 bis 4294967295 ohne führende Null
    foreach (['0', '00001', '01', '4294967296', '9999999999', '99999999999', '18446744073709551615', '99999999999999999999', '-1', '1.5', '1e3', ' 1', '1 '] as $id) {
        T::same(null, Cursor::decode($encode('2026-11-15 10:00:00|' . $id)), "ungültige ID {$id} → null");
    }
    T::same(4294967295, Cursor::decode($encode('2026-11-15 10:00:00|4294967295'))?->id, 'größte ID einer INT-UNSIGNED-Spalte gilt');
    // Form
    foreach (['', '2026-11-15 10:00:00', '2026-11-15 10:00:00|', '|5', '2026-11-15|5', '2026-11-15 10:00:00 |5', '2026-11-15 10:00:00|5|6', "2026-11-15 10:00:00|5\0"] as $plain) {
        T::same(null, Cursor::decode($encode($plain)), 'ungültige Form: ' . T::export($plain));
    }
    T::same(null, Cursor::decode(null), 'null → null');

    // Länge: MAX_LENGTH ist genau die Länge des längsten gültigen Cursors; jede längere Eingabe wird abgewiesen
    $longest = (new Cursor('9999-12-31 23:59:59', Cursor::MAX_ID))->encode();
    T::same(Cursor::MAX_LENGTH, strlen($longest), 'der längste gültige Cursor hat genau MAX_LENGTH Zeichen');
    T::same([Cursor::MAX_ID], [Cursor::decode($longest)?->id], 'und wird dekodiert');
    T::check(Cursor::MAX_LENGTH <= 64, 'MAX_LENGTH ist eine kleine Grenze (kein Platz für große Eingaben)', (string) Cursor::MAX_LENGTH);
    T::same(null, Cursor::decode($longest . 'A'), 'ein Zeichen länger → null');
    T::same(null, Cursor::decode(str_repeat('A', Cursor::MAX_LENGTH + 1)), 'zu lange Eingabe → null');
    T::same(null, Cursor::decode(str_repeat('A', 100000)), 'sehr lange Eingabe → null (ohne zu dekodieren)');
    T::same(null, Cursor::decode($longest . '='), 'Auffüllzeichen sind nicht erlaubt');
});

/**
 * PDO, das jede Transaktionsanweisung festhält: BEGIN, SAVEPOINT, RELEASE, ROLLBACK TO, COMMIT, ROLLBACK. Das SQLite-Double vertrüge
 * gleichnamige Savepoints (SQLite führt sie als Stapel), MariaDB nicht (der gleichnamige Savepoint ersetzt den äußeren, Fehler 1305):
 * deshalb prüft der Test die Namen selbst, nicht die Folgen.
 */
final class RepoRecordingPdo extends \PDO
{
    /** @var list<string> */
    public array $statements = [];

    public function exec(string $statement): int|false
    {
        $this->statements[] = $statement;

        return parent::exec($statement);
    }

    public function beginTransaction(): bool
    {
        $this->statements[] = 'BEGIN';

        return parent::beginTransaction();
    }

    public function commit(): bool
    {
        $this->statements[] = 'COMMIT';

        return parent::commit();
    }

    public function rollBack(): bool
    {
        $this->statements[] = 'ROLLBACK';

        return parent::rollBack();
    }
}

T::run('Transaktion: Savepoints heißen je Tiefe verschieden, Geschwister gleich (B33/B40), unabhängig vom Datenbanktreiber', static function (): void {
    $make = static function (): array {
        $pdo = new RepoRecordingPdo('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $db = (new \ReflectionClass(Database::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(Database::class, 'pdo'))->setValue($db, $pdo);

        return [$db, $pdo];
    };
    $nop = static fn (): null => null;

    // Drei Ebenen
    [$db, $pdo] = $make();
    Transaction::run($db, static function () use ($db, $nop): void {
        Transaction::run($db, static function () use ($db, $nop): void {
            Transaction::run($db, $nop);
        });
    });
    T::same(
        ['BEGIN', 'SAVEPOINT cms_community_tx_1', 'SAVEPOINT cms_community_tx_2', 'RELEASE SAVEPOINT cms_community_tx_2', 'RELEASE SAVEPOINT cms_community_tx_1', 'COMMIT'],
        $pdo->statements,
        'drei Ebenen: BEGIN, Savepoint der Tiefe 1, Savepoint der Tiefe 2, danach von innen nach außen freigegeben, COMMIT'
    );

    // Vier Ebenen mit Geschwistern auf Tiefe 2: nach jedem Aufruf fällt die Tiefe zurück
    [$db, $pdo] = $make();
    Transaction::run($db, static function () use ($db, $nop): void {
        Transaction::run($db, static function () use ($db, $nop): void {
            Transaction::run($db, $nop);
            Transaction::run($db, static function () use ($db, $nop): void {
                Transaction::run($db, $nop);
            });
        });
        Transaction::run($db, $nop);
    });
    T::same(
        [
            'BEGIN', 'SAVEPOINT cms_community_tx_1',
            'SAVEPOINT cms_community_tx_2', 'RELEASE SAVEPOINT cms_community_tx_2',
            'SAVEPOINT cms_community_tx_2', 'SAVEPOINT cms_community_tx_3', 'RELEASE SAVEPOINT cms_community_tx_3', 'RELEASE SAVEPOINT cms_community_tx_2',
            'RELEASE SAVEPOINT cms_community_tx_1',
            'SAVEPOINT cms_community_tx_1', 'RELEASE SAVEPOINT cms_community_tx_1',
            'COMMIT',
        ],
        $pdo->statements,
        'Geschwister: gleiche Tiefe, gleicher Name; jede tiefere Ebene einen eigenen Namen'
    );

    // Scheitert die mittlere Ebene: Rücknahme auf ihren Savepoint (nicht auf den der inneren Ebene), die äußere macht weiter
    [$db, $pdo] = $make();
    Transaction::run($db, static function () use ($db, $nop): void {
        try {
            Transaction::run($db, static function () use ($db, $nop): void {
                Transaction::run($db, $nop);
                throw new \RuntimeException('Mitte');
            });
        } catch (\RuntimeException) {
        }
        Transaction::run($db, $nop);
    });
    T::same(
        [
            'BEGIN', 'SAVEPOINT cms_community_tx_1', 'SAVEPOINT cms_community_tx_2', 'RELEASE SAVEPOINT cms_community_tx_2',
            'ROLLBACK TO SAVEPOINT cms_community_tx_1', 'RELEASE SAVEPOINT cms_community_tx_1',
            'SAVEPOINT cms_community_tx_1', 'RELEASE SAVEPOINT cms_community_tx_1', 'COMMIT',
        ],
        $pdo->statements,
        'mittlere Ebene scheitert: ROLLBACK TO und RELEASE auf ihren eigenen Savepoint, die Tiefe fällt danach zurück'
    );

    // Nach einer Ausnahme der äußersten Ebene beginnt die Zählung neu
    [$db, $pdo] = $make();
    try {
        Transaction::run($db, static function () use ($db, $nop): void {
            Transaction::run($db, $nop);
            throw new \RuntimeException('außen');
        });
    } catch (\RuntimeException) {
    }
    Transaction::run($db, static function () use ($db, $nop): void {
        Transaction::run($db, $nop);
    });
    T::same(
        ['BEGIN', 'SAVEPOINT cms_community_tx_1', 'RELEASE SAVEPOINT cms_community_tx_1', 'ROLLBACK', 'BEGIN', 'SAVEPOINT cms_community_tx_1', 'RELEASE SAVEPOINT cms_community_tx_1', 'COMMIT'],
        $pdo->statements,
        'nach einer Rücknahme der äußersten Ebene beginnt die Tiefenzählung wieder bei 1'
    );

    // Eine zweite Verbindung zählt für sich
    [$db, $pdo] = $make();
    [$dbOther, $pdoOther] = $make();
    Transaction::run($db, static function () use ($dbOther, $nop): void {
        Transaction::run($dbOther, static function () use ($dbOther, $nop): void {
            Transaction::run($dbOther, $nop);
        });
    });
    T::same(['BEGIN', 'COMMIT'], $pdo->statements, 'Verbindung A: nur die äußerste Ebene');
    T::same(['BEGIN', 'SAVEPOINT cms_community_tx_1', 'RELEASE SAVEPOINT cms_community_tx_1', 'COMMIT'], $pdoOther->statements, 'Verbindung B zählt unabhängig von A (beginnt bei der äußersten Ebene)');
});

// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
// SQLite-Double
// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

T::run('SQLite-Double: Tabellen und Indizes aus den echten CREATE-Texten', static function (): void {
    $db = CommunityTestDatabase::create();
    $expected = [...CommunityTestSchema::CORE_TABLES, 'role_permissions', ...\CMS\SchemaManager::FEATURE_TABLES_V27];
    $missing = array_values(array_filter($expected, static fn (string $table): bool => !$db->tableExists('cms_' . $table)));
    T::same([], $missing, 'alle Testtabellen sind angelegt (28 Community-Tabellen plus Core-Tabellen)');
    T::same(28, count(\CMS\SchemaManager::FEATURE_TABLES_V27), 'die Community-Tabellen sind 28');
    T::same(false, $db->tableExists('cms_gibt_es_nicht'), 'tableExists: unbekannte Tabelle → false');
    foreach (['uq_community_forums_slug', 'uq_community_forums_slug_en', 'idx_community_forums_path', 'idx_community_topics_forum_list', 'idx_community_posts_topic', 'uq_community_forum_permissions_subject', 'idx_community_bans_user', 'unique_user_group'] as $index) {
        T::same(1, (int) $db->get_var("SELECT COUNT(*) FROM sqlite_master WHERE type = 'index' AND name = ?", [$index]), "Index {$index} ist angelegt");
    }
    $columns = array_map(static fn (object $r): string => (string) $r->name, $db->get_results('PRAGMA index_info(idx_community_forums_path)'));
    T::same(['path'], $columns, 'Präfixindex path(191) → Spalte path (verschachtelte Klammern)');
    $columns = array_map(static fn (object $r): string => (string) $r->name, $db->get_results('PRAGMA index_info(idx_community_topics_forum_list)'));
    T::same(['forum_id', 'status', 'is_pinned', 'last_post_at'], $columns, 'Mehrspaltenindex mit Spaltenreihenfolge');

    // UNIQUE greift wie in MariaDB
    $db->forum(['slug' => 'eins']);
    try {
        $db->forum(['slug' => 'eins']);
        T::check(false, 'UNIQUE auf slug wird erzwungen');
    } catch (\PDOException $e) {
        T::same('23000', (string) $e->getCode(), 'UNIQUE auf slug: SQLSTATE 23000');
    }
    // INT UNSIGNED AUTO_INCREMENT PRIMARY KEY → eigene IDs
    $a = $db->user();
    $b = $db->user();
    T::check($b === $a + 1 && $a > 0, 'AUTO_INCREMENT-Spalten vergeben fortlaufende IDs');

    // Strenge: Zeitspalten der Community-Tabellen ohne Default
    try {
        $db->execute('INSERT INTO cms_community_forums (title, slug) VALUES (?, ?)', ['x', 'ohne-zeit']);
        T::check(false, 'Insert ohne created_at wird abgelehnt');
    } catch (\PDOException) {
        T::check(true, 'Insert ohne created_at/updated_at wird abgelehnt (kein DEFAULT CURRENT_TIMESTAMP bei DATETIME NOT NULL)');
    }
    $sql = CommunityTestSchema::sqlite(CommunityTestSchema::mysqlStatements('cms_')['community_topics'])[0];
    T::check(!str_contains(strtoupper($sql), 'CURRENT_TIMESTAMP'), 'Community-CREATE im Double ohne CURRENT_TIMESTAMP');
    $users = CommunityTestSchema::sqlite(CommunityTestSchema::mysqlStatements('cms_')['users'])[0];
    T::check(str_contains($users, 'DEFAULT CURRENT_TIMESTAMP') && !str_contains($users, 'ON UPDATE'), 'Core-Tabelle users: Default bleibt, ON UPDATE entfällt');
    $groups = CommunityTestSchema::sqlite(CommunityTestSchema::mysqlStatements('cms_')['user_groups'])[0];
    T::check(!str_contains($groups, 'COMMENT'), 'Spaltenkommentare entfallen');
});

T::run('SQLite-Double: Übersetzung, Fehlerinjektion, Wächter für nicht portables SQL', static function (): void {
    T::same('INSERT OR IGNORE INTO t (a) VALUES (?)', CommunityTestDatabase::translate('INSERT IGNORE INTO t (a) VALUES (?)'), 'INSERT IGNORE → INSERT OR IGNORE');
    T::same('INSERT OR IGNORE INTO t', CommunityTestDatabase::translate('insert   ignore INTO t'), 'Übersetzung unabhängig von Groß-/Kleinschreibung und Leerraum');
    T::same('SELECT 1 FROM t WHERE id = ? LIMIT 1', CommunityTestDatabase::translate('SELECT 1 FROM t WHERE id = ? LIMIT 1 FOR UPDATE'), ' FOR UPDATE entfällt');

    $db = CommunityTestDatabase::create();
    $forum = $db->forum(['title' => 'Sperre']);
    $db->log = [];
    T::same($forum, (int) (new ForumRepository($db))->lockForUpdate($forum)['id'], 'FOR UPDATE wird vom Double verarbeitet');
    T::check(str_ends_with($db->log[0] ?? '', ' LIMIT 1 FOR UPDATE'), '$log enthält das SQL vor der Übersetzung');
    $users = new UserStatsRepository($db);
    $users->ensure(5, rt());
    $users->ensure(5, rt());
    T::same(1, $db->count('community_user_stats', ['user_id' => 5]), 'INSERT IGNORE auf vorhandene Zeile: kein Fehler, keine zweite Zeile');

    $db->failOn = ['/FROM \S*community_forums/'];
    try {
        $db->execute('SELECT * FROM cms_community_forums');
        T::check(false, 'failOn wirft');
    } catch (\PDOException $e) {
        T::check(str_contains($e->getMessage(), 'Testfehler'), 'failOn: PDOException mit Testfehler-Text');
    }
    $db->failOn = [];
    T::same(1, count($db->get_results('SELECT * FROM cms_community_forums')), 'nach dem Zurücksetzen von failOn geht es wieder');
    T::same($forum, (int) $db->get_var('SELECT id FROM cms_community_forums WHERE id = ?', [$forum]), 'get_var');
    T::same($forum, (int) ($db->get_row('SELECT id FROM cms_community_forums WHERE id = ?', [$forum])->id ?? 0), 'get_row');
    T::same([$forum], array_map('intval', $db->get_col('SELECT id FROM cms_community_forums')), 'get_col');
    T::same(null, $db->get_row('SELECT id FROM cms_community_forums WHERE id = ?', [99999]), 'get_row: keine Zeile → null');
    T::same(null, $db->get_var('SELECT id FROM cms_community_forums WHERE id = ?', [99999]), 'get_var: keine Zeile → null');
    T::same('cms_', $db->getPrefix(), 'getPrefix');
    T::check($db->getPdo() === $db->sqlite && $db->getPdo() instanceof \PDO, 'getPdo liefert die SQLite-Verbindung');

    $bad = [
        'SELECT NOW()' => 'NOW()',
        'UPDATE t SET a = CURRENT_TIMESTAMP' => 'CURRENT_TIMESTAMP',
        'SELECT CURDATE()' => 'CURDATE()',
        'SELECT UTC_TIMESTAMP()' => 'UTC_TIMESTAMP',
        'SELECT * FROM t LIMIT 5 OFFSET 10' => 'OFFSET',
        'INSERT INTO t (a) VALUES (1) ON DUPLICATE KEY UPDATE a = 2' => 'ON DUPLICATE KEY',
        'UPDATE t SET a = GREATEST(a, ?)' => 'GREATEST()',
        'UPDATE t SET a = LEAST(a, ?)' => 'LEAST()',
        'SELECT * FROM t ORDER BY FIELD(status, ?, ?)' => 'FIELD()',
        'SELECT JSON_EXTRACT(a, ?) FROM t' => 'JSON_*()',
        'SELECT * FROM t WHERE (last_post_at, id) < (?, ?)' => 'Zeilenkonstruktor',
    ];
    foreach ($bad as $sql => $label) {
        T::same([$label], CommunityTestDatabase::portabilityViolations($sql), "Wächter erkennt {$label}");
    }
    foreach ([
        "SELECT * FROM t WHERE a IN (?, ?) AND (b < ? OR (b = ? AND id < ?)) ORDER BY b DESC, id DESC LIMIT ?",
        'INSERT IGNORE INTO t (a, b) VALUES (?, ?)',
        'UPDATE t SET c = CASE WHEN c + ? > ? THEN c + ? - ? ELSE 0 END, version = version + 1 WHERE id = ?',
        "SELECT updated_at, created_at, offsets FROM t WHERE name = 'now'",
    ] as $sql) {
        T::same([], CommunityTestDatabase::portabilityViolations($sql), 'Wächter lässt portables SQL durch: ' . substr($sql, 0, 40));
    }
    try {
        $db->execute('SELECT NOW()');
        T::check(false, 'execute weist nicht portables SQL ab');
    } catch (\LogicException $e) {
        T::check(str_contains($e->getMessage(), 'NOW()'), 'execute weist nicht portables SQL mit LogicException ab');
    }
});

T::run('Themen: Forenliste in zwei Schritten, Zeilen ändern sich zwischen den Schritten [SQLite-Double]', static function (): void {
    $db = CommunityTestDatabase::create();
    $repo = new TopicRepository($db);
    $forum = $db->forum(['title' => 'Zwei Schritte']);
    $other = $db->forum(['title' => 'Ziel']);
    $user = $db->user();
    $ids = [];
    foreach ([100, 200, 300, 400, 500, 600] as $seconds) {
        $ids[$seconds] = $db->topic(['forum' => $forum, 'user' => $user, 'last_post_at' => rt($seconds)]);
    }
    $db->log = [];
    $plain = $repo->listForum($forum, rt(1000), null, 5);
    $selects = array_values(array_filter($db->log, static fn (string $sql): bool => str_starts_with(ltrim($sql), 'SELECT')));
    T::same([$ids[600], $ids[500], $ids[400], $ids[300], $ids[200]], rIds($plain->items), 'ohne Änderung: fünf Themen nach Aktivität');
    T::check(str_starts_with($selects[0] ?? '', 'SELECT id, last_post_at FROM ') && str_contains($selects[0], 'is_pinned = 0'), 'erster Schritt: nur id und last_post_at über den Keyset (Index idx_community_topics_forum_list)', $selects[0] ?? '');
    T::same(1, count(array_filter($selects, static fn (string $sql): bool => str_starts_with($sql, 'SELECT * FROM ') && str_contains($sql, ' WHERE id IN ('))), 'zweiter Schritt: die Zeilen über id IN (…)');

    // Zwischen beiden Schritten: eines ausgeblendet, eines verschoben, eines angeheftet, eines entfernt.
    $table = $db->getPrefix() . 'community_topics';
    $db->beforeExecute = ['/ WHERE id IN \(/' => static function () use ($db, $table, $ids, $other): void {
        $db->execute("UPDATE {$table} SET status = 'hidden' WHERE id = ?", [$ids[600]]);
        $db->execute("UPDATE {$table} SET forum_id = ? WHERE id = ?", [$other, $ids[500]]);
        $db->execute("UPDATE {$table} SET is_pinned = 1 WHERE id = ?", [$ids[400]]);
        $db->execute("DELETE FROM {$table} WHERE id = ?", [$ids[300]]);
    }];
    $page = $repo->listForum($forum, rt(1000), null, 5);
    T::same([], $db->beforeExecute, 'der Eingriff lief zwischen beiden Schritten');
    T::same([$ids[400], $ids[200]], rIds($page->items), 'geändert zwischen beiden Schritten: ausgeblendet, verschoben und entfernt fehlen, das angeheftete steht nur oben bei den Pins');
    T::same([], array_values(array_filter($page->items, static fn (array $row): bool => (string) $row['status'] !== 'published' || (int) $row['forum_id'] !== $forum)), 'keine Zeile mit anderem Status oder aus einem anderen Forum');
    $cursor = Cursor::decode((string) $page->next);
    T::check($cursor !== null && $cursor->id === $ids[200] && $cursor->at === rt(200), 'der Cursor stammt aus dem ersten Schritt (letzte ID-Zeile der Seite)');
    T::same([$ids[100]], rIds($repo->listForum($forum, rt(1000), $cursor, 5)->items), 'die Folgeseite beginnt hinter dem Cursor, nichts geht verloren');
});

T::run('SQLite-Double: Fabrikhilfen', static function (): void {
    $db = CommunityTestDatabase::create();
    $a = $db->forum(['title' => 'A']);
    $b = $db->forum(['title' => 'B', 'parent' => $a, 'category' => 2, 'status' => 'readonly', 'inherit' => false, 'require_approval' => true, 'slow_mode' => 30]);
    $c = $db->forum(['parent' => $b]);
    $rowA = $db->row('community_forums', ['id' => $a]);
    $rowB = $db->row('community_forums', ['id' => $b]);
    $rowC = $db->row('community_forums', ['id' => $c]);
    T::same(['/' . $a . '/', 0, null], [$rowA['path'], (int) $rowA['depth'], $rowA['parent_id']], 'forum(): Wurzel');
    T::same(['/' . $a . '/' . $b . '/', 1, $a, 2, 'readonly', 0, 1, 30], [$rowB['path'], (int) $rowB['depth'], (int) $rowB['parent_id'], (int) $rowB['category_id'], $rowB['status'], (int) $rowB['inherit_permissions'], (int) $rowB['require_approval'], (int) $rowB['slow_mode_seconds']], 'forum(): Unterforum mit Optionen');
    T::same(['/' . $a . '/' . $b . '/' . $c . '/', 2, 2], [$rowC['path'], (int) $rowC['depth'], (int) $rowC['category_id']], 'forum(): dritte Ebene erbt die Kategorie');
    try {
        $db->forum(['parent' => 99999]);
        T::check(false, 'forum() mit unbekanntem Elternforum wirft');
    } catch (\InvalidArgumentException) {
        T::check(true, 'forum() mit unbekanntem Elternforum wirft');
    }
    $u1 = $db->user();
    $u2 = $db->user(['username' => 'anna', 'email' => 'anna@example.test', 'role' => 'editor', 'status' => 'inactive']);
    T::same([$u1 + 1, 'editor', 'inactive'], [$u2, $db->row('users', ['id' => $u2])['role'], $db->row('users', ['id' => $u2])['status']], 'user(): eindeutige Namen, Optionen');
    $t = $db->topic(['forum' => $a, 'user' => $u1, 'title' => 'Hallo']);
    $p = $db->post(['topic' => $t, 'user' => $u1, 'first' => true]);
    $rowT = $db->row('community_topics', ['id' => $t]);
    $rowP = $db->row('community_posts', ['id' => $p]);
    T::same([$a, 'published', CommunityTestDatabase::FIXTURE_TIME, CommunityTestDatabase::FIXTURE_TIME], [(int) $rowT['forum_id'], $rowT['status'], $rowT['created_at'], $rowT['last_post_at']], 'topic(): Standardwerte, last_post_at = created_at');
    T::same([$a, $t, 1], [(int) $rowP['forum_id'], (int) $rowP['topic_id'], (int) $rowP['is_first_post']], 'post(): Forum aus dem Thema');
    $db->stats($u1, ['post_count' => 4, 'trust_level' => 2, 'trust_level_locked' => true]);
    T::same([4, 2, 1], array_map('intval', array_values(array_intersect_key($db->row('community_user_stats', ['user_id' => $u1]) ?? [], ['post_count' => 1, 'trust_level' => 1, 'trust_level_locked' => 1]))), 'stats(): Zeile mit Optionen');
    $g = $db->group('Team');
    $db->join($u1, $g);
    T::same(1, $db->count('user_group_members', ['user_id' => $u1, 'group_id' => $g]), 'group()/join()');
    $ban = $db->ban(['user' => $u1, 'ends_at' => '2030-01-01 00:00:00']);
    T::same(['write', '2030-01-01 00:00:00', null], [$db->row('community_bans', ['id' => $ban])['scope'], $db->row('community_bans', ['id' => $ban])['ends_at'], $db->row('community_bans', ['id' => $ban])['lifted_at']], 'ban(): Standardumfang write');
    T::same(null, $db->row('community_topics', ['id' => 99999]), 'row(): keine Zeile → null');
    T::same(2, count($db->rows('community_forums', ['category_id' => 2])), 'rows() mit Bedingung');
    T::same(1, $db->count('community_forums', ['parent_id' => null, 'id' => $a]), 'count() mit IS NULL');
    foreach ([static fn () => $db->row('users; DROP', []), static fn () => $db->rows('users', ['id; x' => 1]), static fn () => $db->rows('users', [], 'id; x'), static fn () => $db->topic([])] as $call) {
        try {
            $call();
            T::check(false, 'ungültige Namen oder fehlende Pflichtoptionen werfen');
        } catch (\InvalidArgumentException) {
            T::check(true, 'ungültige Namen oder fehlende Pflichtoptionen werfen');
        }
    }

    $mysql = CommunityTestSchema::ROLE_PERMISSIONS;
    $source = rSource('includes/functions/roles.php');
    T::check(preg_match('/CREATE TABLE IF NOT EXISTS \{\$db->prefix\(\)\}role_permissions \((.*?)\) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci/s', $source, $m) === 1, 'cms_ensure_role_permissions_table: CREATE-Text gefunden');
    $normalize = static fn (string $sql): string => (string) preg_replace('/\s+/', ' ', trim($sql));
    T::same($normalize((string) ($m[1] ?? '')), $normalize((string) (preg_match('/\((.*?)\) ENGINE=/s', $mysql, $mm) === 1 ? $mm[1] : '')), 'role_permissions im Double entspricht cms_ensure_role_permissions_table()');
});

// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
// Quelltext-Wächter
// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

T::run('Quelltext-Wächter: Repository-Code ohne Zeit, Dialekt und Schreibhelfer der Datenbank', static function (): void {
    $files = glob(ABSPATH . 'core/Services/Community/Repository/*.php') ?: [];
    sort($files);
    $names = array_map('basename', $files);
    // PR #2b ergänzt Repositories (ModerationLogRepository, ReportRepository, …); jede Datei im Ordner durchläuft die Prüfungen unten.
    $required = ['AbstractRepository.php', 'BanRepository.php', 'CategoryRepository.php', 'ForumRepository.php', 'MemberRepository.php', 'ModerationLogRepository.php', 'PostRepository.php', 'ReadStateRepository.php', 'ReportRepository.php', 'TopicRepository.php', 'UserStatsRepository.php'];
    T::same([], array_values(array_diff($required, $names)), 'alle Repository-Dateien aus PR #2a und W1 (B1, B2) sind vorhanden');
    T::same([], array_values(array_filter($names, static fn (string $name): bool => preg_match('/^[A-Z][A-Za-z]*Repository\.php$/', $name) !== 1)), 'im Ordner liegen nur Repository-Klassen (flach, Vertrag DESIGN-2b §1 Nr. 7)');

    $selfTest = rSourceViolations("<?php\n\$x = 'SELECT NOW()'; \$y = date('Y'); \$z = \$this->db->insert('t', []); \$q = 'LIMIT 5 OFFSET 2'; \$u = 'ON DUPLICATE KEY UPDATE'; \$g = 'GREATEST(a,b)'; \$t = time(); \$c = 'CURRENT_TIMESTAMP';");
    T::check(count($selfTest) >= 8 && in_array('NOW()', $selfTest, true) && in_array('date()', $selfTest, true) && in_array('Database::insert/update/delete', $selfTest, true), 'Gegenprobe: der Wächter erkennt einen absichtlich schlechten Quelltext', implode(', ', $selfTest));
    T::same([], rSourceViolations("<?php\n\$this->update(\$id); \$stmt = 'UPDATE t SET a = ?'; \$r = \$this->db->execute(\$sql); gmmkt();"), 'Gegenprobe: update() als Methodenname und execute() sind unproblematisch');

    $forUpdate = 0;
    $instance = 0;
    foreach ($files as $file) {
        $name = basename($file);
        $source = (string) file_get_contents($file);
        T::same([], rSourceViolations($source), $name . ': keine verbotenen Konstrukte');
        T::check(str_contains($source, 'declare(strict_types=1);') && str_contains($source, 'namespace CMS\\Services\\Community\\Repository;') && str_contains($source, "if (!defined('ABSPATH'))"), $name . ': strict_types, Namensraum, ABSPATH-Wächter');
        T::check(str_contains($source, '@package CMSv2\\Core\\Services\\Community'), $name . ': Kopfkommentar mit @package');
        T::check($name === 'AbstractRepository.php' ? str_contains($source, 'abstract class AbstractRepository') : str_contains($source, 'final class ' . substr($name, 0, -4) . ' extends AbstractRepository'), $name . ': Klassenkopf (abstrakt bzw. final, erbt AbstractRepository)');
        $forUpdate += substr_count($source, "' FOR UPDATE'");
        $instance += substr_count($source, 'Database::instance()');
        T::check(stripos($source, 'FOR UPDATE') === false || $name === 'AbstractRepository.php', $name . ': FOR UPDATE steht nur in AbstractRepository::forUpdate()');
        T::check(preg_match('/PDOStatement|->prepare\(|->query\(/', $source) !== 1, $name . ': kein direkter PDO-Zugriff (nur execute())');
    }
    T::same(1, $forUpdate, 'der Anhang \' FOR UPDATE\' kommt in allen Repositories genau einmal vor');
    T::same(1, $instance, 'Database::instance() kommt genau einmal vor (Konstruktor-Standard der Basisklasse)');
    T::check(substr_count(rSource('core/Services/Community/Repository/AbstractRepository.php'), "return ' FOR UPDATE';") === 1, 'forUpdate() liefert den Anhang genau an einer Stelle');
});

// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
// Nur MariaDB
// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

if (MariaDbTestDatabase::configured()) {
    T::run('MariaDB: UTC-Werte bleiben bei Ortszeit der Sitzung unverändert', static function (): void {
        $db = MariaDbTestDatabase::create('+01:00');
        try {
            T::same('+01:00', (string) $db->get_var('SELECT @@session.time_zone'), 'Sitzungszone +01:00');
            $local = (string) $db->get_var('SELECT NOW()');
            $utc = (string) $db->get_var('SELECT UTC_TIMESTAMP()');
            T::check($local !== $utc && abs(strtotime($local . ' UTC') - strtotime($utc . ' UTC') - 3600) <= 2, 'Voraussetzung: NOW() ist eine Stunde vor/nach UTC (Ortszeit der Sitzung)', $local . ' / ' . $utc);

            $forum = $db->forum(['title' => 'UTC']);
            $user = $db->user();
            $topics = new TopicRepository($db);
            $created = gmdate('Y-m-d H:i:s', gmmktime(23, 30, 0, 12, 31, 2026));
            $id = $topics->insert(['forum_id' => $forum, 'user_id' => $user, 'title' => 'Silvester', 'slug' => 'silvester', 'last_post_at' => $created, 'created_at' => $created, 'updated_at' => $created]);
            T::same([$created, $created], [$topics->find($id)['created_at'], $topics->find($id)['last_post_at']], 'gebundene UTC-Zeiten stehen unverändert in der Spalte');
            T::same($id, $topics->lastOfUser($user, $created)['id'] ?? 0, 'Vergleiche mit gebundenen UTC-Werten treffen genau');
            T::same(null, $topics->lastOfUser($user, gmdate('Y-m-d H:i:s', gmmktime(23, 30, 1, 12, 31, 2026))), 'eine Sekunde später: kein Treffer');
            $default = $db->forum(['title' => 'Default']);
            $db->getPdo()->exec('INSERT INTO cms_community_bans (user_id, scope, reason) VALUES (1, \'write\', \'x\')');
            T::check((string) $db->row('community_bans', ['user_id' => 1])['starts_at'] === $local || abs(strtotime((string) $db->row('community_bans', ['user_id' => 1])['starts_at'] . ' UTC') - strtotime($local . ' UTC')) <= 2, 'Spaltendefaults tragen die Ortszeit der Sitzung: darum setzen Dienste jede Zeit selbst (Gegenprobe zum SQLite-Double ohne Default)');
            T::check($default > 0, 'Fixture');
        } finally {
            $db->dispose();
        }
    });

    T::run('MariaDB: UNIQUE-Verletzung (1062) wird zum Fachfehler, UNSIGNED-Grenze, rowCount', static function (): void {
        $db = MariaDbTestDatabase::create();
        try {
            $repo = new ForumRepository($db);
            T::check($repo->create(['title' => 'Eins', 'slug' => 'eins'], 3, rt())['success'], 'Forum angelegt');
            try {
                $db->execute('INSERT INTO cms_community_forums (title, slug, created_at, updated_at) VALUES (?, ?, ?, ?)', ['Doppelt', 'eins', rt(), rt()]);
                T::check(false, 'UNIQUE auf slug');
            } catch (\PDOException $e) {
                T::same([1062, '23000'], [(int) ($e->errorInfo[1] ?? 0), (string) $e->getCode()], 'UNIQUE-Verletzung: 1062 / SQLSTATE 23000');
            }
            T::same(['success' => false, 'error' => 'slug_taken'], $repo->create(['title' => 'Eins', 'slug' => 'eins'], 3, rt()), 'create: doppelter Slug → slug_taken');

            // rowCount-Semantik: unveränderte Zeile zählt 0, darum steht in den Zählern immer version = version + 1
            $forum = (int) $repo->find((int) $db->get_var('SELECT id FROM cms_community_forums LIMIT 1'))['id'];
            $same = $db->execute('UPDATE cms_community_forums SET title = title WHERE id = ?', [$forum])->rowCount();
            T::same(0, $same, 'MariaDB: UPDATE ohne Änderung zählt 0 (gefunden, aber nicht geändert)');
            T::check($repo->applyCounters($forum, 0, 0, rt(10)), 'applyCounters liefert true, auch wenn kein Zähler ändert (version ändert sich)');
            T::same(true, $repo->applyCounters($forum, 0, -5, rt(11)), 'UNSIGNED: Verminderung unter 0 scheitert nicht („out of range“) und endet bei 0');
            T::same(0, (int) $repo->find($forum)['post_count'], 'post_count bleibt 0');

            $topics = new TopicRepository($db);
            $user = $db->user();
            $topic = $topics->insert(['forum_id' => $forum, 'user_id' => $user, 'title' => 'X', 'slug' => 'x', 'last_post_at' => rt(), 'created_at' => rt(), 'updated_at' => rt()]);
            T::check($topics->update($topic, ['title' => 'X']), 'TopicRepository::update: auch ohne geänderten Wert true (version)');
            $posts = new PostRepository($db);
            $post = $posts->insert(['topic_id' => $topic, 'forum_id' => $forum, 'user_id' => $user, 'body' => 'a', 'created_at' => rt(), 'updated_at' => rt()]);
            $posts->update($post, ['status_reason' => 'a']);
            T::same(false, $posts->update($post, ['status_reason' => 'a']), 'PostRepository::update ohne geänderten Wert: false (keine Versionsspalte, MariaDB zählt Änderungen)');
            T::same(true, $posts->update($post, ['status_reason' => 'b']), 'PostRepository::update mit Änderung: true');

            $reads = new ReadStateRepository($db);
            $raw = static fn (string $sql, array $p): int => $db->execute($sql, $p)->rowCount();
            T::same(1, $raw('INSERT IGNORE INTO cms_community_read_states (user_id, topic_id, last_read_post_id, updated_at) VALUES (?, ?, ?, ?)', [$user, $topic, 5, rt()]), 'INSERT IGNORE neu: 1');
            T::same(0, $raw('INSERT IGNORE INTO cms_community_read_states (user_id, topic_id, last_read_post_id, updated_at) VALUES (?, ?, ?, ?)', [$user, $topic, 9, rt()]), 'INSERT IGNORE vorhanden: 0');
            T::same([false, true], [$reads->advance($user, $topic, 5, rt(1)), $reads->advance($user, $topic, 6, rt(1))], 'advance über die echte Datenbank: gleich → false, größer → true');
        } finally {
            $db->dispose();
        }
    });
}

// Teildateien je Repository-Gruppe (Vertrag DESIGN-2b §1 Nr. 6; je Datei genau ein Besitzer), alphabetisch.
foreach (glob(__DIR__ . '/part-*.php') ?: [] as $part) {
    require $part;
}

T::finish();
