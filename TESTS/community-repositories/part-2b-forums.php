<?php
/**
 * Teil der Suite `community-repositories` (Besitzer A, PR #2b Welle 1): `ForumRepository` laut Vertrag DESIGN-2b §3.7.
 *
 *  - `tree()` mit `category_visible` (ohne Kategorie 1, sichtbar 1, unsichtbar 0, fehlend 0).
 *  - Mengenabfragen der Rechte: `permissionForumIds()` (Instanz-Zwischenspeicher, `savePermissions()` und `forgetTree()` leeren ihn),
 *    `permissionRowsMany()` (Subjekte, Gruppen als Text, Stücke), `firstPerSubject()` (eigene Zeile vor geerbter, `trust` bis zur Stufe,
 *    Zeilen fremder Foren zählen nicht).
 *  - Forum-Moderatoren: `moderatorAssignments()` (Konto und Gruppen, je Forum eine Zeile) und `moderates()` (einzige Kinderregel,
 *    auch `moderatorRows()` filtert damit; kein SQL-Text vergleicht `include_children`).
 *  - `touch()`: `version + 1` und `updated_at`, IDs aufsteigend, Stücke zu 100.
 *
 * Geladen von `run.php` vor `T::finish()`; nutzt dessen Hilfen (`rt()`) und `$backends`.
 */

declare(strict_types=1);

use CMS\Database;
use CMS\Services\Community\Repository\ForumRepository;

/** Zeilen von `permissionRowsMany()`/`firstPerSubject()` als `forum:typ:schlüssel` (Reihenfolge bleibt). */
function aForumRowKeys(array $rows): array
{
    return array_map(static fn (array $r): string => $r['forum_id'] . ':' . $r['subject_type'] . ':' . $r['subject_key'], $rows);
}

function aScenarioForumTreeCategory(Database $db): void
{
    /** @var CommunityTestDatabase|MariaDbTestDatabase $db */
    $visible = $db->category(['slug' => 'offen']);
    $hidden = $db->category(['slug' => 'intern', 'visible' => false]);
    $none = $db->forum(['title' => 'Ohne']);
    $inVisible = $db->forum(['title' => 'Sichtbar', 'category' => $visible]);
    $inHidden = $db->forum(['title' => 'Unsichtbar', 'category' => $hidden]);
    $missing = $db->forum(['title' => 'Fehlt', 'category' => 987654]);
    $child = $db->forum(['title' => 'Kind', 'parent' => $inHidden, 'category' => $visible]);
    $repo = new ForumRepository($db);
    $tree = $repo->tree();
    $flags = [];
    foreach ([$none, $inVisible, $inHidden, $missing, $child] as $id) {
        $flags[] = (int) $tree[$id]['category_visible'];
    }
    T::same([1, 1, 0, 0, 1], $flags, 'tree(): category_visible ohne Kategorie 1, sichtbar 1, unsichtbar 0, fehlend 0, Kind mit eigener sichtbarer Kategorie 1 (die Kette prüft CommunityAccess)');
    T::same($repo->find($inHidden)['title'], $tree[$inHidden]['title'], 'tree(): Spalten der Forenzeile bleiben (f.*)');
    T::same(array_keys(array_diff_key($tree[$inHidden], $repo->find($inHidden))), ['category_visible'], 'tree(): genau eine zusätzliche Spalte');
    $order = array_keys($tree);
    $sorted = $order;
    usort($sorted, static fn (int $a, int $b): int => [(int) $tree[$a]['category_id'], (int) $tree[$a]['depth'], (int) $tree[$a]['sort_order'], $a] <=> [(int) $tree[$b]['category_id'], (int) $tree[$b]['depth'], (int) $tree[$b]['sort_order'], $b]);
    T::same($sorted, $order, 'tree(): Reihenfolge Kategorie, Tiefe, Sortierung, ID');
    $db->execute('UPDATE ' . $db->getPrefix() . 'community_categories SET is_visible = 1 WHERE id = ?', [$hidden]);
    T::same(0, (int) $repo->tree()[$inHidden]['category_visible'], 'tree(): Zwischenspeicher bis forgetTree()');
    $repo->forgetTree();
    T::same(1, (int) $repo->tree()[$inHidden]['category_visible'], 'tree(): nach forgetTree() die neue Sichtbarkeit');
}

