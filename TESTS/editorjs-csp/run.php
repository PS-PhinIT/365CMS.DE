<?php
/**
 * Tests ohne Datenbank und Browser: Editor.js-Ausgaben ohne style-Attribute (Produktiv-CSP, style-src-attr).
 *
 * Die Produktiv-CSP setzt `style-src 'self' 'nonce-…'` ohne 'unsafe-inline'; `style="…"` im HTML wird blockiert.
 * Geprüft werden EditorJsAssetService::render() (Editor-Markup), EditorJsRenderer (öffentliche Ausgabe) und die
 * Editor-Ansichten für Beiträge und Seiten. Den Browser-Nachweis mit erzwungener CSP und
 * `securitypolicyviolation`-Listener führt `node TESTS/editorjs-csp/browser/run.js`.
 *
 * Aufruf aus dem Repository-Wurzelverzeichnis: php TESTS/editorjs-csp/run.php [-v]
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/TestRunner.php';

T::$verbose = in_array('-v', $argv ?? [], true);

$repository = dirname(__DIR__, 2);
$renderScript = __DIR__ . '/browser/render.php';

/** Führt render.php in einem eigenen Prozess aus (eigene Attrappen für Security/Auth). */
$render = static function (array $arguments) use ($renderScript): string {
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($renderScript);
    foreach ($arguments as $argument) {
        $command .= ' ' . escapeshellarg($argument);
    }
    $output = [];
    exec($command . ' 2>&1', $output, $exitCode);
    if ($exitCode !== 0) {
        throw new RuntimeException('render.php ' . implode(' ', $arguments) . ' fehlgeschlagen: ' . implode("\n", $output));
    }

    return implode("\n", $output);
};

/**
 * style-Attribute außerhalb von script/style/template (z. B. kein `data-style`).
 *
 * @return list<string>
 */
$styleAttributes = static function (string $html): array {
    $withoutRawText = (string) preg_replace('#<(script|style|template)\b[^>]*>.*?</\1>#is', '', $html);
    preg_match_all('#<[a-z][a-z0-9:-]*\b[^>]*?\sstyle\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', $withoutRawText, $matches);

    return $matches[0];
};

T::run('EditorJsAssetService: öffentliche Schnittstelle unverändert', static function () use ($repository): void {
    define('ABSPATH', $repository . '/CMS/');
    require_once ABSPATH . 'core/Services/EditorJs/EditorJsAssetService.php';
    $class = new ReflectionClass(\CMS\Services\EditorJs\EditorJsAssetService::class);
    $methods = [];
    foreach ($class->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        $parameters = array_map(static fn (ReflectionParameter $parameter): string => (string) $parameter->getType() . ' $' . $parameter->getName()
            . ($parameter->isDefaultValueAvailable() ? ' = ' . var_export($parameter->getDefaultValue(), true) : ''), $method->getParameters());
        $methods[$method->getName()] = '(' . implode(', ', $parameters) . '): ' . (string) $method->getReturnType();
    }
    ksort($methods);
    T::same([
        'enqueueEditorAssets' => '(): void',
        'getPageAssets' => '(): array',
        'render' => "(string \$name, string \$content = '', array \$settings = array (\n)): string",
    ], $methods, 'öffentliche Methoden und Signaturen');
});

T::run('EditorJsAssetService::render() ohne style-Attribute', static function () use ($render, $styleAttributes): void {
    $expected = [
        'post' => [1100, 1100, 50, 400],
        'page' => [1180, 1400, 40, 520],
        'plugin' => [960, 960, 50, 460],
        'member' => [760, 760, 50, 320],
        'reusable' => [920, 920, 28, 320],
    ];
    foreach ($expected as $context => [$width, $expanded, $padding, $minHeight]) {
        $result = json_decode($render(['editor', $context]), true);
        $markup = (string) ($result['markup'] ?? '');
        T::same([], $styleAttributes($markup), $context . ': kein style-Attribut im Editor-Markup');
        T::check(str_contains($markup, 'data-editorjs-content-width="' . $width . '"')
            && str_contains($markup, 'data-editorjs-content-width-expanded="' . $expanded . '"')
            && str_contains($markup, 'data-editorjs-content-padding-x="' . $padding . '"'), $context . ': Breiten und Innenabstand als data-Attribute (' . $width . '/' . $expanded . '/' . $padding . ')');
        T::check(str_contains($markup, 'data-editorjs-min-height="' . $minHeight . '"'), $context . ': Mindesthöhe ' . $minHeight . ' als data-Attribut');
        T::check(preg_match('#<script nonce="__CSP_NONCE__">\s*\(function\(\) \{\s*//[^\n]*\n[\s\S]*?applyEditorJsLayout#', $markup) === 1, $context . ': Werte werden im Nonce-Skript übernommen');
        foreach (['--editorjs-content-width', '--editorjs-content-width-expanded', '--editorjs-content-padding-x'] as $property) {
            T::check(str_contains($markup, "['" . $property . "', 'data-" . substr($property, 2) . "']"), $context . ': ' . $property . ' per style.setProperty');
        }
        T::check(str_contains($markup, "setProperty('min-height', minHeight)"), $context . ': min-height per style.setProperty');
    }
});

