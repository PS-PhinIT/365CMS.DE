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

T::run('Download-Block: vier Templates und Bereinigung', static function (): void {
    $renderer = \CMS\Services\Downloads\DownloadRenderer::class;
    $settings = \CMS\Services\Downloads\DownloadSettings::class;

    T::same(['compact', 'card', 'featured', 'button'], $settings::TEMPLATES, 'vier Templates');
    foreach ($settings::TEMPLATES as $template) {
        T::check(is_file(ABSPATH . 'views/partials/download/' . $template . '.php'), 'Partial für „' . $template . '“ vorhanden');
        T::same($template, $renderer::sanitizeBlock(['downloadIds' => [3], 'template' => $template])['template'], 'Template „' . $template . '“ bleibt erhalten');
    }

    T::same([4, 9, 2], $renderer::sanitizeBlock(['downloadIds' => ['4', 9, 0, -1, 'x', 2, 9], 'template' => 'compact'])['downloadIds'], 'Kompakt: mehrere gültige IDs, ohne Dubletten');
    foreach (['card', 'featured', 'button'] as $template) {
        T::same([4], $renderer::sanitizeBlock(['downloadIds' => [4, 9], 'template' => $template])['downloadIds'], '„' . $template . '“: nur ein Download');
    }

    $clean = $renderer::sanitizeBlock([
        'downloadIds' => [7],
        'template' => 'button',
        'version' => '../1.0',
        'url' => 'https://example.org/x.zip',
        'options' => [
            'showSize' => '0',
            'showProperties' => ['plattform', 'Bad Key', 'plattform', '<b>', 7],
            'buttonLabel' => "<b>Jetzt</b>\nladen",
            'path' => '/etc/passwd',
        ],
    ]);
    T::same(['downloadIds', 'version', 'template', 'options'], array_keys($clean), 'nur Referenzen und Optionen, keine Pfade oder URLs');
    T::same('latest', $clean['version'], 'ungültige Version fällt auf latest zurück');
    T::same(false, $clean['options']['showSize'], 'Schalter werden zu bool');
    T::same(['plattform'], $clean['options']['showProperties'], 'nur gültige Eigenschaftsschlüssel, ohne Dubletten');
    T::check(!str_contains($clean['options']['buttonLabel'], '<') && !str_contains($clean['options']['buttonLabel'], "\n"), 'Button-Text ohne HTML und Zeilenumbruch');
    T::check(!array_key_exists('path', $clean['options']), 'unbekannte Optionen werden verworfen');
});

T::run('Download-Block: neue Eigenschaftsfelder', static function (): void {
    $settings = \CMS\Services\Downloads\DownloadSettings::class;
    $fields = [['key' => 'plattform', 'label' => 'Plattform', 'type' => 'select', 'options' => ['Windows', 'Linux']]];

    [$next, $key] = $settings::appendPropertyField($fields, 'Geprüft am', 'date');
    T::same('geprueft_am', $key, 'Schlüssel aus der Bezeichnung (Umlaute umschrieben)');
    T::same(['key' => 'geprueft_am', 'label' => 'Geprüft am', 'type' => 'date', 'options' => []], $next[1], 'Feld wird angehängt');
    T::same($next, $settings::normalizePropertyFields($next), 'Ergebnis besteht die Normalisierung der Einstellungen');

    [$same, $existing] = $settings::appendPropertyField($fields, 'plattform', 'text');
    T::same(['plattform', 1, 'select'], [$existing, count($same), $same[0]['type']], 'vorhandenes Feld wird wiederverwendet, nicht überschrieben');

    T::same('', $settings::appendPropertyField($fields, '123', 'text')[1], 'Bezeichnung ohne Buchstaben am Anfang wird abgelehnt');
    T::same('gr_masse', $settings::appendPropertyField($fields, 'Gr. & Maße!', 'text')[1], 'Sonderzeichen landen nicht im Schlüssel');
    T::same('', $settings::appendPropertyField($fields, '<script>', 'text')[1], 'reines Markup ergibt kein Feld');
    T::same('', $settings::appendPropertyField($fields, 'Auswahl', 'select')[1], 'Auswahlfelder nur über die Einstellungen');
    T::same('text', $settings::appendPropertyField($fields, 'Hinweis', 'bogus')[0][1]['type'], 'unbekannter Typ wird zu text');

    $full = [];
    for ($i = 0; $i < 50; $i++) {
        $full[] = ['key' => 'f' . $i, 'label' => 'F' . $i, 'type' => 'text', 'options' => []];
    }
    [$capped, $none] = $settings::appendPropertyField($full, 'Noch eins', 'text');
    T::same(['', 50], [$none, count($capped)], 'höchstens 50 Felder');
});

T::run('Blockauswahl des Editors', static function (): void {
    // Die Auswahl im Beitrags-/Seiteneditor ist eine feste Liste; Core-Blöcke müssen dort eingetragen sein.
    $source = (string) file_get_contents(ABSPATH . 'assets/js/admin-content-editor.js');
    T::check(str_contains($source, "block: 'download'"), 'Download steht in der Blockauswahl');
    T::check(str_contains($source, "block: 'form'"), 'Formular steht in der Blockauswahl');
});

T::finish();
