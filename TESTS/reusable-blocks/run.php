<?php
/**
 * EditorJS Block Vorlagen (4.1.0, Bereich 3b): Blockdaten (nur `id`), Nutzungsindex, Rendern mit aktuellem Inhalt
 * und Sprache, Rekursionsschutz (direkt, über zwei Ebenen, Tiefe), gelöschte Vorlage, Verlauf (20 Fassungen),
 * Duplizieren, Löschwarnung, Aktualisieren verknüpfter Inhalte, Umwandeln in normale Blöcke, Suchtext sowie
 * Quelltext-Prüfungen der Einbindung (Registry, Editor-Skript, API, Schema, Menü, Rechte, CSP).
 * Ohne Datenbank: Der Dienst läuft gegen eine SQLite-Attrappe im Speicher.
 *
 * Aufruf aus dem Repository-Wurzelverzeichnis: php TESTS/reusable-blocks/run.php [-v]
 */

declare(strict_types=1);

define('ABSPATH', dirname(__DIR__, 2) . '/CMS/');
define('CMS_DEBUG', false);
define('LOG_LEVEL', 'emergency');
define('LOG_PATH', sys_get_temp_dir() . '/cms-tests-logs/');
define('SITE_URL', 'https://cms.test');

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'CMS\\')) {
        return;
    }
    $file = ABSPATH . 'core/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

require_once dirname(__DIR__) . '/lib/TestRunner.php';

use CMS\Database;
use CMS\Services\ReusableBlocks\ReusableBlockService as S;
use CMS\Services\SiteSearchService;

T::$verbose = in_array('-v', $argv ?? [], true);

function rbSource(string $relative): string
{
    return (string) file_get_contents(ABSPATH . $relative);
}

function rbDatabase(): Database
{
    return new class () extends Database {
        public PDO $sqlite;

        public function __construct()
        {
            $this->sqlite = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $this->sqlite->exec("CREATE TABLE cms_users (id INTEGER PRIMARY KEY, username TEXT, display_name TEXT)");
            $this->sqlite->exec("CREATE TABLE cms_posts (id INTEGER PRIMARY KEY, title TEXT, content TEXT, content_en TEXT)");
            $this->sqlite->exec("CREATE TABLE cms_pages (id INTEGER PRIMARY KEY, title TEXT, content TEXT, content_en TEXT)");
            $this->sqlite->exec("CREATE TABLE cms_reusable_blocks (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT NOT NULL, content TEXT NOT NULL, content_en TEXT,
                status TEXT NOT NULL DEFAULT 'active', created_by INTEGER, updated_by INTEGER, created_at TEXT, updated_at TEXT)");
            $this->sqlite->exec('CREATE TABLE cms_reusable_block_usage (block_id INTEGER, content_type TEXT, content_id INTEGER, PRIMARY KEY (block_id, content_type, content_id))');
            $this->sqlite->exec("CREATE TABLE cms_reusable_block_revisions (id INTEGER PRIMARY KEY AUTOINCREMENT, block_id INTEGER, title TEXT DEFAULT '', content TEXT,
                content_en TEXT, created_by INTEGER, created_at TEXT)");
        }

        public function execute(string $sql, array $params = []): \PDOStatement
        {
            $stmt = $this->sqlite->prepare($sql);
            $stmt->execute(array_values($params));

            return $stmt;
        }

        public function get_var(string $query, array $params = []): mixed
        {
            $value = $this->execute($query, $params)->fetchColumn();

            return $value === false ? null : $value;
        }

        public function get_results(string $query, array $params = []): array
        {
            return $this->execute($query, $params)->fetchAll(PDO::FETCH_OBJ);
        }

        public function get_row(string $query, array $params = []): ?object
        {
            return $this->execute($query, $params)->fetch(PDO::FETCH_OBJ) ?: null;
        }

        public function lastInsertId(): int
        {
            return (int) $this->sqlite->lastInsertId();
        }

        public function getPrefix(): string
        {
            return 'cms_';
        }
    };
}

/** Editor.js-JSON aus Absätzen und Vorlagen-Verweisen: ['Text', 12, …] */
function rbDoc(array $items): string
{
    $blocks = [];
    foreach ($items as $item) {
        $blocks[] = is_int($item)
            ? ['type' => 'reusableBlock', 'data' => ['id' => $item]]
            : ['id' => 'b' . count($blocks), 'type' => 'paragraph', 'data' => ['text' => $item]];
    }

    return (string) json_encode(['blocks' => $blocks], JSON_UNESCAPED_UNICODE);
}

