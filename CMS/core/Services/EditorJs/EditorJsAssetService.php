<?php
/**
 * Editor.js Asset- und Render-Service.
 *
 * @package CMSv2\Services
 */

declare(strict_types=1);

namespace CMS\Services\EditorJs;

if (!defined('ABSPATH')) {
    exit;
}

final class EditorJsAssetService
{
    private bool $assetsEnqueued = false;
    private int $editorCount = 0;

    /** @var string[] */
    private const EDITOR_JS_FILES = [
        'editorjs.umd.js',
        'paragraph.umd.js',
        'header.umd.js',
        'editorjs-list.umd.js',
        'image.umd.js',
        'quote.umd.js',
        'code.umd.js',
        'table.umd.js',
        'delimiter.umd.js',
        'embed.umd.js',
        'link.umd.js',
        'attaches.umd.js',
        'warning.umd.js',
        'alert.umd.js',
        'raw.umd.js',
        'inline-code.umd.js',
        'underline.umd.js',
        'strikethrough.umd.js',
        'hyperlink.umd.js',
        'text-color.umd.js',
        'spoiler.umd.js',
        'anchor.umd.js',
        'alignment-tune.umd.js',
        'indent-tune.umd.js',
        'text-variant-tune.umd.js',
        'accordion.umd.js',
        'undo.umd.js',
        'drag-drop.umd.js',
    ];

    /** @var string[] */
    private const EDITOR_CSS_FILES = [];

