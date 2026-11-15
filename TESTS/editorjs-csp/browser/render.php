<?php
/**
 * Rendert Editor.js-Ausgaben ohne Datenbank für den CSP-Browsertest (TESTS/editorjs-csp/browser/run.js).
 *
 *   php render.php public [Renderer-Datei]   Beispielinhalt aller Block-Varianten mit Stil-Optionen (EditorJsRenderer)
 *   php render.php editor <post|page|plugin|member|reusable>
 *                                            Editor-Markup aus EditorJsAssetService::render() mit den Einstellungen
 *                                            der jeweiligen Aufrufer
 *   php render.php fixture                   die Blockdaten des Beispielinhalts als JSON
 *
 * Die optionale Renderer-Datei erlaubt den Layout-Vergleich mit einem älteren Stand von EditorJsRenderer.php.
 * Der Nonce wird als Platzhalter `__CSP_NONCE__` ausgegeben; der Testserver ersetzt ihn je Antwort.
 */

declare(strict_types=1);

namespace CMS {
    if (!class_exists(Security::class, false)) {
        /** Attrappe: Nonce-Platzhalter und fester Token statt Sitzung/Datenbank. */
        final class Security
        {
            private static ?self $instance = null;

            public static function instance(): self
            {
                return self::$instance ??= new self();
            }

            public function nonceAttr(): string
            {
                return 'nonce="__CSP_NONCE__"';
            }

            public function getNonce(): string
            {
                return '__CSP_NONCE__';
            }

            public function generateToken(string $action = ''): string
            {
                return 'test-token';
            }
        }
    }

    if (!class_exists(Auth::class, false)) {
        /** Attrappe: Admin, damit die Schnellleiste auch Registry-Blöcke zeigt. */
        final class Auth
        {
            private static ?self $instance = null;

            public static function instance(): self
            {
                return self::$instance ??= new self();
            }

            public function isAdmin(): bool
            {
                return true;
            }
        }
    }
}

namespace {
    use CMS\Services\EditorJs\EditorJsAssetService;

    define('ABSPATH', dirname(__DIR__, 3) . '/CMS/');
    define('CMS_DEBUG', false);
    define('LOG_LEVEL', 'emergency');
    define('LOG_PATH', sys_get_temp_dir() . '/cms-tests-logs/');
    define('SITE_URL', '');

    $mode = (string) ($argv[1] ?? '');
    $rendererOverride = $mode === 'public' ? (string) ($argv[2] ?? '') : '';
    if ($rendererOverride !== '') {
        if (!is_file($rendererOverride)) {
            fwrite(STDERR, 'Renderer-Datei nicht gefunden: ' . $rendererOverride . PHP_EOL);
            exit(2);
        }
        require $rendererOverride;
    }

    spl_autoload_register(static function (string $class): void {
        if (str_starts_with($class, 'CMS\\')) {
            $file = ABSPATH . 'core/' . str_replace('\\', '/', substr($class, 4)) . '.php';
            if (is_file($file)) {
                require $file;
            }
        }
    });

    /** Einstellungen ohne Datenbank: Lazy Loading aus, damit alle Bilder sofort Layout bekommen. */
    function get_option(string $name, mixed $default = null): mixed
    {
        return match ($name) {
            'perf_lazy_loading' => '0',
            default => $default,
        };
    }

    function cms_asset_url(string $path): string
    {
        return '/assets/' . ltrim($path, '/');
    }