/**
 * @return array{db: Database, service: S, changed: \ArrayObject, sanitized: \ArrayObject, clock: \ArrayObject}
 */
function rbFixture(): array
{
    $db = rbDatabase();
    $db->execute("INSERT INTO cms_users (id, username, display_name) VALUES (1, 'anna', 'Anna')");
    $changed = new \ArrayObject();
    $sanitized = new \ArrayObject();
    $clock = new \ArrayObject(['now' => strtotime('2026-11-15 10:00:00')]);
    $holder = new \stdClass();
    // Mini-Renderer: Absätze als <p>, Vorlagen-Verweise über den Dienst selbst (wie EditorJsRenderer → Registry).
    $renderer = static function (string $json) use ($holder): string {
        $data = json_decode($json, true);
        $html = '';
        foreach ((array) ($data['blocks'] ?? []) as $block) {
            if (($block['type'] ?? '') === 'reusableBlock') {
                $html .= $holder->service->render((int) ($block['data']['id'] ?? 0), $holder->locale, $holder->mode);
            } elseif (($block['type'] ?? '') === 'paragraph') {
                $html .= '<p>' . htmlspecialchars((string) $block['data']['text'], ENT_QUOTES, 'UTF-8') . '</p>';
            }
        }

        return $html;
    };
    $holder->locale = 'de';
    $holder->mode = 'frontend';
    $service = new S(
        $db,
        static fn (): int => (int) $clock['now'],
        static function (string $json) use ($sanitized): string {
            $sanitized[] = $json;

            return $json;
        },
        $renderer,
        static function (int $blockId, array $linked) use ($changed): void {
            $changed[] = ['block' => $blockId, 'linked' => $linked];
        }
    );
    $holder->service = $service;

    return ['db' => $db, 'service' => $service, 'changed' => $changed, 'sanitized' => $sanitized, 'clock' => $clock, 'holder' => $holder];
}

T::run('Blockdaten und Verweise', static function (): void {
    T::same(['id' => 12], S::sanitizeBlockData(['id' => 12, 'html' => '<script>x</script>', 'title' => 'x']), 'nur die ID bleibt erhalten');
    T::same(['id' => 7], S::sanitizeBlockData(['id' => '7']), 'ID als Ziffernfolge');
    T::same(['id' => 0], S::sanitizeBlockData(['id' => '7 OR 1=1']), 'keine Ausdrücke');
    T::same(['id' => 0], S::sanitizeBlockData(['id' => -3]), 'keine negativen IDs');
    T::same(['id' => 0], S::sanitizeBlockData(['id' => ['x']]), 'keine Arrays');
    T::same([3, 5], S::extractBlockIds(rbDoc(['a', 3, 'b', 5, 3])), 'Verweise ohne Doppelte');
    $nested = json_encode(['blocks' => [['type' => 'columns', 'data' => ['cols' => [json_encode(['blocks' => [['type' => 'reusableBlock', 'data' => ['id' => 9]]]])]]]]]);
    T::same([9], S::extractBlockIds((string) $nested), 'auch in verschachtelten Blöcken (JSON-Zeichenkette)');
    T::same([], S::extractBlockIds('<p>HTML</p>'), 'HTML-Inhalt: keine Verweise');
    T::same([], S::extractBlockIds(rbDoc([0, 'x'])), 'ID 0 zählt nicht');
});