    public function render(string $name, string $content = '', array $settings = []): string
    {
        $this->editorCount++;
        $editorNum = $this->editorCount;
        $holderId  = $name . '_editorjs_' . $editorNum;
        $hiddenId  = $name . '_hidden_' . $editorNum;

        if (!$this->assetsEnqueued) {
            $this->enqueueEditorAssets();
        }

        $minHeight = (int) ($settings['height'] ?? 400);
        $escapedData = htmlspecialchars($content, ENT_QUOTES, 'UTF-8');
        $siteUrl = defined('SITE_URL') ? SITE_URL : '';
        $contentWidth = max(320, (int) ($settings['content_width'] ?? 1100));
        $expandedContentWidth = max($contentWidth, (int) ($settings['content_width_expanded'] ?? $contentWidth));
        $contentPaddingX = max(0, (int) ($settings['content_padding_x'] ?? 50));
        $contextClass = preg_replace('/[^a-z0-9_-]/i', '', (string) ($settings['context'] ?? 'default')) ?: 'default';
        $readOnly = !empty($settings['read_only']) || !empty($settings['readonly']);
        $editorLabel = trim((string) ($settings['aria_label'] ?? 'EditorJS Block-Editor')) ?: 'EditorJS Block-Editor';
        $csrfToken = class_exists(\CMS\Security::class)
            ? \CMS\Security::instance()->generateToken('editorjs_media')
            : '';
        $jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES;
        $holderIdJs = (string) json_encode($holderId, $jsonFlags);
        $hiddenIdJs = (string) json_encode($hiddenId, $jsonFlags);
        $countIdJs = (string) json_encode($holderId . '_count', $jsonFlags);
        $mediaUploadUrlJs = (string) json_encode(rtrim((string) $siteUrl, '/') . '/api/media', $jsonFlags);
        $csrfTokenJs = (string) json_encode($csrfToken, $jsonFlags);

        ob_start();
        ?>
        <input type="hidden"
               id="<?php echo htmlspecialchars($hiddenId, ENT_QUOTES); ?>"
               name="<?php echo htmlspecialchars($name, ENT_QUOTES); ?>"
               value="<?php echo $escapedData; ?>">

        <div class="editorjs-wrap editorjs-wrap--<?php echo htmlspecialchars($contextClass, ENT_QUOTES); ?>"
             id="<?php echo htmlspecialchars($holderId, ENT_QUOTES); ?>_wrap"
             style="--editorjs-content-width:<?php echo $contentWidth; ?>px; --editorjs-content-width-expanded:<?php echo $expandedContentWidth; ?>px; --editorjs-content-padding-x:<?php echo $contentPaddingX; ?>px;">
            <div class="editorjs-toolbar" id="<?php echo htmlspecialchars($holderId, ENT_QUOTES); ?>_toolbar" role="toolbar" aria-label="EditorJS Schnellwerkzeuge">
                <button type="button" data-block="header" data-level="2" title="Überschrift H2" aria-label="Überschrift H2 einfügen">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M7 4v16"/><path d="M7 12h10"/><path d="M17 4v16"/></svg>
                    <span>H2</span>
                </button>
                <button type="button" data-block="paragraph" title="Textabsatz" aria-label="Textabsatz einfügen">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 6h16"/><path d="M4 12h16"/><path d="M4 18h12"/></svg>
                    <span>Text</span>
                </button>
                <button type="button" data-block="list" title="Liste" aria-label="Liste einfügen">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 6h11"/><path d="M9 12h11"/><path d="M9 18h11"/><circle cx="5" cy="6" r="1" fill="currentColor"/><circle cx="5" cy="12" r="1" fill="currentColor"/><circle cx="5" cy="18" r="1" fill="currentColor"/></svg>
                    <span>Liste</span>
                </button>
                <button type="button" data-block="list" data-style="checklist" title="Checkliste" aria-label="Checkliste einfügen">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 6h11"/><path d="M9 12h11"/><path d="M9 18h11"/><path d="M4 6l1 1l2-2"/><path d="M4 12l1 1l2-2"/><path d="M4 18l1 1l2-2"/></svg>
                    <span>Check</span>
                </button>
                <button type="button" data-block="image" title="Bild" aria-label="Bild einfügen">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="3"/><circle cx="9" cy="9" r="1.5"/><path d="M3 16l5-5c1-.9 2.1-.9 3 0l5 5"/><path d="M14 14l1-1c1-.9 2.1-.9 3 0l3 3"/></svg>
                    <span>Bild</span>
                </button>
                <button type="button" data-block="mediaText" title="Bild + Text" aria-label="Bild-und-Text-Block einfügen">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="8" height="8" rx="2"/><path d="M14 6h7"/><path d="M14 10h7"/><path d="M3 16h18"/><path d="M3 20h14"/></svg>
                    <span>Bild+Text</span>
                </button>
                <button type="button" data-block="imageGallery" data-columns="3" title="Bildergalerie" aria-label="Bildergalerie einfügen">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
                    <span>Galerie</span>
                </button>
                <button type="button" data-block="embed" title="Embed" aria-label="Embed einfügen">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M8 5v14l11-7z"/><path d="M3 5h2v14H3z"/></svg>
                    <span>Embed</span>
                </button>
                <button type="button" data-block="linkTool" title="Link-Karte" aria-label="Link-Karte einfügen">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 13a5 5 0 0 0 7.07 0l2-2a5 5 0 0 0-7.07-7.07l-1.15 1.15"/><path d="M14 11a5 5 0 0 0-7.07 0l-2 2A5 5 0 0 0 12 20.07l1.15-1.15"/></svg>
                    <span>Link</span>
                </button>
                <button type="button" data-block="button" title="Button" aria-label="Button einfügen">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="8" width="18" height="8" rx="4"/><path d="M8 12h8"/></svg>
                    <span>Button</span>
                </button>
                <button type="button" data-block="attaches" title="Anhang" aria-label="Anhang einfügen">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.82-2.83l8.48-8.48"/></svg>
                    <span>Anhang</span>
                </button>
                <button type="button" data-block="table" title="Tabelle (3×3)" aria-label="Tabelle einfügen">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18"/><path d="M3 15h18"/><path d="M9 3v18"/></svg>
                    <span>Tabelle</span>
                </button>
                <button type="button" data-block="code" title="Code" aria-label="Code-Block einfügen">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 18l6-6l-6-6"/><path d="M8 6l-6 6l6 6"/></svg>
                    <span>Code</span>
                </button>
                <button type="button" data-block="quote" title="Zitat" aria-label="Zitat einfügen">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 11h-4a1 1 0 01-1-1v-3a1 1 0 011-1h3a1 1 0 011 1v6c0 2.667-1.333 4.333-4 5"/><path d="M19 11h-4a1 1 0 01-1-1v-3a1 1 0 011-1h3a1 1 0 011 1v6c0 2.667-1.333 4.333-4 5"/></svg>
                    <span>Zitat</span>
                </button>
                <button type="button" data-block="warning" title="Hinweis" aria-label="Hinweisbox einfügen">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 9v4"/><path d="M12 17h.01"/><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/></svg>
                    <span>Hinweis</span>
                </button>
                <button type="button" data-block="alert" data-variant="info" title="Alert" aria-label="Alert-Box einfügen">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16v12H5.17L4 17.17z"/><path d="M8 8h8"/><path d="M8 12h5"/></svg>
                    <span>Alert</span>
                </button>
                <button type="button" data-block="accordion" title="Akkordeon" aria-label="Akkordeon einfügen">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 9l6 6l6-6"/><path d="M4 4h16"/><path d="M4 20h16"/></svg>
                    <span>Akkordeon</span>
                </button>
                <button type="button" data-block="delimiter" title="Trennlinie" aria-label="Trennlinie einfügen">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 12h16"/></svg>
                    <span>Trenner</span>
                </button>
                <button type="button" data-block="spacer" data-height="40" title="Abstand" aria-label="Abstand einfügen">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 3v18"/><path d="M8 7l4-4l4 4"/><path d="M8 17l4 4l4-4"/></svg>
                    <span>Abstand</span>
                </button>
                <button type="button" data-block="raw" title="HTML" aria-label="HTML-Block einfügen">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M8 9l-4 3l4 3"/><path d="M16 9l4 3l-4 3"/><path d="M14 4l-4 16"/></svg>
                    <span>HTML</span>
                </button>
            </div>

            <div id="<?php echo htmlspecialchars($holderId, ENT_QUOTES); ?>"
                 class="editorjs-holder"
                  role="region"
                  aria-label="<?php echo htmlspecialchars($editorLabel, ENT_QUOTES); ?>"
                  aria-busy="true"
                 style="min-height:<?php echo $minHeight; ?>px;"></div>

              <div class="editorjs-statusbar" role="status" aria-live="polite">
                <span class="editorjs-statusbar__hint">Tippe <kbd>/</kbd> oder nutze das <strong>+</strong>-Menü für alle Plugins</span>
                <span class="editorjs-statusbar__count" id="<?php echo htmlspecialchars($holderId, ENT_QUOTES); ?>_count"></span>
            </div>
        </div>

        <script <?= \CMS\Security::instance()->nonceAttr() ?>>
        (function() {
            function initEditorJs<?php echo $editorNum; ?>() {
                var holderEl = document.getElementById(<?php echo $holderIdJs; ?>);
                var hiddenEl = document.getElementById(<?php echo $hiddenIdJs; ?>);
                if (!holderEl || !hiddenEl) {
                    return;
                }

                var raw = hiddenEl.value || '';
                if (typeof window.createCmsEditor !== 'function') {
                    console.error('editor-init.js not loaded');
                    return;
                }

                var editor;
                var form = holderEl.closest('form');
                var saveStateKey = String(<?php echo $hiddenIdJs; ?>);
                if (form) {
                    form.cmsEditorJsSaveStates = form.cmsEditorJsSaveStates || {};
                    form.cmsEditorJsSaveStates[saveStateKey] = 'dirty';
                }
                var countEl = document.getElementById(<?php echo $countIdJs; ?>);
                var updateBlockCount = function() {
                    if (!countEl || !editor || !editor.blocks) {
                        return;
                    }
                    var count = editor.blocks.getBlocksCount();
                    countEl.textContent = count + (count === 1 ? ' Block' : ' Blöcke');
                };
                var syncHiddenValue = function(outputData) {
                    var normalizedOutput = typeof window.cmsNormalizeEditorJsData === 'function'
                        ? window.cmsNormalizeEditorJsData(outputData)
                        : outputData;
                    hiddenEl.value = JSON.stringify(normalizedOutput);
                };
                try {
                    editor = window.createCmsEditor(
                        <?php echo $holderIdJs; ?>,
                        raw,
                        <?php echo $mediaUploadUrlJs; ?>,
                        <?php echo $csrfTokenJs; ?>,
                        {
                            readOnly: <?php echo $readOnly ? 'true' : 'false'; ?>,
                            onReady: function() {
                                holderEl.setAttribute('aria-busy', 'false');
                                updateBlockCount();
                            },
                            onChange: function(outputData) {
                                syncHiddenValue(outputData);
                                if (form && form.cmsEditorJsSaveStates) {
                                    form.cmsEditorJsSaveStates[saveStateKey] = 'dirty';
                                }
                            },
                            onError: function(error, context) {
                                console.error('Editor.js runtime error:', context || {}, error || null);
                            }
                        }
                    );
                } catch (error) {
                    holderEl.setAttribute('aria-busy', 'false');
                    console.error('Editor.js init error:', error);
                    alert('Editor.js konnte nicht initialisiert werden. Bitte Seite neu laden oder Logs prüfen.');
                    return;
                }

                var toolbar = document.getElementById('<?php echo $holderId; ?>_toolbar');
                if (toolbar) {
                    if (editor.cmsReadOnly) {
                        Array.prototype.slice.call(toolbar.querySelectorAll('button[data-block]')).forEach(function(button) {
                            button.disabled = true;
                            button.setAttribute('aria-disabled', 'true');
                        });
                    }
                    toolbar.addEventListener('click', function(event) {
                        var btn = event.target.closest('button[data-block]');
                        if (!btn || !editor || !editor.blocks || editor.cmsReadOnly) {
                            return;
                        }

                        var blockType = btn.getAttribute('data-block');
                        var blockData = {};
                        var level = btn.getAttribute('data-level');
                        var height = btn.getAttribute('data-height');
                        var columns = btn.getAttribute('data-columns');
                        var style = btn.getAttribute('data-style');
                        var variant = btn.getAttribute('data-variant');
                        if (level) {
                            blockData.level = parseInt(level, 10);
                        }
                        if (style) {
                            blockData.style = style;
                            if (blockType === 'list' && style === 'checklist') {
                                blockData.items = [{ content: '', meta: { checked: false }, items: [] }];
                            }
                        }
                        if (height) {
                            blockData.height = parseInt(height, 10);
                            blockData.preset = height + 'px';
                        }
                        if (columns) {
                            blockData.columns = parseInt(columns, 10);
                        }
                        if (variant) {
                            blockData.variant = variant;
                            if (blockType === 'alert') {
                                blockData.type = variant;
                                blockData.align = 'left';
                            }
                        }
                        try {
                            editor.blocks.insert(blockType, blockData);

                            var lastIndex = editor.blocks.getBlocksCount() - 1;
                            if (editor.caret && typeof editor.caret.setToBlock === 'function') {
                                editor.caret.setToBlock(lastIndex, 'start');
                            }
                            updateBlockCount();
                        } catch (error) {
                            console.error('Editor.js toolbar insert error:', error);
                            alert('Der Block konnte nicht eingefügt werden. Bitte Editor-Konsole prüfen.');
                        }
                    });
                }

                if (editor.isReady && typeof editor.isReady.then === 'function') {
                    editor.isReady.then(function() {
                        holderEl.setAttribute('aria-busy', 'false');
                        updateBlockCount();
                    }).catch(function(error) {
                        holderEl.setAttribute('aria-busy', 'false');
                        console.error('Editor.js ready error:', error);
                        alert('Editor.js konnte nicht vollständig initialisiert werden. Bitte Seite neu laden oder Logs prüfen.');
                    });
                } else {
                    holderEl.setAttribute('aria-busy', 'false');
                    updateBlockCount();
                }

                var intervalId = window.setInterval(updateBlockCount, 2000);
                window.setTimeout(function() {
                    window.clearInterval(intervalId);
                }, 300000);

                window.addEventListener('pagehide', function() {
                    window.clearInterval(intervalId);
                    if (editor && typeof editor.destroy === 'function') {
                        try {
                            editor.destroy();
                        } catch (error) {
                            console.warn('Editor.js destroy during pagehide failed:', error);
                        }
                    }
                }, { once: true });

                if (form) {
                    var lastSubmitter = null;
                    var submitFormSafely = function(submitter) {
                        var resolvedSubmitter = submitter && submitter.form === form ? submitter : null;

                        if (typeof window.cmsSubmitFormSafely === 'function') {
                            window.cmsSubmitFormSafely(form, resolvedSubmitter);
                            return;
                        }

                        if (typeof form.requestSubmit === 'function') {
                            form.requestSubmit(resolvedSubmitter || undefined);
                            return;
                        }

                        var fallbackSubmitter = document.createElement('button');
                        fallbackSubmitter.type = 'submit';
                        fallbackSubmitter.hidden = true;
                        fallbackSubmitter.setAttribute('aria-hidden', 'true');
                        form.appendChild(fallbackSubmitter);
                        fallbackSubmitter.click();
                        fallbackSubmitter.remove();
                    };

                    form.addEventListener('click', function(event) {
                        var target = event.target && event.target.closest ? event.target.closest('button, input') : null;
                        if (!target || target.form !== form) {
                            return;
                        }

                        var tagName = String(target.tagName || '').toLowerCase();
                        var type = String(target.getAttribute('type') || (tagName === 'button' ? 'submit' : '')).toLowerCase();
                        if (type === 'submit' || type === 'image') {
                            lastSubmitter = target;
                        }
                    }, true);

                    form.addEventListener('submit', function(e) {
                        var saveStates = form.cmsEditorJsSaveStates || {};
                        if (saveStates[saveStateKey] === 'saved') {
                            return;
                        }

                        e.preventDefault();
                        if (saveStates[saveStateKey] === 'saving') {
                            return;
                        }

                        saveStates[saveStateKey] = 'saving';
                        var submitter = e.submitter || lastSubmitter;

                        editor.save().then(function(outputData) {
                            syncHiddenValue(outputData);
                            saveStates[saveStateKey] = 'saved';

                            var allEditorsSaved = Object.keys(saveStates).every(function(key) {
                                return saveStates[key] === 'saved';
                            });
                            if (allEditorsSaved) {
                                // Erst nach Abschluss des laufenden submit-Events erneut absenden:
                                // requestSubmit() wird während der Event-Verteilung ignoriert.
                                window.setTimeout(function() {
                                    submitFormSafely(submitter);
                                }, 0);
                            }
                        }).catch(function(err) {
                            console.error('Editor.js save error:', err);
                            saveStates[saveStateKey] = 'failed';
                            alert('Der Editor-Inhalt konnte nicht gespeichert werden. Bitte problematische Blöcke prüfen und erneut speichern.');
                            if (typeof holderEl.focus === 'function') {
                                holderEl.focus({ preventScroll: true });
                            }
                        });
                    });
                }
            }

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', initEditorJs<?php echo $editorNum; ?>);
            } else {
                initEditorJs<?php echo $editorNum; ?>();
            }
        })();
        </script>
        <?php

