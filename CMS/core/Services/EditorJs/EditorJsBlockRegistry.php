<?php
/**
 * Registry für zusätzliche Editor.js-Blöcke aus Core-Features (z. B. „Download“, „Formular“).
 *
 * Ein Eintrag registriert einen Block an allen drei Stellen, die Editor.js-Blöcke benötigen:
 * - Editor-Factory/Asset-Registry: das Tool-Skript wird vor `js/editor-init.js` geladen und meldet
 *   sich über `window.CMS_EDITORJS_BLOCKS.push({ name, toolClass, config })` an.
 * - Sanitizer (Speichern): `sanitize`-Callback bereinigt die Blockdaten serverseitig.
 * - Öffentlicher Renderer: `render`-Callback erzeugt das Frontend-HTML.
 *
 * Registrierung über `CMS\Services\FeatureHooks\<Feature>Hooks::register()`.
 *
 * @package CMSv2\Core\Services\EditorJs
 */

declare(strict_types=1);

namespace CMS\Services\EditorJs;

if (!defined('ABSPATH')) {
    exit;
}

final class EditorJsBlockRegistry
{
    /**
     * @var array<string, array{label: string, script: string, sanitize: \Closure, render: \Closure}>
     */
    private static array $blocks = [];

    /**
     * @param string $type Block-Typ (Editor.js-Toolname), z. B. "download"
     * @param string $label Lesbarer Name (Revisionen, Diff)
     * @param string $script Pfad relativ zu `CMS/assets/`, z. B. "js/editor-blocks/download-block.js"
     * @param callable(array<string, mixed>): array<string, mixed> $sanitize
     * @param callable(array<string, mixed>, array<string, mixed>): string $render
     */
    public static function register(string $type, string $label, string $script, callable $sanitize, callable $render): void
    {
        if (preg_match('/^[a-zA-Z][a-zA-Z0-9]{1,40}$/', $type) !== 1) {
            throw new \InvalidArgumentException('Ungültiger Editor.js-Blocktyp: ' . $type);
        }

        self::$blocks[$type] = [
            'label' => $label,
            'script' => ltrim($script, '/'),
            'sanitize' => \Closure::fromCallable($sanitize),
            'render' => \Closure::fromCallable($render),
        ];
    }

    public static function has(string $type): bool
    {
        return isset(self::$blocks[$type]);
    }

    /**
     * @return list<string>
     */
    public static function types(): array
    {
        return array_keys(self::$blocks);
    }

    public static function label(string $type): ?string
    {
        return self::$blocks[$type]['label'] ?? null;
    }

    /**
     * @return list<string> Asset-Pfade relativ zu `CMS/assets/`
     */
    public static function scripts(): array
    {
        $scripts = [];
        foreach (self::$blocks as $block) {
            if ($block['script'] !== '' && !in_array($block['script'], $scripts, true)) {
                $scripts[] = $block['script'];
            }
        }

        return $scripts;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function sanitize(string $type, array $data): array
    {
        if (!isset(self::$blocks[$type])) {
            return [];
        }

        $clean = (self::$blocks[$type]['sanitize'])($data);

        return is_array($clean) ? $clean : [];
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $tunes
     */
    public static function render(string $type, array $data, array $tunes = []): string
    {
        if (!isset(self::$blocks[$type])) {
            return '';
        }

        try {
            return (string) (self::$blocks[$type]['render'])($data, $tunes);
        } catch (\Throwable $e) {
            \CMS\Logger::instance()->withChannel('editorjs.blocks')->error('Editor.js-Block konnte nicht gerendert werden.', [
                'type' => $type,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return '';
        }
    }
}