T::run('Anlegen, Rendern, Sprache', static function (): void {
    $f = rbFixture();
    $s = $f['service'];
    T::check(empty($s->save(0, '   ', rbDoc(['x']), null, 1)['success']), 'Titel ist Pflicht');
    $a = $s->save(0, 'Kontakt-Box', rbDoc(['Ruf uns an']), rbDoc(['Call us']), 1);
    T::check(!empty($a['success']) && (int) $a['id'] > 0, 'Vorlage angelegt');
    $id = (int) $a['id'];
    T::same(2, count($f['sanitized']), 'beide Sprachfassungen laufen durch den Sanitizer');
    T::same('<div class="cms-reusable-block" data-reusable-block="' . $id . '"><p>Ruf uns an</p></div>', $s->render($id, 'de'), 'Rendern DE');
    T::same('<div class="cms-reusable-block" data-reusable-block="' . $id . '"><p>Call us</p></div>', $s->render($id, 'en'), 'Rendern EN');

    $s->save($id, 'Kontakt-Box', rbDoc(['Neue Nummer']), null, 1);
    T::check(str_contains($s->render($id, 'de'), 'Neue Nummer'), 'Rendern zeigt immer den aktuellen Stand');
    T::check(str_contains($s->render($id, 'en'), 'Neue Nummer'), 'ohne EN-Fassung: Rückfall auf DE');
    T::same(null, $s->find($id)['content_en'], 'leere EN-Fassung wird als NULL gespeichert');

    T::same('', $s->render(999, 'de'), 'gelöschte/unbekannte Vorlage: im Frontend nichts');
    T::check(str_contains($s->render(999, 'de', 'preview'), 'existiert nicht mehr'), 'gelöschte Vorlage: Hinweis in der Editor-Vorschau');
    T::check(str_contains($s->render(0, 'de', 'preview'), 'auswählen'), 'ohne Auswahl: Hinweis in der Vorschau');
    $empty = $s->save(0, 'Leer', rbDoc([]), null, 1);
    T::same('', $s->render((int) $empty['id'], 'de'), 'leere Vorlage: nichts');
});

T::run('Rekursionsschutz', static function (): void {
    $f = rbFixture();
    $s = $f['service'];
    $a = (int) $s->save(0, 'A', rbDoc(['A-Text']), null, 1)['id'];
    $b = (int) $s->save(0, 'B', rbDoc(['B-Text', $a]), null, 1)['id'];
    $self = $s->save($a, 'A', rbDoc(['A-Text', $a]), null, 1);
    T::check(empty($self['success']), 'direkte Selbsteinbindung wird beim Speichern abgelehnt');
    T::same('Eine Vorlage kann sich nicht selbst einbinden.', (string) ($self['error'] ?? ''), 'mit eigener, klarer Meldung');
    $cycle = $s->save($a, 'A', rbDoc(['A-Text', $b]), null, 1);
    T::check(empty($cycle['success']) && str_contains((string) $cycle['error'], 'eingebundene'), 'Kreis über zwei Ebenen (A → B → A) wird abgelehnt');
    $cycleEn = $s->save($a, 'A', rbDoc(['A-Text']), rbDoc([$b]), 1);
    T::check(empty($cycleEn['success']), 'auch über die EN-Fassung');
    $c1 = (int) $s->save(0, 'C1', rbDoc(['c1']), null, 1)['id'];
    $c2 = (int) $s->save(0, 'C2', rbDoc(['c2']), rbDoc([$c1]), 1)['id'];
    T::check(empty($s->save($c1, 'C1', rbDoc([$c2]), null, 1)['success']), 'auch wenn der Kreis über die EN-Fassung der anderen Vorlage läuft');
    T::check(str_contains($s->render($b, 'de'), 'A-Text') && str_contains($s->render($b, 'de'), 'B-Text'), 'erlaubte Verschachtelung rendert beide');

    // Kreis direkt in der Datenbank (z. B. Import) – das Rendern muss trotzdem enden.
    $f['db']->execute('UPDATE cms_reusable_blocks SET content = ? WHERE id = ?', [rbDoc(['A-Text', $b]), $a]);
    $html = $s->render($a, 'de');
    T::same(1, substr_count($html, 'A-Text'), 'Kreis A → B → A: A erscheint nur einmal');
    T::same(1, substr_count($html, 'B-Text'), 'Kreis A → B → A: B erscheint einmal');
    $f['holder']->mode = 'preview';
    T::check(str_contains($s->render($a, 'de', 'preview'), 'bindet sich selbst ein'), 'Vorschau nennt die Rekursion');
    $f['holder']->mode = 'frontend';

    // Tiefe: D → C → B2 → A2 (4 Ebenen) – höchstens 3 werden gerendert.
    $a2 = (int) $s->save(0, 'A2', rbDoc(['Ebene4']), null, 1)['id'];
    $b2 = (int) $s->save(0, 'B2', rbDoc(['Ebene3', $a2]), null, 1)['id'];
    $c = (int) $s->save(0, 'C', rbDoc(['Ebene2', $b2]), null, 1)['id'];
    $d = (int) $s->save(0, 'D', rbDoc(['Ebene1', $c]), null, 1)['id'];
    $deep = $s->render($d, 'de');
    T::check(str_contains($deep, 'Ebene3') && !str_contains($deep, 'Ebene4'), 'mehr als ' . S::MAX_DEPTH . ' Ebenen werden abgeschnitten');
    T::same('', $s->render($a, 'de') === '' ? 'x' : '', 'nach einem Rekursionsabbruch ist der Stapel wieder leer (Folge-Rendern klappt)');
    T::check(S::createsCycle($a, [$b], static fn (int $id): string => $id === $b ? rbDoc([$a]) : ''), 'createsCycle erkennt den Kreis');
    T::check(!S::createsCycle($a, [$b], static fn (int $id): string => ''), 'createsCycle: kein Kreis');
});

