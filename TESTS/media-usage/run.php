<?php
/**
 * Verwendungsnachweis für Medien (Release 4.1.0, Bereich 2b).
 *
 * Ohne Datenbank: `MediaUsageIndex` mit In-Memory-Speicher und Test-Extraktoren, `MediaPathNormalizer` mit eigener
 * Existenzprüfung. Geprüft werden die Extraktion (absolute/relative URLs, Unterverzeichnis, geschützte Auslieferung,
 * srcset, CSS, JSON-Escapes, Derivat-Namen), Kontexte aus Editor.js-Blöcken, Neuindizieren nach Speichern/Löschen,
 * „ungenutzt“, das Ausgabeformat für Mediathek und Löschwarnung, keine Treffer für fremde Domains, der Neuaufbau in
 * Schritten mit Bereinigung und – statisch – die Einbindung (Hooks, Schema, Mediathek, Papierkorb, Medien-Werkzeuge).
 *
 * Aufruf: php TESTS/media-usage/run.php [-v]
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/TestRunner.php';
T::$verbose = in_array('-v', $argv ?? [], true);

$root = dirname(__DIR__, 2) . '/CMS/';
if (!defined('ABSPATH')) {
    define('ABSPATH', $root);
}
if (!class_exists('CMS\\Hooks')) {
    eval('namespace CMS; final class Hooks {
        public static function applyFilters(string $tag, $value, ...$args): mixed { return $value; }
        public static function doAction(string $tag, ...$args): void {}
    }');
}
spl_autoload_register(static function (string $class) use ($root): void {
    if (str_starts_with($class, 'CMS\\')) {
        $file = $root . 'core/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    }
});

use CMS\Services\Media\MediaPathNormalizer as Normalizer;
use CMS\Services\Media\MediaReferenceExtractor;
use CMS\Services\Media\MediaUsageIndex;
use CMS\Services\Media\MediaUsageStoreInterface;

/** Dateien, die es in uploads/ „gibt“. */
$files = [
    '2026/bild.jpg', '2026/bild-small.jpg', '2026/bild.webp', '2026/bild.avif',
    '2026/foto.png', '2026/team-small.jpg', '2026/logo.webp', '2026/logo-small.webp',
    'docs/handbuch.pdf', 'member/7/privat.jpg',
];
$normalizer = new Normalizer(
    ['cms.example.test', 'www.cms.example.test'],
    ['uploads', 'cms/uploads'],
    ['media-file', 'cms/media-file'],
    static fn (string $path): bool => in_array($path, $files, true)
);

T::run('Extraktion: URLs, Pfade und Formate', static function () use ($normalizer): void {
    $one = static fn (mixed $value): array => $normalizer->extract($value);
    T::same(['2026/bild.jpg'], $one('<img src="https://cms.example.test/uploads/2026/bild.jpg">'), 'absolute URL der eigenen Domain');
    T::same(['2026/bild.jpg'], $one('<img src="/uploads/2026/bild.jpg" alt="">'), 'relative URL');
    T::same(['2026/bild.jpg'], $one('https://www.cms.example.test/cms/uploads/2026/bild.jpg?v=2#x'), 'Unterverzeichnis, weitere Domain, Query/Fragment');
    T::same(['2026/bild.jpg'], $one('//cms.example.test/uploads/2026/bild.jpg'), 'protokollrelativ');
    T::same(['member/7/privat.jpg'], $one('/media-file?path=member%2F7%2Fprivat.jpg&disposition=inline'), 'geschützte Auslieferung /media-file?path=');
    T::same(['2026/bild.jpg', '2026/foto.png'], $one('<img srcset="/uploads/2026/bild-small.jpg 300w, /uploads/2026/foto.png 2x">'), 'srcset (Größe → Original)');
    T::same(['2026/foto.png'], $one('<div style="background:url(\'/uploads/2026/foto.png\')">'), 'CSS url()');
    T::same(['2026/bild.jpg'], $one('{"blocks":[{"type":"image","data":{"file":{"url":"https:\/\/cms.example.test\/uploads\/2026\/bild.jpg"}}}]}'), 'JSON mit \\/-Escapes');
    T::same(['2026/bild.jpg'], $one('&lt;img src=&quot;/uploads/2026/bild.jpg&quot;&gt;'), 'HTML-Entities');
    T::same(['docs/handbuch.pdf'], $one(['a' => ['b' => (object) ['href' => '/uploads/docs/handbuch.pdf']]]), 'verschachtelte Arrays/Objekte, Nicht-Bilder');
    T::same(['2026/bild.jpg'], $one('uploads/2026/bild.jpg'), 'nackter Pfad „uploads/…“');
});

