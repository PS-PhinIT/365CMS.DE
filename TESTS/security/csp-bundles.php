<?php
/**
 * CSP-Verträglichkeit der ausgelieferten Editor.js-Bundles (ohne Datenbank, ohne Browser).
 *
 * Die Produktiv-CSP erlaubt kein `unsafe-eval` und verlangt Trusted Types; die Runtime
 * (`CMS/assets/js/cms-csp-runtime.js`) blockiert `createScript` grundsätzlich. Jeder Aufruf von
 * `eval()` oder `new Function()` löst darum `securitypolicyviolation`-Ereignisse aus – auch wenn das
 * Bundle ihn in try/catch kapselt (so bei `@codexteam/ajax` in link/image/attaches).
 * Wird von TESTS/security/run.php eingebunden.
 */

declare(strict_types=1);

T::run('CSP: Editor.js-Bundles kommen ohne eval/new Function aus', static function (): void {
    $dir = ABSPATH . 'assets/editorjs/';
    $files = (array) (new ReflectionClassConstant(\CMS\Services\EditorJs\EditorJsAssetService::class, 'EDITOR_JS_FILES'))->getValue();
    T::check(count($files) >= 20, 'EditorJsAssetService führt die Editor.js-Bundles', 'Anzahl: ' . count($files));

    foreach ($files as $file) {
        $source = (string) @file_get_contents($dir . $file);
        T::check($source !== '', $file . ' ist vorhanden');
        T::check(preg_match('/\bnew\s+Function\s*\(|(?<![\w$.])eval\s*\(/', $source) !== 1, $file . ' enthält kein eval/new Function');
    }

    // Die drei Tool-Bundles mit @codexteam/ajax: globales Objekt ohne new Function("return this").
    foreach (['link.umd.js', 'image.umd.js', 'attaches.umd.js'] as $file) {
        $source = (string) @file_get_contents($dir . $file);
        T::check(str_contains($source, 'typeof globalThis=="object"?globalThis'), $file . ' ermittelt das globale Objekt über globalThis');
    }
});
