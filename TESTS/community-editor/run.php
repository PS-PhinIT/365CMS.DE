<?php
/**
 * Community-Editor (PR #4/#5): Vorlage, Anbindung und Browser-Prüfung des visuellen Editors (SunEditor mit Markdown-Brücke).
 *
 * Teil 1 (immer, ohne Browser): Quelltext von Vorlage, Controller und Modulen. Teil 2 (nur mit `node` und Playwright, sonst übersprungen
 * wie die Datenbank-Läufe): `browser.cjs` startet Chromium gegen die echte Vorlage unter dem CSP des CMS (Nonce, Trusted Types).
 *
 *   php TESTS/community-editor/run.php [-v]
 */

declare(strict_types=1);

define('ABSPATH', dirname(__DIR__, 2) . '/CMS/');
define('LOG_PATH', sys_get_temp_dir() . '/cms-tests-logs-community-editor-' . getmypid() . '/');
require_once dirname(__DIR__) . '/lib/TestRunner.php';

$read = static fn (string $file): string => (string) file_get_contents(ABSPATH . $file);

T::run('Vorlage, Controller und Module (Quelltext)', static function () use ($read): void {
    $compose = $read('views/community/compose.php');
    $controller = $read('core/Services/Community/Http/PublicController.php');
    $editor = $read('assets/js/community/editor.js');
    $bridge = $read('assets/js/community/md-bridge.js');

    T::check(str_contains($compose, 'data-cc-editor="') && str_contains($compose, 'data-css-url=') && str_contains($compose, 'data-js-url=') && str_contains($compose, 'data-target-key='), 'compose: Editor-Attribute (Modus, Asset-Adressen, Vorschauziel)');
    T::check(preg_match('/<\?php if \(!empty\(\$editor\)\): \?> data-cc-editor=/', $compose) === 1, 'compose: Editor-Attribute nur mit $editor (Gäste und Hinweisseiten ohne)');
    T::check(str_contains($compose, 'id="cc-body"') && str_contains($compose, 'name="body"') && str_contains($compose, 'required'), 'compose: das Markdown-Textfeld bleibt (ohne JavaScript bedienbar)');
    T::check(str_contains($controller, "'mode' => (\$vars['mode'] ?? '') === 'edit' ? 'markdown' : 'visual'"), 'Controller: neu und Antwort visuell, Bearbeiten zuerst Markdown (verlustfrei)');
    T::check(str_contains($controller, "cms_asset_url('suneditor/suneditor.min.js')") && !str_contains($controller, 'cdn') && !str_contains($controller, 'https://'), 'Controller: SunEditor nur lokal (kein CDN)');
    T::check(str_contains($controller, "js/community/editor.js") && str_contains($controller, "empty(\$vars['readonly'])"), 'Controller: Editor-Modul nur auf Formularseiten ohne Hinweisansicht');
    T::check(is_file(ABSPATH . 'assets/suneditor/suneditor.min.js') && is_file(ABSPATH . 'assets/suneditor/css/suneditor.min.css') && is_file(ABSPATH . 'assets/suneditor/lang/de.js'), 'SunEditor-Dateien liegen lokal im Repository');

    foreach (['editor.js' => $editor, 'md-bridge.js' => $bridge] as $name => $source) {
        T::check(!str_contains($source, 'eval(') && !str_contains($source, 'document.write') && !str_contains($source, 'new Function'), $name . ': kein eval, document.write, new Function');
        T::check(!preg_match('#https?://[a-z0-9.-]+\.[a-z]{2,}#i', preg_replace('#//.*$#m', '', $source) ?? ''), $name . ': keine externen Adressen');
    }
    T::check(substr_count($editor, 'innerHTML') === 0, 'editor.js: kein innerHTML (Inhalte nur über die SunEditor-API und textContent)');
    T::check(substr_count($bridge, 'innerHTML') === 0, 'md-bridge.js: kein innerHTML');
    T::check(str_contains($bridge, "['http:', 'https:', 'mailto:']") && str_contains($bridge, 'SKIP'), 'md-bridge.js: Linkprotokolle und Überspringliste');
    T::check(str_contains($editor, 'elementBlacklist') && str_contains($editor, 'attributeWhitelist'), 'editor.js: SunEditor mit Element-Sperrliste und Attribut-Allowlist');
    T::check(filesize(ABSPATH . 'assets/js/community/editor.js') + filesize(ABSPATH . 'assets/js/community/md-bridge.js') < 30 * 1024, 'eigener Editor-Code unter 30 KB (SunEditor lädt erst auf Formularseiten)');
    T::check(!str_contains($read('assets/js/community/community.js'), 'suneditor'), 'community.js bleibt unabhängig vom Editor');
    T::check(strpos($editor, 'if (root && textarea && form)') > strpos($editor, 'let editor = null;'), 'editor.js: Start erst nach den Deklarationen (kein TDZ-Fehler)');
});

T::run('Browser (Chromium, Playwright)', static function (): void {
    $node = trim((string) @shell_exec('command -v node 2>/dev/null'));
    $probe = $node !== '' ? trim((string) @shell_exec(escapeshellarg($node) . ' -e "try{require(\'playwright\');console.log(\'ok\')}catch(e){console.log(\'no\')}" 2>/dev/null')) : '';
    if ($probe !== 'ok') {
        T::info('Browser-Prüfung übersprungen: node und Playwright (mit Chromium) nötig');

        return;
    }
    $dir = sys_get_temp_dir() . '/cms-community-editor-' . getmypid();
    @mkdir($dir, 0700, true);
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/render.php') . ' ' . escapeshellarg($dir) . ' 2>&1', $renderOut, $renderCode);
    T::same(0, $renderCode, 'Vorlage wird für die Browser-Prüfung gerendert', implode("\n", $renderOut));
    T::check(is_file($dir . '/new.html') && is_file($dir . '/reply.html') && is_file($dir . '/edit.html'), 'drei Seiten gerendert');
    $output = [];
    exec(escapeshellarg($node) . ' ' . escapeshellarg(__DIR__ . '/browser.cjs') . ' ' . escapeshellarg($dir) . ' 2>&1', $output, $code);
    $text = implode("\n", $output);
    T::same(0, $code, 'Browser-Prüfung fehlerfrei: ' . (string) end($output), $text);
    foreach (glob($dir . '/*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($dir);
});

T::finish();