T::run('Extraktion: keine fremden Treffer', static function () use ($normalizer): void {
    $one = static fn (mixed $value): array => $normalizer->extract($value);
    T::same([], $one('<img src="https://fremd.example/uploads/2026/bild.jpg">'), 'fremde Domain');
    T::same([], $one('https://cms.example.test.evil.example/uploads/2026/bild.jpg'), 'Domain mit gleichem Anfang');
    T::same([], $one('//fremd.example/uploads/2026/bild.jpg'), 'fremde Domain protokollrelativ');
    T::same([], $one('javascript:alert(1)//uploads/2026/bild.jpg'), 'fremdes Schema');
    T::same([], $one('/uploads/../config/app.php'), 'Pfad mit ..');
    T::same([], $one('/assets/images/logo.png'), 'kein Upload-Pfad');
    T::same([], $one('2026/bild.jpg text/html'), 'nackte Pfade ohne uploads/ zählen nicht');
    T::same([], $one(''), 'leerer Wert');
});

T::run('Derivate → Original', static function () use ($normalizer): void {
    T::same('2026/bild.jpg', $normalizer->toOriginal('2026/bild-small.jpg'), '-small → Original');
    T::same('2026/bild.jpg', $normalizer->toOriginal('2026/bild-300x200.jpg'), '-300x200 → Original');
    T::same('2026/bild.jpg', $normalizer->toOriginal('2026/bild.webp'), 'WebP-Fassung → Original');
    T::same('2026/bild.jpg', $normalizer->toOriginal('2026/bild.avif'), 'AVIF-Fassung → Original');
    T::same('2026/bild.jpg', $normalizer->toOriginal('2026/bild-small.avif'), 'AVIF einer Größe → Original');
    T::same('2026/team-small.jpg', $normalizer->toOriginal('2026/team-small.jpg'), 'ohne vorhandenes Original bleibt die Datei selbst ein Original');
    T::same('2026/logo.webp', $normalizer->toOriginal('2026/logo.webp'), 'WebP-Upload ohne JPG bleibt');
    T::same('2026/logo.webp', $normalizer->toOriginal('2026/logo-small.webp'), 'Größe eines WebP-Originals → WebP-Original');
    T::same(['2026/bild-small.jpg', '2026/bild.jpg'], $normalizer->lookupPaths('2026/bild-small.jpg'), 'Abfrage einer Größe schließt das Original ein');
    T::same(['2026/bild.jpg'], $normalizer->lookupPaths('2026/bild.jpg'), 'Abfrage eines Originals');
});

final class MemoryUsageStore implements MediaUsageStoreInterface
{
    /** @var array<string, array{media_path:string, content_type:string, content_id:int, context:string, updated_at:string}> */
    public array $rows = [];

    public function replace(string $contentType, int $contentId, array $references, string $now): void
    {
        $this->delete($contentType, $contentId);
        foreach ($references as $reference) {
            $this->rows[$reference['path'] . '|' . $contentType . '|' . $contentId . '|' . $reference['context']] = [
                'media_path' => $reference['path'], 'content_type' => $contentType, 'content_id' => $contentId,
                'context' => $reference['context'], 'updated_at' => $now,
            ];
        }
    }

