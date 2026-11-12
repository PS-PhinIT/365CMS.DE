/**
 * Knowledgebase – Admin (/admin/knowledgebase)
 *
 * - Farbfelder: Farbwähler und Hex-Textfeld synchron halten ([data-color-sync]).
 * - Design-Tab: Live-Vorschau über CSS-Variablen ([data-kb-preview-box]).
 * - Editor: Inline-Formatierungsleiste für die Inhaltsbereiche wieder aktivieren (nur auf dem Editor).
 */
(function () {
    'use strict';

    function normalizeHex(value) {
        var normalized = String(value || '').trim();
        if (normalized.charAt(0) !== '#') {
            normalized = '#' + normalized;
        }

        return /^#[0-9a-fA-F]{6}$/.test(normalized) ? normalized.toUpperCase() : null;
    }

    function initColorSync() {
        document.querySelectorAll('[data-color-sync]').forEach(function (wrapper) {
            var colorInput = wrapper.querySelector('input[type="color"]');
            var textInput = wrapper.querySelector('input[type="text"]');
            if (!colorInput || !textInput) {
                return;
            }

            colorInput.addEventListener('input', function () {
                var normalized = normalizeHex(colorInput.value);
                if (normalized !== null) {
                    textInput.value = normalized;
                }
            });
            textInput.addEventListener('input', function () {
                var normalized = normalizeHex(textInput.value);
                if (normalized !== null) {
                    colorInput.value = normalized;
                    colorInput.dispatchEvent(new Event('change', { bubbles: true }));
                }
            });
        });
    }

    var PREVIEW_VARS = {
        design_accent_color: ['--kb-preview-accent', ''],
        design_accent_hover_color: ['--kb-preview-accent-hover', ''],
        design_surface_color: ['--kb-preview-surface', ''],
        design_border_color: ['--kb-preview-border', ''],
        design_border_radius: ['--kb-preview-radius', 'px'],
        tooltip_background_color: ['--kb-preview-tooltip-bg', ''],
        tooltip_text_color: ['--kb-preview-tooltip-text', ''],
        archive_card_bg_color: ['--kb-preview-card-bg', '']
    };

    function initPreview() {
        var form = document.querySelector('[data-kb-design-form]');
        var box = document.querySelector('[data-kb-preview-box]');
        if (!form || !box) {
            return;
        }

        var apply = function () {
            Object.keys(PREVIEW_VARS).forEach(function (name) {
                var field = form.querySelector('[data-kb-preview="' + name + '"]');
                if (!field || field.value === '') {
                    return;
                }
                box.style.setProperty(PREVIEW_VARS[name][0], field.value + PREVIEW_VARS[name][1]);
            });
        };

        form.addEventListener('input', apply);
        form.addEventListener('change', apply);
        apply();
    }

    document.addEventListener('DOMContentLoaded', function () {
        initColorSync();
        initPreview();
    });

    // Das Skript steht im Footer (nach dem Formular): ohne Editor-Formular keine Editor-Anpassung.
    if (!document.querySelector('.admin-knowledgebase-editor')) {
        return;
    }

    /*
     * Der Core-Editor (editor-init.js) registriert alle Blöcke mit inlineToolbar: false,
     * dadurch erscheint beim Markieren von Text keine Formatierungsleiste.
     * Für die KB-Inhaltsbereiche wird die Inline-Toolbar beim Erzeugen der
     * EditorJS-Instanz für Textblöcke wieder aktiviert.
     */
    var KB_INLINE_TEXT_BLOCKS = ['paragraph', 'header', 'list', 'quote', 'table', 'warning', 'alert', 'image', 'mediaText', 'accordion'];
    // Bewusst ohne 'hyperlink' (setzt unter EditorJS 2.31 keinen Link und hinterlässt Markierungs-Spans)
    // und ohne 'textColor' (Custom Element bricht beim Klonen des Inline-Popovers ab); stattdessen das eingebaute 'link'.
    var KB_INLINE_TOOL_ORDER = ['bold', 'italic', 'link', 'underline', 'marker', 'strikethrough', 'inlineCode'];
    var KB_BUILTIN_INLINE_TOOLS = ['bold', 'italic', 'link'];

    function resolveInlineTools(tools) {
        return KB_INLINE_TOOL_ORDER.filter(function (name) {
            return KB_BUILTIN_INLINE_TOOLS.indexOf(name) !== -1 || !!tools[name];
        });
    }

    function enableInlineToolbar(config) {
        if (!config || typeof config !== 'object' || !config.tools || typeof config.tools !== 'object') {
            return config;
        }

        var inlineTools = resolveInlineTools(config.tools);
        KB_INLINE_TEXT_BLOCKS.forEach(function (blockName) {
            var definition = config.tools[blockName];
            if (definition && typeof definition === 'object' && definition.inlineToolbar === false) {
                definition.inlineToolbar = inlineTools.slice();
            }
        });

        return config;
    }

    function wrapEditorJs(OriginalEditorJs) {
        if (typeof OriginalEditorJs !== 'function' || OriginalEditorJs.__kbInlineToolbarPatched) {
            return OriginalEditorJs;
        }

        var PatchedEditorJs = function (config) {
            return new OriginalEditorJs(enableInlineToolbar(config));
        };
        Object.setPrototypeOf(PatchedEditorJs, OriginalEditorJs);
        PatchedEditorJs.prototype = OriginalEditorJs.prototype;
        PatchedEditorJs.__kbInlineToolbarPatched = true;

        return PatchedEditorJs;
    }

    (function installEditorJsHook() {
        var descriptor = Object.getOwnPropertyDescriptor(window, 'EditorJS');
        if (descriptor && !descriptor.configurable) {
            window.EditorJS = wrapEditorJs(window.EditorJS);
            return;
        }

        var current = wrapEditorJs(window.EditorJS);
        Object.defineProperty(window, 'EditorJS', {
            configurable: true,
            enumerable: true,
            get: function () {
                return current;
            },
            set: function (value) {
                current = wrapEditorJs(value);
            }
        });
    })();
})();