T::run('EditorJsAssetService::render(): Grenzwerte wie bisher', static function () use ($repository, $styleAttributes): void {
    // Gleiche Klemmung wie zuvor im style-Attribut: Breite ≥ 320, erweiterte Breite ≥ Breite, Innenabstand ≥ 0.
    $file = tempnam(sys_get_temp_dir(), 'cms-csp-');
    file_put_contents($file, '<?php $argv = ["render.php", "fixture"]; ob_start(); require ' . var_export(__DIR__ . '/browser/render.php', true) . '; ob_end_clean();'
        . ' $s = new CMS\Services\EditorJs\EditorJsAssetService(); ob_start();'
        . ' echo $s->render("x", "", ["content_width" => 100, "content_width_expanded" => 50, "content_padding_x" => -5, "height" => "250px"]);'
        . ' $out = ob_get_clean(); echo substr($out, strpos($out, "<input"));');
    $output = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($file) . ' 2>&1');
    unlink($file);
    T::same([], $styleAttributes($output), 'kein style-Attribut');
    T::check(str_contains($output, 'data-editorjs-content-width="320"'), 'Breite mindestens 320');
    T::check(str_contains($output, 'data-editorjs-content-width-expanded="320"'), 'erweiterte Breite mindestens so groß wie die Breite');
    T::check(str_contains($output, 'data-editorjs-content-padding-x="0"'), 'Innenabstand nicht negativ');
    T::check(str_contains($output, 'data-editorjs-min-height="250"'), 'Höhe als ganze Zahl');
});

T::run('EditorJsRenderer: öffentliche Ausgabe ohne style-Attribute', static function () use ($render, $styleAttributes, $repository): void {
    $html = $render(['public']);
    T::same([], $styleAttributes($html), 'kein style-Attribut im gerenderten Inhalt');

    $fixture = json_decode($render(['fixture']), true);
    $types = array_count_values(array_map(static fn (array $block): string => (string) $block['type'], $fixture['blocks']));
    T::check(substr_count($html, 'class="editorjs-block editorjs-image') === $types['image'], 'alle Bilder gerendert (' . $types['image'] . ')');
    T::check(substr_count($html, '<canvas class="editorjs-embed__sizer"') === 8, 'Embeds mit Seitenverhältnis-Canvas');
    T::check(str_contains($html, '<canvas class="editorjs-embed__sizer" width="333" height="777" aria-hidden="true"></canvas>'), 'Canvas trägt width/height des Embeds');
    T::check(str_contains($html, 'editorjs-spacer--custom editorjs-spacer--h100-1 editorjs-spacer--h10-1 editorjs-spacer--h1-3" aria-hidden="true" role="presentation" data-height="113"'), 'freie Spacer-Höhe als Ziffernklassen');
    T::check(str_contains($html, 'class="editorjs-block editorjs-spacer" aria-hidden="true" role="presentation" data-height="40"'), 'Preset-Höhe ohne Zusatzklassen');
    T::check(preg_match('/class="[^"]*\beditorjs-indent editorjs-indent--8"[^>]*\sdata-editorjs-indent="8"/', $html) === 1, 'Einzug als Klasse');
    T::check(str_contains($html, 'editorjs-delimiter--width-50 editorjs-delimiter--thickness-4'), 'Trennlinie über Preset-Klassen');

    // Jede vom Renderer vergebene Modifier-Klasse braucht eine Regel in editorjs-content.css.
    $css = (string) file_get_contents($repository . '/CMS/assets/css/editorjs-content.css');
    preg_match_all('/class="([^"]+)"/', $html, $matches);
    $classes = array_unique(array_merge(...array_map(static fn (string $value): array => preg_split('/\s+/', trim($value)) ?: [], $matches[1])));
    $missing = [];
    foreach ($classes as $class) {
        if (preg_match('/^editorjs-(indent--\d|spacer--(custom|h\d+-\d)|embed__(sizer|frame--sized)|image--(fit|max-height|align|border-|normal|wide|full)|delimiter--(width|thickness)|media-text--(image-width|spacing|valign|image-fit))/', $class) !== 1) {
            continue;
        }
        if (preg_match('/\.' . preg_quote($class, '/') . '(?![\w-])/', $css) !== 1 && !str_ends_with($class, '--max-height-0') && !str_contains($class, '--h100-0') && !str_contains($class, '--h10-0') && !str_contains($class, '--h1-0')) {
            $missing[] = $class;
        }
    }
    T::same([], $missing, 'alle Layout-Klassen haben CSS-Regeln');
    for ($level = 1; $level <= 8; $level++) {
        T::check(str_contains($css, '.editorjs-indent.editorjs-indent--' . $level . ' { margin-left: ' . rtrim(rtrim(number_format($level * 1.5, 1, '.', ''), '0'), '.') . 'rem; }'), 'Einzug Stufe ' . $level);
    }
});

T::run('Editor-Ansichten für Beiträge und Seiten ohne style-Attribute', static function () use ($repository, $styleAttributes): void {
    $views = ['CMS/admin/views/posts/edit.php', 'CMS/admin/views/pages/edit.php'];
    foreach ($views as $view) {
        $source = (string) file_get_contents($repository . '/' . $view);
        preg_match_all('#/\.\./partials/([a-z0-9_-]+\.php)#', $source, $partials);
        foreach (array_merge([$view], array_map(static fn (string $partial): string => 'CMS/admin/views/partials/' . $partial, array_unique($partials[1]))) as $file) {
            $content = (string) file_get_contents($repository . '/' . $file);
            T::same([], $styleAttributes($content), $file . ': kein style-Attribut');
        }
    }
});

T::run('Embed-Vorlagen im Editor ohne style-Attribut', static function () use ($repository): void {
    $source = (string) file_get_contents($repository . '/CMS/assets/js/editor-init.js');
    T::check(str_contains($source, "if (toolName === 'embed') {\n            patchEmbedServiceTemplates(toolClass);"), 'editor-init.js bereinigt die Vorlagen von @editorjs/embed');
    $css = (string) file_get_contents($repository . '/CMS/assets/css/admin.css');
    T::check(str_contains($css, '.cms-embed-frame--full-width {') && str_contains($css, '.cms-embed-frame--centered {'), 'Ersatzklassen in admin.css');
});

T::finish();
