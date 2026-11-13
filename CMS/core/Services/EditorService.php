<?php
/**
 * WYSIWYG Editor Service - SunEditor / Editor.js Integration
 * 
 * Provides rich text editing capabilities.
 * Delegates to SunEditor (legacy) or Editor.js based on admin setting.
 * 
 * @package CMSv2\Services
 */

declare(strict_types=1);

namespace CMS\Services;

use CMS\Json;
use CMS\Services\EditorJs\EditorJsContentNormalizer;
use CMS\Services\EditorJs\EditorJsHtmlSanitizer;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Editor Service Class
 */
class EditorService
{
    /**
     * Singleton instance
     */
    private static ?self $instance = null;
    
    /**
     * Track if assets are enqueued
     */
    private static bool $assetsEnqueued = false;
    
    /**
     * Track editor instances for unique IDs
     */
    private static int $editorCount = 0;

    /**
     * Cached editor type: 'suneditor' | 'editorjs'
     */
    private static ?string $editorType = null;
    
    /**
     * Get singleton instance
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Private constructor (Singleton)
     */
    private function __construct()
    {
        // Register admin head action for assets
        \CMS\Hooks::addAction('admin_head', [$this, 'enqueueEditorAssets']);
    }

    /**
     * Aktiven Editor-Typ ermitteln.
     */
    public static function getEditorType(): string
    {
        if (self::$editorType === null) {
            self::$editorType = function_exists('get_option')
                ? (get_option('setting_editor_type', 'editorjs') ?: 'editorjs')
                : 'editorjs';
        }
        return self::$editorType;
    }

    /**
     * Prüfen ob Editor.js aktiv ist.
     */
    public static function isEditorJs(): bool
    {
        return self::getEditorType() === 'editorjs';
    }
    