        return (string) ob_get_clean();
    }

    /**
     * @return array{css: string[], js: string[]}
     */
    public function getPageAssets(): array
    {
        return [
            'css' => $this->getEditorCssUrls(),
            'js' => $this->getEditorJsUrls(),
        ];
    }

    public function enqueueEditorAssets(): void
    {
        if ($this->assetsEnqueued) {
            return;
        }

        echo "\n<!-- Editor.js Assets -->\n";

        foreach ($this->getEditorCssUrls() as $cssUrl) {
            echo '<link rel="stylesheet" href="' . htmlspecialchars($cssUrl, ENT_QUOTES, 'UTF-8') . '">' . "\n";
        }

        foreach ($this->getEditorJsUrls() as $jsUrl) {
            echo '<script src="' . htmlspecialchars($jsUrl, ENT_QUOTES, 'UTF-8') . '" defer></script>' . "\n";
        }

        echo "<!-- /Editor.js Assets -->\n\n";
        $this->assetsEnqueued = true;
    }

    /**
     * @return string[]
     */
    private function getEditorJsUrls(): array
    {
        $urls = [];

        foreach (self::EDITOR_JS_FILES as $file) {
            $urls[] = $this->buildAssetUrl('editorjs/' . $file);
        }

        // Zusätzliche Core-Blöcke (EditorJsBlockRegistry) vor der Factory laden.
        foreach (EditorJsBlockRegistry::scripts() as $script) {
            $urls[] = $this->buildAssetUrl($script);
        }

        $urls[] = $this->buildAssetUrl('js/editor-init.js');

        return $urls;
    }

    /**
     * @return string[]
     */
    private function getEditorCssUrls(): array
    {
        $urls = [];

        foreach (self::EDITOR_CSS_FILES as $file) {
            $assetPath = defined('ASSETS_PATH') ? ASSETS_PATH . 'editorjs/' . $file : null;
            if ($assetPath !== null && file_exists($assetPath)) {
                $urls[] = $this->buildAssetUrl('editorjs/' . $file);
            }
        }

        return $urls;
    }

    private function buildAssetUrl(string $relativePath): string
    {
        return \cms_asset_url($relativePath);
    }
}