function aScenarioPermissionBulk(Database $db): void
{
    /** @var CommunityTestDatabase|MariaDbTestDatabase $db */
    $repo = new ForumRepository($db);
    $a = $db->forum(['title' => 'A']);
    $b = $db->forum(['title' => 'B', 'parent' => $a]);
    $c = $db->forum(['title' => 'C', 'parent' => $b]);
    $x = $db->forum(['title' => 'X']);
    $y = $db->forum(['title' => 'Y']);
    T::same([], $repo->permissionForumIds(), 'permissionForumIds(): keine Rechte-Zeilen → []');

    $repo->savePermissions($a, [
        ['subject_type' => 'guest', 'permissions' => ['read' => 1]],
        ['subject_type' => 'role', 'subject_key' => 'member', 'permissions' => ['read' => 1, 'reply' => 1]],
        ['subject_type' => 'group', 'subject_key' => '7', 'permissions' => ['read' => 1]],
        ['subject_type' => 'trust', 'subject_key' => '1', 'permissions' => ['create_topic' => 1]],
        ['subject_type' => 'trust', 'subject_key' => '3', 'permissions' => ['edit_own' => 1]],
    ], rt());
    $repo->savePermissions($b, [['subject_type' => 'role', 'subject_key' => 'member', 'permissions' => ['read' => 0]]], rt());
    $repo->savePermissions($x, [['subject_type' => 'role', 'subject_key' => 'member', 'permissions' => ['read' => 1]], ['subject_type' => 'group', 'subject_key' => '7', 'permissions' => null]], rt());
    T::same([$a, $b, $x], $repo->permissionForumIds(), 'permissionForumIds(): Foren mit Zeilen, aufsteigend, ohne Doppelte (savePermissions leert den Zwischenspeicher)');
    $db->log = [];
    $repo->permissionForumIds();
    T::same(0, count($db->log), 'permissionForumIds(): zweiter Aufruf aus dem Zwischenspeicher');
    $db->execute('INSERT INTO ' . $db->getPrefix() . 'community_forum_permissions (forum_id, subject_type, subject_key, permissions_json, updated_at) VALUES (?, ?, ?, ?, ?)', [$y, 'guest', '', '{"read":1}', rt()]);
    T::same([$a, $b, $x], $repo->permissionForumIds(), 'fremde Änderung ohne forgetTree(): Zwischenspeicher');
    $repo->forgetTree();
    T::same([$a, $b, $x, $y], $repo->permissionForumIds(), 'forgetTree() leert auch den Zwischenspeicher der Foren mit Rechte-Zeilen');
    $repo->savePermissions($y, [], rt());
    T::same([$a, $b, $x], $repo->permissionForumIds(), 'savePermissions() ohne Zeilen: das Forum fällt heraus');
    $repo->forgetTree();
    $db->log = [];
    $repo->permissionForumIds();
    T::check(count($db->log) === 1 && str_contains($db->log[0], 'DISTINCT forum_id'), 'permissionForumIds(): eine Abfrage SELECT DISTINCT forum_id', implode(' | ', $db->log));

    // permissionRowsMany: alle passenden Zeilen der Foren, Subjekte wie permissionRows()
    $keys = static fn (array $ids, string $role, array $groups, int $level): array => aForumRowKeys($repo->permissionRowsMany($ids, $role, $groups, $level));
    T::same(["{$a}:group:7", "{$a}:guest:", "{$a}:role:member", "{$a}:trust:1", "{$a}:trust:3", "{$b}:role:member", "{$x}:group:7", "{$x}:role:member"], $keys([$x, $b, $a], 'member', [7], 0), 'permissionRowsMany(): Foren aufsteigend, darin Subjekt und Schlüssel; trust-Zeilen aller Schlüssel ab Stufe 0 (die Stufe filtert firstPerSubject)');
    T::same(["{$a}:guest:"], $keys([$a, $c], '', [], -1), 'Gast (Rolle leer, Stufe -1): nur guest-Zeilen');
    T::same(["{$a}:guest:", "{$a}:trust:1", "{$a}:trust:3"], $keys([$a], 'editor', [], 2), 'andere Rolle, keine Gruppe');
    T::same(["{$a}:group:7", "{$a}:guest:"], $keys([$a], '', ['7', 7, 0, -1], -1), 'Gruppen als Text verglichen, doppelte und ungültige IDs entfallen');
    T::same([], $keys([$c, 0, -3], 'member', [7], 4), 'Forum ohne Zeilen und ungültige IDs: []');
    $db->log = [];
    T::same([], $repo->permissionRowsMany([], 'member', [7], 1), 'leere Liste: []');
    T::same(0, count($db->log), 'leere Liste: keine Abfrage');
    $row = $repo->permissionRowsMany([$x], 'member', [7], 0);
    T::same([['forum_id' => $x, 'subject_type' => 'group', 'subject_key' => '7', 'permissions_json' => null], ['forum_id' => $x, 'subject_type' => 'role', 'subject_key' => 'member', 'permissions_json' => '{"read":1}']], $row, 'Zeilenform: forum_id int, Texte, NULL bleibt NULL');
    $many = range(1, 650);
    $many[] = $a;
    $db->log = [];
    $rows = $repo->permissionRowsMany($many, 'member', [7], 0);
    T::same(2, count($db->log), '651 Foren-IDs: zwei Abfragen (Stücke zu 500)');
    T::same(8, count($rows), '651 Foren-IDs: alle Zeilen der Foren mit Zeilen');

    // firstPerSubject: je Subjekt das erste Forum der Kette
    $chain = $repo->chainIds($c);
    T::same([$c, $b, $a], $chain, 'Kette von C');
    $all = $repo->permissionRowsMany([$a, $b, $x], 'member', [7], 0);
    $first = static fn (array $chain, int $level): array => aForumRowKeys(ForumRepository::firstPerSubject($chain, $all, $level));
    T::same(["{$a}:group:7", "{$a}:guest:", "{$b}:role:member"], $first($chain, 0), 'firstPerSubject(): eigene Zeile (B) vor geerbter (A); Zeilen aus X gehören nicht zur Kette');
    T::same(["{$a}:group:7", "{$a}:guest:", "{$b}:role:member", "{$a}:trust:1"], $first($chain, 2), 'trust:1 ab Stufe 1 (Stufe 2)');
    T::same(["{$a}:group:7", "{$a}:guest:", "{$b}:role:member", "{$a}:trust:1", "{$a}:trust:3"], $first($chain, 4), 'trust:3 ab Stufe 3 (Stufe 4)');
    T::same(["{$a}:group:7", "{$a}:guest:", "{$b}:role:member"], $first($chain, -1), 'Stufe -1: keine trust-Zeile');
    T::same(["{$x}:group:7", "{$x}:role:member"], $first([$x], 0), 'firstPerSubject() für X: nur Zeilen von X');
    T::same([], $first([$c], 0), 'Kette ohne Zeilen: []');
    T::same([], ForumRepository::firstPerSubject([], $all, 0), 'leere Kette: []');
    $odd = [['forum_id' => $a, 'subject_type' => 'trust', 'subject_key' => 'x1', 'permissions_json' => '{}'], ['forum_id' => (string) $a, 'subject_type' => 'trust', 'subject_key' => '2', 'permissions_json' => null]];
    T::same(["{$a}:trust:2"], aForumRowKeys(ForumRepository::firstPerSubject([$a], $odd, 4)), 'trust-Schlüssel ohne Ziffern entfallen; forum_id als Text zählt');
    T::same($repo->permissionRows($chain, 'member', [7], 2), ForumRepository::firstPerSubject($chain, $repo->permissionRowsMany($chain, 'member', [7], 2), 2), 'permissionRows() = firstPerSubject(Kette, permissionRowsMany(Kette))');
}