    /**
     * Render WYSIWYG editor
     * 
     * @param string $name Field name attribute
     * @param string $content Initial content
     * @param array $settings Optional editor settings
     * @return string Editor HTML
     */
    public function render(string $name, string $content = '', array $settings = []): string
    {
        // Bei Editor.js an den spezialisierten Service delegieren
        if (self::isEditorJs()) {
            return EditorJsService::getInstance()->render($name, $content, $settings);
        }

        self::$editorCount++;
        $editorId = $name . '_editor_' . self::$editorCount;
        
        // Ensure assets are enqueued
        if (!self::$assetsEnqueued) {
            $this->enqueueEditorAssets();
        }
        
        // Default settings
        $defaults = [
            'height' => '400',
            'language' => 'de',
            'buttonList' => [
                ['undo', 'redo'],
                ['bold', 'italic', 'underline', 'strike'],
                ['fontColor', 'hiliteColor'],
                ['removeFormat'],
                ['outdent', 'indent'],
                ['align', 'list', 'lineHeight'],
                ['table', 'link', 'image'],
                ['fullScreen', 'codeView']
            ]
        ];
        
        $settings = array_merge($defaults, $settings);
        
        // Content für JS-Übergabe: JSON-encode garantiert korrektes Escaping
        $jsContent = json_encode($content ?? '', JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT);
        
        ob_start();
        ?>
        <textarea 
            id="<?php echo htmlspecialchars($editorId); ?>" 
            name="<?php echo htmlspecialchars($name); ?>" 
            hidden style="display:none;"></textarea>
        <script <?= \CMS\Security::instance()->nonceAttr() ?>>
        (function() {
            var _initialContent = <?php echo $jsContent; ?>;

            // SunEditor wird per defer geladen und steht erst ab DOMContentLoaded bereit.
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', initEditor);
            } else {
                initEditor();
            }

            function showFallback(el) {
                if (el) { el.value = _initialContent; el.hidden = false; el.style.display = 'block'; }
            }

            // SunEditor 3: Plugins müssen explizit übergeben werden; alte 2.x-Buttonnamen werden gemappt.
            function resolveButtons(buttonList) {
                var aliases = { hiliteColor: 'backgroundColor', formatBlock: 'blockStyle', horizontalRule: 'hr' };
                var plugins = {};
                var buttons = buttonList.map(function (group) {
                    return group.map(function (name) {
                        var resolved = aliases[name] || name;
                        if (SUNEDITOR.plugins && SUNEDITOR.plugins[resolved]) {
                            plugins[resolved] = SUNEDITOR.plugins[resolved];
                        }
                        return resolved;
                    });
                });
                return { buttons: buttons, plugins: plugins };
            }

            function initEditor() {
                var editorElement = document.getElementById('<?php echo $editorId; ?>');
                if (!editorElement) {
                    console.error('Editor element not found: <?php echo $editorId; ?>');
                    return;
                }

                if (typeof SUNEDITOR === 'undefined') {
                    console.error('SunEditor not loaded. Please check script inclusion.');
                    showFallback(editorElement);
                    return;
                }

                try {
                    var toolbar = resolveButtons(<?php echo json_encode($settings['buttonList']); ?>);
                    editorElement.value = _initialContent;
                    var editor_<?php echo self::$editorCount; ?> = SUNEDITOR.create(editorElement, {
                        lang: window.SUNEDITOR_LANG && SUNEDITOR_LANG.de ? SUNEDITOR_LANG.de : undefined,
                        value: _initialContent,
                        height: '<?php echo htmlspecialchars((string)$settings['height']); ?>',
                        width: '100%',
                        plugins: toolbar.plugins,
                        buttonList: toolbar.buttons,
                        charCounter: false,
                        statusbar: true,
                        statusbar_resizeEnable: true,
                        statusbar_showPathLabel: false,
                        tabDisable: false,
                        attributeWhitelist: {
                            '*': 'style|class|id|data-[^\\s]+',
                            table: 'cellpadding|cellspacing|border',
                            a: 'href|target|rel|title',
                            img: 'src|alt|title|width|height'
                        },
                        // Die Toolbar hat kein Embed-/Video-Plugin: iframes nicht in den Editor lassen, auch nicht
                        // über die Zwischenablage-Platzhalter (data-se-iframe-holder), aus denen SunEditor sie neu baut.
                        elementBlacklist: 'iframe',
                        attributeBlacklist: { '*': 'data-se-iframe-holder|data-se-iframe-holder-attrs' },
                        blockStyle: { items: ['p', 'div', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'blockquote', 'pre'] },
                        image: { createFileInput: false },
                        video: { createFileInput: false },
                        audio: { createFileInput: false },
                        font: { items: ['Arial', 'Comic Sans MS', 'Courier New', 'Georgia', 'Impact', 'Tahoma', 'Times New Roman', 'Verdana'] },
                        fontColor: { items: [
                            '#ff0000', '#ff5e00', '#ffe400', '#abf200', '#00d8ff', '#0055ff', '#6600ff', '#ff00dd', '#000000',
                            '#ffd8d8', '#fae0d4', '#faecc5', '#c5f2e6', '#d4f4fa', '#d9e5ff', '#e8d9ff', '#ffd9fa', '#f1f1f1',
                            '#ffa7a7', '#ffc19e', '#faed7d', '#b7f0b1', '#b2ebf4', '#b2ccff', '#d1b2ff', '#ffb2f5', '#bdbdbd',
                            '#ff7a7a', '#ff9770', '#f7d730', '#80df90', '#60d2f0', '#8bb8ff', '#bd8fff', '#ff8fe6', '#8c8c8c',
                            '#f15f5f', '#ff7a44', '#f9d120', '#5ce07e', '#30cde4', '#6f9eff', '#af75ff', '#ff78d9', '#595959',
                            '#c92323', '#df5319', '#e5b700', '#30c757', '#009cb4', '#4072ff', '#8150e6', '#ff40c0', '#3b3b3b',
                            '#8c0000', '#a82800', '#ad8a00', '#158f3e', '#005f6d', '#1841bb', '#4e1a95', '#b60084', '#000000'
                        ], splitNum: 9 },
                        // Textarea bei Änderungen synchronisieren (SunEditor 3 schreibt nicht selbst zurück)
                        events: {
                            onChange: function (params) {
                                editorElement.value = params && typeof params.data === 'string'
                                    ? params.data
                                    : editor_<?php echo self::$editorCount; ?>.$.html.get();
                            }
                        }
                    });

                    // Registry-Adapter mit getContents()/setContents() für admin-content-editor.js
                    var legacyAdapter = {
                        editor: editor_<?php echo self::$editorCount; ?>,
                        getContents: function () { return editor_<?php echo self::$editorCount; ?>.$.html.get(); },
                        setContents: function (html) {
                            editor_<?php echo self::$editorCount; ?>.$.html.set(String(html || ''));
                            editorElement.value = editor_<?php echo self::$editorCount; ?>.$.html.get();
                        }
                    };
                    window.cmsLegacyEditors = window.cmsLegacyEditors || { byId: {}, byName: {} };
                    if (editorElement.id) {
                        window.cmsLegacyEditors.byId[editorElement.id] = legacyAdapter;
                    }
                    if (editorElement.name) {
                        window.cmsLegacyEditors.byName[editorElement.name] = legacyAdapter;
                    }

                    // Auch vor Form-Submit sicherstellen dass der Wert aktuell ist
                    var form = editorElement.closest('form');
                    if (form) {
                        form.addEventListener('submit', function() {
                            editorElement.value = editor_<?php echo self::$editorCount; ?>.$.html.get();
                        });
                    }
                } catch (error) {
                    console.error('Failed to initialize SunEditor:', error);
                    showFallback(editorElement);
                }
            }
        })();
        </script>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Enqueue editor assets (CSS and JS)
     * Called via admin_head hook
     * 
     * @return void
     */
    public function enqueueEditorAssets(): void
    {
        if (self::$assetsEnqueued) {
            return;
        }

        // Bei Editor.js an den spezialisierten Service delegieren
        if (self::isEditorJs()) {
            EditorJsService::getInstance()->enqueueEditorAssets();
            self::$assetsEnqueued = true;
            return;
        }
        
        $assetUrl = static fn(string $path): string => htmlspecialchars(
            function_exists('cms_asset_url') ? \cms_asset_url($path) : (defined('SITE_URL') ? SITE_URL : '') . '/assets/' . $path,
            ENT_QUOTES,
            'UTF-8'
        );
        
        echo "\n<!-- SunEditor Assets -->\n";
        echo '<link rel="stylesheet" href="' . $assetUrl('suneditor/css/suneditor.min.css') . '">' . "\n";
        echo '<script src="' . $assetUrl('suneditor/suneditor.min.js') . '" defer></script>' . "\n";
        echo '<script src="' . $assetUrl('suneditor/lang/de.js') . '" defer></script>' . "\n";
        echo "<!-- /SunEditor Assets -->\n\n";
        
        self::$assetsEnqueued = true;
    }
    