T::run('Nutzungsindex, Verwendet in, verknüpfte Inhalte', static function (): void {
    $f = rbFixture();
    $s = $f['service'];
    $inner = (int) $s->save(0, 'Innen', rbDoc(['innen']), null, 1)['id'];
    $outer = (int) $s->save(0, 'Außen', rbDoc(['außen', $inner]), null, 1)['id'];
    $f['db']->execute("INSERT INTO cms_posts (id, title, content) VALUES (10, 'Beitrag mit Vorlage', ?)", [rbDoc(['x', $outer])]);
    $f['db']->execute("INSERT INTO cms_pages (id, title, content) VALUES (20, 'Seite', ?)", [rbDoc([$inner])]);
    $s->syncUsage('post', 10, [rbDoc(['x', $outer]), '']);
    $s->syncUsage('page', 20, [rbDoc([$inner]), rbDoc([$inner])]);

    $usages = $s->usages($inner);
    T::same(['page:20', 'reusable_block:' . $outer], array_map(static fn (array $u): string => $u['type'] . ':' . $u['id'], $usages), 'Verwendet in: Seite und die äußere Vorlage');
    T::same('Seite', $usages[0]['title'], 'Titel der Seite');
    T::same('/admin/pages?action=edit&id=20', $usages[0]['edit_url'], 'Link zum Editor');
    $linked = array_map(static fn (array $u): string => $u['type'] . ':' . $u['id'], $s->linkedContent($inner));
    sort($linked);
    T::same(['page:20', 'post:10'], $linked, 'verknüpfte Inhalte auch über verschachtelte Vorlagen');
    T::same(2, $s->all()[array_search('Innen', array_column($s->all(), 'title'), true)]['usage_count'], 'Liste zählt Verwendungen');

    $s->save($inner, 'Innen', rbDoc(['innen neu']), null, 1);
    $last = $f['changed'][count($f['changed']) - 1];
    $notified = array_map(static fn (array $u): string => $u['type'] . ':' . $u['id'], $last['linked']);
    sort($notified);
    T::same(['page:20', 'post:10'], $notified, 'Änderung aktualisiert alle verknüpften Inhalte (Cache, Suchindex)');

    $s->syncUsage('post', 10, [rbDoc(['ohne Vorlage'])]);
    T::same([], array_values(array_filter($s->usages($outer), static fn (array $u): bool => $u['type'] === 'post')), 'Speichern ohne Verweis entfernt die Verwendung');
    $s->removeUsage('page', 20);
    T::same(['reusable_block:' . $outer], array_map(static fn (array $u): string => $u['type'] . ':' . $u['id'], $s->usages($inner)), 'Löschen des Inhalts entfernt die Verwendung');
});

T::run('Löschen mit Warnung', static function (): void {
    $f = rbFixture();
    $s = $f['service'];
    $id = (int) $s->save(0, 'Banner', rbDoc(['Banner']), null, 1)['id'];
    $s->save($id, 'Banner', rbDoc(['Banner 2']), null, 1);
    $s->syncUsage('post', 10, [rbDoc([$id])]);
    $warn = $s->delete($id, false);
    T::check(empty($warn['success']) && !empty($warn['needs_confirmation']) && count($warn['usages']) === 1, 'verwendete Vorlage: Löschen braucht Bestätigung und nennt die Stellen');
    T::check($s->find($id) !== null, 'ohne Bestätigung bleibt die Vorlage');
    $deleted = $s->delete($id, true);
    T::check(!empty($deleted['success']), 'mit Bestätigung gelöscht');
    T::same(null, $s->find($id), 'Vorlage ist weg');
    T::same(0, (int) $f['db']->get_var('SELECT COUNT(*) FROM cms_reusable_block_revisions WHERE block_id = ?', [$id]), 'Verlauf ist weg');
    T::same(0, (int) $f['db']->get_var('SELECT COUNT(*) FROM cms_reusable_block_usage WHERE block_id = ?', [$id]), 'Nutzungseinträge sind weg');
    T::same([['type' => 'post', 'id' => 10]], $f['changed'][count($f['changed']) - 1]['linked'], 'betroffene Inhalte werden aktualisiert');
    T::same('', $s->render($id, 'de'), 'Inhalte mit dem Verweis zeigen danach nichts');
    $unused = (int) $s->save(0, 'Frei', rbDoc(['x']), null, 1)['id'];
    T::check(!empty($s->delete($unused, false)['success']), 'unbenutzte Vorlage ohne Rückfrage löschbar (die Oberfläche fragt trotzdem)');
});