function aScenarioModeratorAssignments(Database $db): void
{
    /** @var CommunityTestDatabase|MariaDbTestDatabase $db */
    $repo = new ForumRepository($db);
    $a = $db->forum(['title' => 'A']);
    $b = $db->forum(['title' => 'B', 'parent' => $a]);
    $c = $db->forum(['title' => 'C', 'parent' => $b]);
    $x = $db->forum(['title' => 'X']);
    $y = $db->forum(['title' => 'Y']);
    $repo->saveModerators($x, [['subject_type' => 'user', 'subject_id' => 7, 'include_children' => 0], ['subject_type' => 'group', 'subject_id' => 3, 'include_children' => 1]]);
    $repo->saveModerators($a, [['subject_type' => 'user', 'subject_id' => 7, 'include_children' => 1], ['subject_type' => 'user', 'subject_id' => 8, 'include_children' => 0]]);
    $repo->saveModerators($b, [['subject_type' => 'group', 'subject_id' => 4, 'include_children' => 0]]);
    $repo->saveModerators($y, [['subject_type' => 'group', 'subject_id' => 3, 'include_children' => 0], ['subject_type' => 'user', 'subject_id' => 7, 'include_children' => 1]]);

    T::same([['forum_id' => $a, 'include_children' => 1], ['forum_id' => $x, 'include_children' => 0], ['forum_id' => $y, 'include_children' => 1]], $repo->moderatorAssignments(7, []), 'moderatorAssignments(): Konto allein, aufsteigend nach Forum');
    T::same([['forum_id' => $a, 'include_children' => 1], ['forum_id' => $x, 'include_children' => 1], ['forum_id' => $y, 'include_children' => 1]], $repo->moderatorAssignments(7, [3]), 'Konto und Gruppe: je Forum eine Zeile, include_children = 1, wenn eine Zuordnung es setzt (X: Gruppe 1, Konto 0; Y: Gruppe 0, Konto 1)');
    T::same([['forum_id' => $b, 'include_children' => 0], ['forum_id' => $x, 'include_children' => 1], ['forum_id' => $y, 'include_children' => 0]], $repo->moderatorAssignments(99, [3, 4, 4, 0]), 'nur über Gruppen; doppelte und ungültige Gruppen-IDs entfallen');
    T::same([['forum_id' => $a, 'include_children' => 0]], $repo->moderatorAssignments(8, [5]), 'fremde Gruppe: nur die eigene Zeile');
    T::same([], $repo->moderatorAssignments(12345, []), 'ohne Zuordnung: []');
    $db->log = [];
    T::same([[], []], [$repo->moderatorAssignments(0, [3]), $repo->moderatorAssignments(-1, [])], 'Konto 0 oder negativ: []');
    T::same(0, count($db->log), 'Konto 0: keine Abfrage');
    $repo->moderatorAssignments(7, [3, 4]);
    T::check(count($db->log) === 1 && str_contains($db->log[0], "OR (subject_type = 'group' AND subject_id IN (?, ?))"), 'eine Abfrage, Gruppen als Platzhalter', implode(' | ', $db->log));
    T::same(['forum_id' => 'integer', 'include_children' => 'integer'], array_map('gettype', $repo->moderatorAssignments(7, [])[0]), 'Werte als int (auch aus MariaDB)');

    // moderates(): die einzige Kinderregel
    $assign = $repo->moderatorAssignments(7, []);
    $anc = static fn (int $id): array => $repo->ancestorIds($id);
    T::same([true, true, true], [ForumRepository::moderates($anc($a), $a, $assign), ForumRepository::moderates($anc($b), $b, $assign), ForumRepository::moderates($anc($c), $c, $assign)], 'Zuordnung auf A mit include_children = 1: A, B und C');
    $only = $repo->moderatorAssignments(8, []);
    T::same([true, false, false], [ForumRepository::moderates($anc($a), $a, $only), ForumRepository::moderates($anc($b), $b, $only), ForumRepository::moderates($anc($c), $c, $only)], 'include_children = 0: nur A');
    $group = $repo->moderatorAssignments(99, [4]);
    T::same([false, true, false], [ForumRepository::moderates($anc($a), $a, $group), ForumRepository::moderates($anc($b), $b, $group), ForumRepository::moderates($anc($c), $c, $group)], 'Gruppe auf B ohne Kinder: nur B');
    T::same(false, ForumRepository::moderates($anc($x), $c, $assign), 'Vorfahren eines anderen Zweigs: false');
    T::same(false, ForumRepository::moderates($anc($c), $c, []), 'ohne Zuordnungen: false');
    T::same(false, ForumRepository::moderates([$c, $b, $a], $c, [['forum_id' => $a, 'include_children' => '1']]), 'include_children muss die Zahl 1 sein (fail-closed)');
    T::same(true, ForumRepository::moderates([$c], $c, [['forum_id' => $c, 'include_children' => 0]]), 'Zuordnung auf dem Forum selbst gilt immer');
}