    /**
     * Render simple textarea (fallback without WYSIWYG)
     * 
     * @param string $name Field name
     * @param string $content Content
     * @param array $settings Settings (rows, cols, etc.)
     * @return string Textarea HTML
     */
    public function renderSimple(string $name, string $content = '', array $settings = []): string
    {
        $rows = $settings['rows'] ?? 10;
        $cols = $settings['cols'] ?? 50;
        $classes = $settings['class'] ?? 'form-control';
        
        $escapedContent = htmlspecialchars($content ?? '', ENT_QUOTES, 'UTF-8');
        
        return sprintf(
            '<textarea name="%s" rows="%d" cols="%d" class="%s">%s</textarea>',
            htmlspecialchars($name),
            (int)$rows,
            (int)$cols,
            htmlspecialchars($classes),
            $escapedContent
        );
    }
    
    /**
     * Sanitize editor content
     * 
     * Removes dangerous HTML while preserving safe formatting.
     * Bei Editor.js wird JSON sanitiert, bei SunEditor HTML.
     * 
     * @param string $content Raw content from editor
     * @return string Sanitized content
     */
    public function sanitize(string $content): string
    {
        // Editor.js-Daten: JSON sanitieren
        if (self::isEditorJs()) {
            return EditorJsService::getInstance()->sanitize($content);
        }

        // SunEditor: HTML sanitieren
        // Allow safe HTML tags
        $allowedTags = [
            'p', 'br', 'strong', 'em', 'u', 's', 'del', 'b', 'i',
            'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
            'ul', 'ol', 'li',
            'blockquote', 'pre', 'code',
            'a', 'img',
            'table', 'thead', 'tbody', 'tr', 'th', 'td',
            'div', 'span', 'hr',
            'sub', 'sup'
        ];
        
        $allowedAttributes = [
            'a' => ['href', 'title', 'target', 'rel'],
            'img' => ['src', 'alt', 'title', 'width', 'height', 'class', 'style'],
            'table' => ['class', 'border', 'cellpadding', 'cellspacing'],
            'td' => ['colspan', 'rowspan'],
            'th' => ['colspan', 'rowspan'],
            'div' => ['class', 'style'],
            'span' => ['class', 'style'],
            'p' => ['style'],
            'h1' => ['style'], 'h2' => ['style'], 'h3' => ['style'],
            'h4' => ['style'], 'h5' => ['style'], 'h6' => ['style']
        ];
        
        // Build allowed tags string
        $allowedTagsString = '<' . implode('><', $allowedTags) . '>';
        
        // Strip dangerous tags
        $sanitized = strip_tags($content, $allowedTagsString);
        
        // Remove dangerous attributes (event handlers, javascript:)
        $sanitized = preg_replace('/(<[^>]+)\s+on\w+\s*=\s*["\'][^"\']*["\']/i', '$1', $sanitized ?? '');
        $sanitized = preg_replace('/(<[^>]+)\s+href\s*=\s*["\']javascript:[^"\']*["\']/i', '$1', $sanitized ?? '');
        
        return $sanitized ?? '';
    }

