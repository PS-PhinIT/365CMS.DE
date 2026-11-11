<?php
/**
 * Tests für die EditorJsBlockRegistry (Render-Kontext für Frontend und Editor-Vorschau) ohne Datenbank.
 *
 * Aufruf aus dem Repository-Wurzelverzeichnis: php TESTS/editorjs-blocks/run.php [-v]
 */

declare(strict_types=1);

use CMS\Services\EditorJs\EditorJsBlockRegistry;

define('ABSPATH', dirname(__DIR__, 2) . '/CMS/');
define('CMS_DEBUG', false);
define('LOG_LEVEL', 'emergency');
define('LOG_PATH', sys_get_temp_dir() . '/cms-tests-logs/');

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

T::$verbose = in_array('-v', $argv ?? [], true);

EditorJsBlockRegistry::register(
    'probeBlock',
    'Probe',
    'js/editor-blocks/probe-block.js',
    static fn (array $data): array => ['ref' => (int) ($data['ref'] ?? 0)],
    static fn (array $data, array $tunes, array $context): string => $context['mode'] . ':' . $data['ref'] . ':' . count($tunes)
);

T::run('Render-Kontext', static function (): void {
    T::same('frontend:7:0', EditorJsBlockRegistry::render('probeBlock', ['ref' => 7]), 'ohne Kontext wird für das Frontend gerendert');
    T::same('preview:7:1', EditorJsBlockRegistry::render('probeBlock', ['ref' => 7], ['align' => 'left'], ['mode' => 'preview']), 'Editor-Vorschau erhält mode=preview');
    T::same('frontend:7:0', EditorJsBlockRegistry::render('probeBlock', ['ref' => 7], [], ['mode' => '<script>']), 'unbekannter Modus fällt auf frontend zurück');
    T::same('', EditorJsBlockRegistry::render('unknownBlock', ['ref' => 7], [], ['mode' => 'preview']), 'unbekannter Typ liefert kein HTML');
});

T::run('Ältere Render-Callbacks', static function (): void {
    EditorJsBlockRegistry::register('legacyBlock', 'Alt', '', static fn (array $data): array => $data, static fn (array $data): string => 'legacy');
    T::same('legacy', EditorJsBlockRegistry::render('legacyBlock', [], [], ['mode' => 'preview']), 'Callback mit nur einem Parameter funktioniert weiter');
    T::same(['js/editor-blocks/probe-block.js'], EditorJsBlockRegistry::scripts(), 'Blöcke ohne Skript erscheinen nicht in der Skriptliste');
});

T::run('Sanitizer vor der Vorschau', static function (): void {
    $clean = EditorJsBlockRegistry::sanitize('probeBlock', ['ref' => '12abc', 'html' => '<b>x</b>']);
    T::same(['ref' => 12], $clean, 'Sanitizer verwirft unbekannte Felder');
    T::same('preview:12:0', EditorJsBlockRegistry::render('probeBlock', $clean, [], ['mode' => 'preview']), 'bereinigte Daten werden gerendert');
    T::same([], EditorJsBlockRegistry::sanitize('unknownBlock', ['ref' => 1]), 'unbekannter Typ liefert leere Daten');
});

T::finish();