function aScenarioForumTouch(Database $db): void
{
    /** @var CommunityTestDatabase|MariaDbTestDatabase $db */
    $repo = new ForumRepository($db);
    $ids = [];
    for ($i = 0; $i < 4; $i++) {
        $ids[] = $db->forum(['title' => 'T' . $i]);
    }
    $version = static fn (int $id): array => [(int) $db->row('community_forums', ['id' => $id])['version'], $db->row('community_forums', ['id' => $id])['updated_at']];
    $before = $version($ids[3]);
    $db->log = [];
    $repo->touch([$ids[2], $ids[0], $ids[2], 0, -5], rt(77));
    T::same(1, count($db->log), 'touch(): eine Anweisung');
    T::same([rt(77), $ids[0], $ids[2]], $db->paramsAt(0), 'touch(): IDs aufsteigend gebunden');
    T::same([[2, rt(77)], [1, $before[1]], [2, rt(77)], $before], [$version($ids[0]), $version($ids[1]), $version($ids[2]), $version($ids[3])], 'touch(): version + 1 und updated_at nur für die genannten Foren, doppelte und ungültige IDs entfallen');
    $db->log = [];
    $repo->touch([], rt());
    $repo->touch([0, -1], rt());
    T::same(0, count($db->log), 'touch(): ohne gültige ID keine Anweisung');
    $more = [];
    for ($i = 0; $i < 150; $i++) {
        $more[] = $db->forum(['title' => 'M' . $i]);
    }
    $db->log = [];
    $repo->touch(array_reverse($more), rt(5));
    T::same(2, count($db->log), 'touch(): 150 IDs in zwei Stücken zu höchstens 100');
    T::same($more[0], $db->paramsAt(0)[1] ?? null, 'touch(): das erste Stück beginnt mit der kleinsten ID');
    T::same(2, (int) $db->row('community_forums', ['id' => $more[149]])['version'], 'touch(): auch das zweite Stück wirkt');
}