    /**
     * Blöcke mit allen Stil-Optionen, die bisher als style-Attribut ausgegeben wurden.
     *
     * @return list<array<string,mixed>>
     */
    function cspFixtureBlocks(): array
    {
        $photo = '/uploads/csp-fixture/photo.svg';
        $portrait = '/uploads/csp-fixture/portrait.svg';
        $blocks = [];
        $add = static function (string $type, array $data, array $tunes = []) use (&$blocks): void {
            $block = ['id' => 'b' . count($blocks), 'type' => $type, 'data' => $data];
            if ($tunes !== []) {
                $block['tunes'] = $tunes;
            }
            $blocks[] = $block;
        };

        $add('header', ['text' => 'CSP-Testinhalt', 'level' => 2]);
        foreach (['left', 'center', 'right', 'justify'] as $alignment) {
            foreach (['compact', 'normal', 'relaxed', 'loose'] as $spacing) {
                $add('paragraph', ['text' => "Absatz {$alignment}/{$spacing}: Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore.", 'alignment' => $alignment, 'spacing' => $spacing]);
            }
        }
        foreach ([1, 2, 3, 4] as $level) {
            $add('header', ['text' => 'Überschrift H' . $level, 'level' => $level, 'alignment' => $level === 3 ? 'center' : 'left', 'spacing' => $level === 2 ? 'loose' : 'normal']);
        }
        for ($indent = 1; $indent <= 8; $indent++) {
            $add('paragraph', ['text' => 'Eingerückt um Stufe ' . $indent], ['indentTune' => ['indentLevel' => $indent]]);
        }
        $add('paragraph', ['text' => 'Einzug, Anker und Textvariante zusammen.'], ['indentTune' => ['indentLevel' => 2], 'anchor' => 'anker-test', 'textVariant' => 'call-out']);
        $add('header', ['text' => 'Überschrift mit Einzug', 'level' => 3], ['indentTune' => ['indentLevel' => 1]]);
        $add('list', ['style' => 'unordered', 'items' => [['content' => 'Liste mit Einzug', 'items' => []]]], ['indentTune' => ['indentLevel' => 3]]);

        $imageVariants = [
            ['size' => 'normal', 'alignment' => 'center'],
            ['size' => 'normal', 'alignment' => 'left', 'borderStyle' => 'thin'],
            ['size' => 'normal', 'alignment' => 'right', 'borderStyle' => 'medium', 'rounded' => true],
            ['size' => 'wide', 'alignment' => 'center', 'borderStyle' => 'thick', 'shadow' => true],
            ['size' => 'full', 'alignment' => 'center', 'withBackground' => true],
            ['size' => 'full', 'alignment' => 'left', 'imageFit' => 'cover', 'maxHeight' => 300],
            ['size' => 'wide', 'alignment' => 'right', 'imageFit' => 'fill', 'maxHeight' => 200, 'withBackground' => true, 'rounded' => true],
            ['size' => 'normal', 'alignment' => 'center', 'imageFit' => 'contain', 'maxHeight' => 400, 'shadow' => true],
            ['size' => 'normal', 'alignment' => 'center', 'imageFit' => 'none', 'maxHeight' => 200],
            ['size' => 'wide', 'alignment' => 'left', 'imageFit' => 'scale-down', 'maxHeight' => 500],
            ['stretched' => true, 'withBorder' => true, 'caption' => 'Bild mit Unterschrift'],
        ];
        foreach ($imageVariants as $index => $variant) {
            $add('image', $variant + ['file' => ['url' => $index % 3 === 2 ? $portrait : $photo], 'caption' => 'Bild ' . ($index + 1)]);
        }
        $add('image', ['file' => ['url' => $photo], 'caption' => 'Bild mit Einzug', 'alignment' => 'left'], ['indentTune' => ['indentLevel' => 2]]);

        foreach ([[8, 1], [15, 2], [25, 3], [35, 2], [50, 4], [60, 5], [100, 6], [42, 7]] as [$width, $thickness]) {
            $add('delimiter', ['style' => 'line', 'lineWidth' => $width, 'lineThickness' => $thickness]);
        }
        $add('delimiter', ['style' => 'dash']);
        $add('delimiter', ['style' => 'star']);

        foreach ([[640, 360], [580, 320], [400, 505], [600, 600], [1920, 1080], [1000, 123], [333, 777]] as [$width, $height]) {
            $add('embed', ['service' => 'youtube', 'source' => 'https://www.youtube.com/watch?v=test', 'embed' => 'https://embed.test/frame?' . $width . 'x' . $height, 'width' => $width, 'height' => $height, 'caption' => "Embed {$width}×{$height}"]);
        }
        $add('embed', ['service' => 'youtube', 'source' => 'https://example.org/', 'embed' => 'https://embed.test/frame?default', 'caption' => 'Embed ohne Maße']);

        foreach ([0, 8, 40, 75, 200, 'large', 'xs', 5, 37, 113, 199, 250] as $height) {
            $add('paragraph', ['text' => 'Abstand ' . $height . ' folgt']);
            $add('spacer', ['height' => $height]);
        }

        $mediaVariants = [
            ['imagePosition' => 'left', 'imageWidth' => '40'],
            ['imagePosition' => 'right', 'imageWidth' => '33', 'verticalAlignment' => 'center', 'showBorder' => true],
            ['imagePosition' => 'left', 'imageWidth' => '50', 'verticalAlignment' => 'bottom', 'showBorder' => true, 'heading' => 'Bild und Text mit Rahmen'],
            ['imagePosition' => 'right', 'imageWidth' => '40', 'imageFit' => 'contain', 'heading' => 'Bild und Text ohne Rahmen', 'spacingTop' => 0, 'spacingBottom' => 100],
            ['imagePosition' => 'left', 'imageWidth' => '33', 'imageFit' => 'fill', 'spacingTop' => 60, 'spacingBottom' => 5],
            ['imagePosition' => 'left', 'imageWidth' => '50', 'imageFit' => 'scale-down', 'verticalAlignment' => 'center', 'spacingTop' => 30, 'spacingBottom' => 40],
        ];
        foreach ($mediaVariants as $index => $variant) {
            $add('mediaText', $variant + [
                'file' => ['url' => $index % 2 === 0 ? $photo : $portrait],
                'alt' => 'Medien-Text ' . ($index + 1),
                'text' => '<p>Medien-Text ' . ($index + 1) . ': Lorem ipsum dolor sit amet, consectetur adipiscing elit. Integer posuere erat a ante venenatis dapibus.</p><p>Zweiter Absatz.</p>',
            ]);
        }
        $add('mediaText', ['file' => ['url' => $photo], 'text' => '<p>Medien-Text mit Einzug.</p>'], ['indentTune' => ['indentLevel' => 2]]);
        $add('paragraph', ['text' => 'Ende des Testinhalts.']);

        return $blocks;
    }