    /**
     * Inhalt als HTML rendern (für Frontend-Ausgabe).
     *
     * Bei SunEditor ist der gespeicherte Inhalt bereits HTML.
     * Bei Editor.js wird der JSON-String über EditorJsRenderer zu HTML konvertiert.
     *
     * @param string $content Gespeicherter Inhalt (HTML oder Editor.js JSON)
     * @return string HTML-Ausgabe
     */
    public function renderContent(string $content): string
    {
        if ($content === '') {
            return '';
        }

        // Prüfe ob der Inhalt ein Editor.js JSON-Objekt ist.
        // Fehlerhafte Einzelblöcke werden im Renderer isoliert, damit der Artikel
        // nicht komplett leer bleibt.
        $decoded = Json::decodeArray($content, []);
        if (is_array($decoded) && isset($decoded['blocks'])) {
            return $this->renderEditorJsNormalized(EditorJsContentNormalizer::normalize($decoded));
        }

        // WordPress/Gutenberg-Blöcke und importiertes HTML in 365CMS-EditorJS-Blöcke
        // übersetzen. Wichtig für wp:media-text / Bild + Text nebeneinander.
        $normalized = EditorJsContentNormalizer::normalize($content);
        if (($normalized['blocks'] ?? []) !== []) {
            $html = $this->renderEditorJsNormalized($normalized);
            if ($html !== '') {
                return $html;
            }
        }

        // Letzter Fallback: reguläres HTML (SunEditor-/Legacy-Inhalt) nicht verlieren,
        // aber niemals unsanitisierte Import-/Fehler-Fragmente ins Frontend kippen.
        return str_contains($content, '<')
            ? EditorJsHtmlSanitizer::sanitizeRawBlock($content)
            : htmlspecialchars($content, ENT_QUOTES, 'UTF-8');
    }

    /**
     * @param array<string,mixed> $normalized
     */
    private function renderEditorJsNormalized(array $normalized): string
    {
        if (!class_exists(EditorJsRenderer::class)) {
            $rendererFile = __DIR__ . '/EditorJsRenderer.php';
            if (is_file($rendererFile)) {
                require_once $rendererFile;
            }
        }

        if (!class_exists(EditorJsRenderer::class, false)) {
            error_log('EditorService: EditorJsRenderer not available, using plain-text fallback.');
            return $this->renderEditorJsPlainFallback($normalized);
        }

        try {
            return EditorJsRenderer::getInstance()->render($normalized);
        } catch (\Throwable $e) {
            error_log('EditorService: EditorJsRenderer failed, fallback applied. ' . $e->getMessage());
            return $this->renderEditorJsPlainFallback($normalized);
        }
    }

    /**
     * @param array<string,mixed> $normalized
     */
    private function renderEditorJsPlainFallback(array $normalized): string
    {
        $blocks = is_array($normalized['blocks'] ?? null) ? $normalized['blocks'] : [];
        if ($blocks === []) {
            return '';
        }

        $parts = [];
        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }

            $data = is_array($block['data'] ?? null) ? $block['data'] : [];
            foreach (['text', 'title', 'message', 'caption', 'content', 'code'] as $key) {
                $value = trim(strip_tags((string) ($data[$key] ?? '')));
                if ($value !== '') {
                    $parts[] = $value;
                    break;
                }
            }

            $items = $data['items'] ?? null;
            if (is_array($items)) {
                foreach ($items as $item) {
                    $text = is_array($item)
                        ? trim(strip_tags((string) ($item['text'] ?? $item['content'] ?? '')))
                        : trim(strip_tags((string) $item));
                    if ($text !== '') {
                        $parts[] = '• ' . $text;
                    }
                }
            }
        }

        if ($parts === []) {
            return '';
        }

        $html = '';
        foreach ($parts as $part) {
            $html .= '<p>' . htmlspecialchars($part, ENT_QUOTES, 'UTF-8') . '</p>';
        }

        return $html;
    }
}