T::run('Verlauf, Wiederherstellen, Duplizieren', static function (): void {
    $f = rbFixture();
    $s = $f['service'];
    $id = (int) $s->save(0, 'V', rbDoc(['Fassung 0']), null, 1)['id'];
    T::same([], $s->revisions($id), 'neue Vorlage: noch kein Verlauf');
    T::same('Keine Änderungen.', $s->save($id, 'V', rbDoc(['Fassung 0']), null, 1)['message'], 'unverändertes Speichern legt keine Fassung an');
    for ($i = 1; $i <= 25; $i++) {
        $f['clock']['now'] += 60;
        $s->save($id, 'V', rbDoc(['Fassung ' . $i]), null, 1);
    }
    T::same(S::REVISION_LIMIT, (int) $f['db']->get_var('SELECT COUNT(*) FROM cms_reusable_block_revisions WHERE block_id = ?', [$id]), 'höchstens 20 Fassungen');
    $revisions = $s->revisions($id);
    T::same('Anna', $revisions[0]['created_by_name'], 'Fassung nennt die bearbeitende Person');
    $newest = (string) $f['db']->get_var('SELECT content FROM cms_reusable_block_revisions WHERE id = ?', [$revisions[0]['id']]);
    T::check(str_contains($newest, 'Fassung 24'), 'neueste Fassung ist der Stand vor dem letzten Speichern');
    $oldestKept = (string) $f['db']->get_var('SELECT content FROM cms_reusable_block_revisions WHERE block_id = ? ORDER BY id ASC LIMIT 1', [$id]);
    T::check(str_contains($oldestKept, 'Fassung 5'), 'die ältesten Fassungen werden entfernt');
    $restore = $s->restoreRevision($id, $revisions[3]['id'], 1);
    T::check(!empty($restore['success']) && str_contains($s->render($id), 'Fassung 21'), 'Wiederherstellen übernimmt die Fassung');
    T::check(empty($s->restoreRevision($id, 999999, 1)['success']), 'unbekannte Fassung');

    $copy = $s->duplicate($id, 1);
    T::check(!empty($copy['success']) && (int) $copy['id'] !== $id, 'Duplizieren legt eine neue Vorlage an');
    T::same('V (Kopie)', $s->find((int) $copy['id'])['title'], 'Titel der Kopie');
    T::same($s->find($id)['content'], $s->find((int) $copy['id'])['content'], 'Kopie hat denselben Inhalt');
});

T::run('Umwandeln in normale Blöcke, Suchtext', static function (): void {
    $f = rbFixture();
    $s = $f['service'];
    $inner = (int) $s->save(0, 'Innen', rbDoc(['Innentext']), rbDoc(['Inner text']), 1)['id'];
    $id = (int) $s->save(0, 'Außen', rbDoc(['Außentext', $inner]), null, 1)['id'];
    $blocks = $s->blocksForConversion($id, 'de');
    T::same(['paragraph', 'reusableBlock'], array_column($blocks, 'type'), 'Blöcke der Vorlage zum Einfügen');
    T::check(!array_key_exists('id', $blocks[0]), 'ohne Editor.js-Block-IDs (Editor vergibt neue)');
    T::same('Inner text', $s->blocksForConversion($inner, 'en')[0]['data']['text'], 'Umwandeln in der Sprache des Editors');
    T::same([], $s->blocksForConversion(999), 'unbekannte Vorlage: nichts');

    SiteSearchService::registerBlockTextResolver('reusableBlock', static fn (array $data, string $locale): string => $s->searchContent($data, $locale));
    $postText = SiteSearchService::plainText(rbDoc(['Beitrag', $id]));
    T::check(str_contains($postText, 'Außentext') && str_contains($postText, 'Innentext'), 'Suchtext enthält die Vorlage aufgelöst (auch verschachtelt)');
    T::check(str_contains(SiteSearchService::plainText(rbDoc([$inner]), 'en'), 'Inner text'), 'Suchtext in der Sprache des Index');
    $f['db']->execute('UPDATE cms_reusable_blocks SET content = ? WHERE id = ?', [rbDoc(['Innentext', $id]), $inner]);
    T::same(1, substr_count(SiteSearchService::plainText(rbDoc([$id])), 'Außentext'), 'Suchtext: Kreis bricht ab');
});