    /** @return array<string,array<string,mixed>> Einstellungen wie in den echten Aufrufern. */
    function cspEditorContexts(): array
    {
        return [
            // Beitrags- und Seiteneditor mit render()-Standardwerten (z. B. englische Fassung, Auszug).
            'post' => ['name' => 'content', 'settings' => ['context' => 'post', 'aria_label' => 'Beitragsinhalt']],
            'page' => ['name' => 'content', 'settings' => ['height' => 520, 'context' => 'page', 'content_width' => 1180, 'content_width_expanded' => 1400, 'content_padding_x' => 40]],
            // Plugin-Editor (365NET-eventsandspeaker, includes/class-admin.php).
            'plugin' => ['name' => 'event_description', 'settings' => ['height' => 460, 'context' => '365netevents', 'aria_label' => 'Beschreibung', 'content_width' => 960]],
            // Mitgliederprofil (member/profile.php) und EditorJS Block Vorlagen (admin/views/reusable-blocks/edit.php).
            'member' => ['name' => 'bio', 'settings' => ['height' => 320, 'context' => 'member_bio', 'content_width' => 760, 'aria_label' => 'Über mich']],
            'reusable' => ['name' => 'content_de', 'settings' => ['height' => 320, 'context' => 'cms-reusable-block', 'aria_label' => 'Inhalt', 'content_width' => 920, 'content_padding_x' => 28]],
        ];
    }

    $fixtureJson = (string) json_encode(['time' => 1, 'blocks' => cspFixtureBlocks(), 'version' => '2.31.0'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    switch ($mode) {
        case 'fixture':
            echo $fixtureJson;
            break;

        case 'public':
            echo \CMS\Services\EditorJsRenderer::getInstance()->render($fixtureJson);
            break;

        case 'editor':
            $contexts = cspEditorContexts();
            $context = (string) ($argv[2] ?? 'post');
            if (!isset($contexts[$context])) {
                fwrite(STDERR, 'Unbekannter Kontext: ' . $context . PHP_EOL);
                exit(2);
            }
            ob_start();
            $service = new EditorJsAssetService();
            $markup = $service->render($contexts[$context]['name'], $fixtureJson, $contexts[$context]['settings']);
            $assets = (string) ob_get_clean();
            echo json_encode(['assets' => $assets, 'markup' => $markup, 'settings' => $contexts[$context]['settings']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            break;

        default:
            fwrite(STDERR, "Aufruf: php render.php public [Renderer-Datei] | editor <Kontext> | fixture\n");
            exit(2);
    }
}