    public function delete(string $contentType, int $contentId): void
    {
        $this->rows = array_filter($this->rows, static fn (array $row): bool => !($row['content_type'] === $contentType && $row['content_id'] === $contentId));
    }

    public function rowsForPaths(array $paths): array
    {
        return array_values(array_map(
            static fn (array $row): array => array_diff_key($row, ['updated_at' => true]),
            array_filter($this->rows, static fn (array $row): bool => in_array($row['media_path'], $paths, true))
        ));
    }

    public function pruneBefore(string $before): int
    {
        $count = count($this->rows);
        $this->rows = array_filter($this->rows, static fn (array $row): bool => $row['updated_at'] >= $before);

        return $count - count($this->rows);
    }

    /** @return list<string> "pfad@typ:id:kontext" */
    public function dump(): array
    {
        $out = array_map(static fn (array $r): string => $r['media_path'] . '@' . $r['content_type'] . ':' . $r['content_id'] . ':' . $r['context'], array_values($this->rows));
        sort($out);

        return $out;
    }
}

final class ArrayExtractor implements MediaReferenceExtractor
{
    /** @param array<int, array{title:string, values:list<array{context:string, value:mixed}>}> $items */
    public function __construct(public string $type, public string $label, public array $items)
    {
    }

    public function contentType(): string
    {
        return $this->type;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function ids(int $afterId, int $limit): array
    {
        $ids = array_values(array_filter(array_keys($this->items), static fn (int $id): bool => $id > $afterId));
        sort($ids);

        return array_slice($ids, 0, $limit);
    }

    public function load(int $id): ?array
    {
        $item = $this->items[$id] ?? null;

        return $item === null ? null : ['title' => $item['title'], 'edit_url' => '/admin/' . $this->type . '?id=' . $id, 'values' => $item['values']];
    }
}

$clock = new ArrayObject(['now' => 1_800_000_000, 'built' => '']);
$makeIndex = static function (MemoryUsageStore $store, array $extractors) use ($normalizer, $clock): MediaUsageIndex {
    $index = new MediaUsageIndex([
        'store' => $store,
        'normalizer' => $normalizer,
        'clock' => static fn (): int => $clock['now'],
        'builtGet' => static fn (): string => (string) $clock['built'],
        'builtSet' => static function (string $value) use ($clock): void {
            $clock['built'] = $value;
        },
        'registerCore' => false,
    ]);
    foreach ($extractors as $extractor) {
        $index->register($extractor);
    }

    return $index;
};

$editorJs = (string) json_encode(['blocks' => [
    ['type' => 'paragraph', 'data' => ['text' => 'Siehe <a href="/uploads/docs/handbuch.pdf">Handbuch</a>']],
    ['type' => 'image', 'data' => ['file' => ['url' => '/uploads/2026/bild-small.jpg']]],
    ['type' => 'imageGallery', 'data' => ['images' => [['url' => 'https://cms.example.test/uploads/2026/foto.png'], ['url' => 'https://fremd.example/uploads/x.jpg']]]],
    ['type' => 'attaches', 'data' => ['file' => ['url' => '/uploads/docs/handbuch.pdf']]],
]], JSON_UNESCAPED_SLASHES);

T::run('Indizieren: Kontexte und Neuindizieren', static function () use ($makeIndex, $editorJs): void {
    $store = new MemoryUsageStore();
    $posts = new ArrayExtractor('post', 'Beitrag', [
        5 => ['title' => 'Hallo', 'values' => [
            ['context' => 'featured', 'value' => '/uploads/2026/bild.webp'],
            ['context' => 'content', 'value' => $editorJs],
            ['context' => 'content_en', 'value' => '<p><img src="/uploads/2026/bild.jpg"></p>'],
        ]],
    ]);
    $index = $makeIndex($store, [$posts]);
    T::same(6, $index->reindexContent('post', 5), 'Anzahl Verweise (Pfad × Kontext)');
    T::same([
        '2026/bild.jpg@post:5:content',
        '2026/bild.jpg@post:5:content_en',
        '2026/bild.jpg@post:5:featured',
        '2026/foto.png@post:5:gallery',
        'docs/handbuch.pdf@post:5:attachment',
        'docs/handbuch.pdf@post:5:content',
    ], $store->dump(), 'Beitragsbild, Inhalt, Galerie, Anhang, Inhalt EN – auf Originale zurückgeführt, fremde Domain ignoriert');

    $posts->items[5]['values'] = [['context' => 'content', 'value' => '<img src="/uploads/2026/foto.png">']];
    $index->reindexContent('post', 5);
    T::same(['2026/foto.png@post:5:content'], $store->dump(), 'nach dem Speichern: entfernte Bilder fallen weg');

    unset($posts->items[5]);
    $index->reindexContent('post', 5);
    T::same([], $store->dump(), 'gelöschter Inhalt (load = null) wird entfernt');

    $posts->items[6] = ['title' => 'B', 'values' => [['context' => 'content', 'value' => '/uploads/2026/bild.jpg']]];
    $index->reindexContent('post', 6);
    $index->removeContent('post', 6);
    T::same([], $store->dump(), 'removeContent (Hook *_deleted)');
    T::same(0, $index->reindexContent('unbekannt', 1), 'unbekannte Inhaltsart: nichts');

    $threw = false;
    try {
        $index->register(new ArrayExtractor('Böse Art', 'x', []));
    } catch (\InvalidArgumentException) {
        $threw = true;
    }
    T::check($threw, 'Inhaltsart muss [a-z0-9_]{1,30} sein');
});

T::run('Abfrage für Mediathek und Löschwarnung', static function () use ($makeIndex): void {
    $store = new MemoryUsageStore();
    $posts = new ArrayExtractor('post', 'Beitrag', [3 => ['title' => 'Mein Beitrag', 'values' => [['context' => 'featured', 'value' => '/uploads/2026/bild.jpg']]]]);
    $hubs = new ArrayExtractor('hub_site', 'Hub-Site', [9 => ['title' => 'Portal', 'values' => [['context' => 'setting', 'value' => '{"hero_image":"/uploads/2026/bild-small.jpg"}']]]]);
    $index = $makeIndex($store, [$posts, $hubs]);
    $index->reindexContent('post', 3);
    $index->reindexContent('hub_site', 9);

    $map = $index->usageMap(['2026/bild.jpg', '2026/foto.png', '2026/bild.webp']);
    T::same(['2026/bild.jpg', '2026/foto.png', '2026/bild.webp'], array_keys($map), 'jeder angefragte Pfad hat einen Eintrag');
    T::same(2, count($map['2026/bild.jpg']), 'zwei Verwendungen');
    $first = $map['2026/bild.jpg'][0];
    T::same(
        ['post', 'Beitrag', 'featured_image', 'Beitragsbild', 3, 'Mein Beitrag', '/admin/post?id=3'],
        [$first['content_type'], $first['content_type_label'], $first['field'], $first['field_label'], $first['content_id'], $first['title'], $first['edit_url']],
        'Format wie MediaUsageService::buildUsageMap() (featured → featured_image)'
    );
    T::same(['Hub-Site', 'Einstellung'], [$map['2026/bild.jpg'][1]['content_type_label'], $map['2026/bild.jpg'][1]['field_label']], 'Hub-Site-Einstellung');
    T::same([], $map['2026/foto.png'], 'ungenutzt: leere Liste');
    T::same(2, count($map['2026/bild.webp']), 'WebP-Fassung zeigt die Verwendungen des Originals');

    unset($posts->items[3]);
    T::same(1, count($index->usageMap(['2026/bild.jpg'])['2026/bild.jpg']), 'Zeile eines nicht mehr vorhandenen Inhalts wird nicht angezeigt');

    T::same(['2026/foto.png', 'docs/handbuch.pdf'], $index->unusedMedia(['2026/bild.jpg', '2026/foto.png', 'docs/handbuch.pdf']), 'unusedMedia');
    T::same(['docs/handbuch.pdf'], $index->unusedMedia(['2026/bild.jpg', '2026/foto.png', 'docs/handbuch.pdf'], 1, 10), 'unusedMedia mit Offset');
    T::same([], $index->usageMap(['../x', '']), 'ungültige Pfade ignoriert');
});

T::run('Neuaufbau in Schritten mit Bereinigung', static function () use ($makeIndex, $clock): void {
    $store = new MemoryUsageStore();
    $items = [];
    for ($i = 1; $i <= 7; $i++) {
        $items[$i] = ['title' => 'P' . $i, 'values' => [['context' => 'content', 'value' => '/uploads/2026/bild.jpg']]];
    }
    $posts = new ArrayExtractor('post', 'Beitrag', $items);
    $forms = new ArrayExtractor('form', 'Formular', [2 => ['title' => 'Kontakt', 'values' => [['context' => 'setting', 'value' => '/uploads/2026/foto.png']]]]);
    $index = $makeIndex($store, [$posts, $forms]);

    // Altlast: Zeile eines Inhalts, den es nicht mehr gibt
    $store->rows['alt'] = ['media_path' => '2026/foto.png', 'content_type' => 'post', 'content_id' => 99, 'context' => 'content', 'updated_at' => '2020-01-01 00:00:00'];
    $clock['built'] = '';
    T::check(!$index->isBuilt(), 'vor dem ersten Lauf: nicht aufgebaut');

    $state = ['started_at' => date('Y-m-d H:i:s', $clock['now'])];
    $step1 = $index->rebuildStep($state, 3);
    T::same([3, false, 3], [$step1['processed'], $step1['done'], $step1['state']['after_id']], 'Schritt 1: 3 Inhalte');
    $step2 = $index->rebuildStep($step1['state'], 3);
    $step3 = $index->rebuildStep($step2['state'], 3);
    T::same([3, 2, true], [$step2['processed'], $step3['processed'], $step3['done']], 'Schritt 2 und 3 (Wechsel der Inhaltsart innerhalb eines Schritts)');
    T::check(!isset($store->rows['alt']), 'nicht berührte Altlast entfernt');
    T::same(8, count($store->rows), 'alle Inhalte indiziert');
    T::check($index->isBuilt(), 'danach aufgebaut (Merker gesetzt)');

    $again = $index->rebuildStep($step3['state'], 3);
    T::same([0, true], [$again['processed'], $again['done']], 'weiterer Schritt nach dem Ende: nichts doppelt');
});

T::run('Einbindung (statisch)', static function () use ($root): void {
    $read = static fn (string $file): string => (string) file_get_contents($root . $file);

    $schema = $read('core/SchemaManager.php');
    T::check(str_contains($schema, "'media_usage' => \"CREATE TABLE IF NOT EXISTS {\$p}media_usage (") && str_contains($schema, "'media_usage',\n"), 'Schema v26: Tabelle media_usage in FEATURE_TABLES_V26');

    $service = $read('core/Services/MediaUsageService.php');
    T::check(preg_match('/public function buildUsageMap\(array \$relativePaths\): array\s*\{.*?if \(\$index->isBuilt\(\)\) \{\s*return \$index->usageMap\(/s', $service) === 1, 'MediaUsageService nutzt den Index, sobald er aufgebaut ist (Mediathek, Filter)');
    T::check(str_contains($read('core/Services/Trash/MediaTrashProvider.php'), 'MediaUsageService::getInstance()->buildUsageMap([$path])'), 'Löschwarnung im Papierkorb über buildUsageMap → Index');

    $hooks = $read('core/Services/FeatureHooks/MediaFeatureHooks.php');
    foreach (["'cms_after_post_save' => ['post', false]", "'page_deleted' => ['page', true]", "'cms_after_hub_save' => ['hub_site', false]", "'cms_after_download_save' => ['download', false]", "'download_deleted' => ['download', true]", "'cms_after_form_save' => ['form', false]", "'cms_form_deleted' => ['form', true]"] as $mapping) {
        T::check(str_contains($hooks, $mapping), 'Hook: ' . $mapping);
    }
    T::check(str_contains($hooks, "'cms_theme_customizer_saved'") && str_contains($hooks, "reindexContent('theme', 0)"), 'Theme-Einstellungen: einmal je Anfrage neu indizieren');
    T::check(str_contains($hooks, 'MediaUsageRebuildService::getInstance()->continueInCron()'), 'Neuaufbau läuft im Cron weiter');

    $forms = $read('core/Services/Forms/FormService.php');
    T::check(substr_count($forms, "Hooks::doAction('cms_after_form_save'") === 2 && str_contains($forms, "Hooks::doAction('cms_form_deleted', \$id);"), 'FormService meldet Anlegen, Ändern, Löschen');
    T::check(str_contains($read('core/Services/ThemeCustomizer.php'), "Hooks::doAction('cms_theme_customizer_saved'"), 'ThemeCustomizer meldet gespeicherte Werte');

    $index = $read('core/Services/Media/MediaUsageIndex.php');
    foreach (['ContentTableReferenceExtractor::posts()', 'ContentTableReferenceExtractor::pages()', 'new Usage\HubSiteReferenceExtractor()', 'new Usage\DownloadReferenceExtractor()', 'new Usage\FormReferenceExtractor()', 'new Usage\ThemeReferenceExtractor()'] as $core) {
        T::check(str_contains($index, $core), 'Core-Extraktor: ' . $core);
    }
    T::check(str_contains($index, 'Hooks::doAction(self::REGISTER_ACTION, $this);'), 'Registrierung weiterer Extraktoren (cms_register_media_reference_extractors)');
    $contentExtractor = $read('core/Services/Media/Usage/ContentTableReferenceExtractor.php');
    T::check(str_contains($contentExtractor, "'/admin/pages?action=edit&id=', ['excerpt']);") && str_contains($contentExtractor, "'/admin/posts?action=edit&id=', ['excerpt', 'excerpt_en']);"), 'Seiten ohne excerpt_en abgefragt (Spalte fehlt in pages)');
    foreach (glob($root . 'core/Services/Media/Usage/*.php') ?: [] as $extractorFile) {
        $source = (string) file_get_contents($extractorFile);
        T::check(!preg_match('/(INSERT|UPDATE|DELETE)\s/i', $source) && !preg_match('/WHERE[^"]*\{\$id\}|\.\s*\$id\s*\./', $source), basename($extractorFile) . ': nur lesend, gebundene Parameter');
    }

    $api = $read('admin/media-tools-api.php');
    T::check(str_contains($api, "MediaUsageRebuildService::JOB_TYPE => 'usage'") && str_contains($api, "'usage' => MediaUsageRebuildService::getInstance()"), 'Medien-Werkzeuge-API: Auftrag usage');
    $toolsView = $read('admin/views/media/tools.php');
    T::check(str_contains($toolsView, "\$mtUsageConfig['job'] = 'usage';") && str_contains($toolsView, 'id="usage-index"'), 'Medien-Werkzeuge: Karte „Verwendungsindex neu aufbauen“');
    T::check(str_contains($read('admin/views/media/library.php'), "if (!\$usageIndexBuilt && \$usageFilter !== 'all')"), 'Mediathek: Hinweis, solange der Index fehlt');

    foreach (['de', 'en'] as $lang) {
        $yaml = $read("lang/{$lang}.yaml");
        foreach (['media_tools.usage.title', 'media_tools.usage.not_built', 'media_tools.usage.summary'] as $key) {
            T::check(str_contains($yaml, "\"{$key}\":"), "{$lang}: {$key}");
        }
    }
});

T::finish();