T::run('Einbindung (Quelltext)', static function (): void {
    $hooks = rbSource('core/Services/FeatureHooks/ReusableBlockHooks.php');
    T::check(str_contains($hooks, 'EditorJsBlockRegistry::register(') && str_contains($hooks, "'Vorlagen'"), 'Block „Vorlagen“ in der Registry (Editor, Sanitizer, Renderer)');
    T::check(str_contains($hooks, "addAction('cms_after_post_save'") && str_contains($hooks, "addAction('cms_after_page_save'"), 'Nutzungsindex nach dem Speichern');
    T::check(str_contains($hooks, "addAction('post_deleted'") && str_contains($hooks, "addAction('page_deleted'"), 'Nutzungsindex nach dem Löschen');
    T::check(str_contains($hooks, 'registerBlockTextResolver('), 'Suchtext-Auflösung registriert');
    T::check(str_contains(rbSource('core/Services/FeatureHooks/Registry.php'), 'ReusableBlockHooks::class'), 'Hooks registriert');
    T::check(str_contains(rbSource('core/Services/FeatureHooks/MediaFeatureHooks.php'), "'cms_reusable_block_saved' => ['reusable_block', false]"), 'Medien-Verwendung der Vorlagen');

    $script = 'assets/js/editor-blocks/reusable-block.js';
    T::check(is_file(ABSPATH . $script), 'Editor-Skript vorhanden');
    $js = rbSource($script);
    T::check(str_contains($js, "name: 'reusableBlock'") && str_contains($js, "title: 'Vorlagen'"), 'Werkzeug meldet sich als „Vorlagen“ an');
    T::check(str_contains($js, 'return { id: this.data.id };'), 'Editor speichert nur die ID');
    T::same(\CMS\Services\ReusableBlocks\ReusableBlock::SCRIPT, 'js/editor-blocks/reusable-block.js', 'Skriptpfad des Blocks');

    $api = rbSource('admin/post-editor-api.php');
    T::check(str_contains($api, "'reusable_block_choices'") && str_contains($api, "'reusable_block_content'"), 'Editor-API: Auswahl und Umwandeln');
    $schema = \CMS\SchemaManager::getSchemaQueries('cms_', 'utf8mb4');
    foreach (['reusable_blocks', 'reusable_block_usage', 'reusable_block_revisions'] as $table) {
        T::check(in_array($table, \CMS\SchemaManager::FEATURE_TABLES_V26, true) && isset($schema[$table]), 'Tabelle ' . $table . ' in Schema v26');
    }
    T::check(str_contains((string) $schema['reusable_block_usage'], 'PRIMARY KEY (block_id, content_type, content_id)'), 'Nutzungsindex: eine Zeile je Vorlage und Inhalt');
    T::check(str_contains(rbSource('admin/modules/users/RolesModule.php'), "'blocks.reusable'"), 'Recht blocks.reusable in der Rechteliste');
    T::check(str_contains(rbSource('core/Services/AdminMenu/CoreCatalog.php'), "'label' => 'EditorJS Block Vorlagen', 'route' => '/admin/reusable-blocks'"), 'Menüpunkt „EditorJS Block Vorlagen“');
    T::check(str_contains(rbSource('admin/reusable-blocks.php'), "CMS_ADMIN_REUSABLE_BLOCKS_CAPABILITY = 'blocks.reusable'"), 'Verwaltung verlangt blocks.reusable');

    $views = rbSource('admin/views/reusable-blocks/index.php') . rbSource('admin/views/reusable-blocks/list.php') . rbSource('admin/views/reusable-blocks/edit.php');
    T::check(!preg_match('/<script|\son[a-z]+=/i', $views), 'Ansichten ohne Inline-Skripte und Inline-Handler (CSP)');
    T::same(substr_count($views, '<form method="post"'), substr_count($views, 'name="csrf_token"'), 'jedes POST-Formular trägt ein CSRF-Token');
    T::check(str_contains(rbSource('core/Services/Search/AbstractTableSearchProvider.php'), "localizedColumns('content', \$locale)), \$locale);"), 'Suchindex übergibt die Sprache');
});

T::finish();