$aScenarios = [
    'Foren (PR #2b): tree() mit category_visible' => 'aScenarioForumTreeCategory',
    'Foren (PR #2b): Mengenabfragen der Rechte (permissionForumIds, permissionRowsMany, firstPerSubject)' => 'aScenarioPermissionBulk',
    'Foren (PR #2b): moderatorAssignments() und moderates()' => 'aScenarioModeratorAssignments',
    'Foren (PR #2b): touch()' => 'aScenarioForumTouch',
];
// Vertrag §3.7: `moderates()` ist die einzige Stelle der Regel `include_children`. Kein SQL-Text des Repositorys vergleicht die Spalte
// (vorher: `AND (forum_id = ? OR include_children = 1)` in `moderatorRows()`, eine zweite Kopie für `can()`).
T::run('Foren (PR #2b): include_children nur in moderates(), in keiner SQL-Bedingung', static function (): void {
    $compared = [];
    foreach (token_get_all((string) file_get_contents(ABSPATH . 'core/Services/Community/Repository/ForumRepository.php')) as $token) {
        if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING && preg_match('/include_children\s*(?:[=<>!]|IN\b|IS\b)/i', $token[1]) === 1) {
            $compared[] = $token[1];
        }
    }
    T::same([], $compared, 'keine SQL-Bedingung auf include_children in ForumRepository.php');
});
foreach ($backends as $label => $make) {
    foreach ($aScenarios as $title => $function) {
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
