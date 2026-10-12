'use strict';

(function () {
    var BLOCK_TOOL_NAMES = [
        'paragraph',
        'header',
        'list',
        'image',
        'quote',
        'code',
        'table',
        'delimiter',
        'spacer',
        'embed',
        'linkTool',
        'attaches',
        'warning',
        'alert',
        'raw',
        'accordion',
        'imageGallery',
        'mediaText',
        'button'
    ];
    var INLINE_TOOL_NAMES = ['inlineCode', 'underline', 'strikethrough', 'hyperlink', 'marker', 'spoiler', 'textColor'];
    var TUNE_TOOL_NAMES = ['anchor', 'alignmentTune', 'indentTune', 'textVariant', 'blockClipboard'];
    var PLUGIN_NAMES = ['undo', 'dragDrop'];
    var TOOL_NAMES = BLOCK_TOOL_NAMES.concat(INLINE_TOOL_NAMES, TUNE_TOOL_NAMES);
    var VERSION = 'cms-editorjs-org-assets-2026-06-05-paste-sanitize-warning-colors';
    var CMS_BLOCK_CLIPBOARD_PREFIX = 'CMS_EDITORJS_BLOCKS_V1:';
    // Deutsche Oberfläche für Editor.js-Core, Plugins und Block-Tunes (i18n.messages).
    var EDITOR_I18N_DE = {
        ui: {
            blockTunes: {
                toggler: {
                    'Click to tune': 'Klicken zum Anpassen',
                    'or drag to move': 'oder ziehen zum Verschieben'
                }
            },
            inlineToolbar: {
                converter: {
                    'Convert to': 'Umwandeln in'
                }
            },
            toolbar: {
                toolbox: {
                    'Add': 'Hinzufügen'
                }
            },
            popover: {
                'Filter': 'Filtern',
                'Search': 'Suchen',
                'Nothing found': 'Nichts gefunden',
                'Convert to': 'Umwandeln in'
            }
        },
        toolNames: {
            'Text': 'Text',
            'Heading': 'Überschrift',
            'List': 'Liste',
            'Unordered List': 'Aufzählung',
            'Ordered List': 'Nummerierte Liste',
            'Checklist': 'Checkliste',
            'Warning': 'Hinweis',
            'Quote': 'Zitat',
            'Code': 'Code',
            'Delimiter': 'Trennlinie',
            'Raw HTML': 'HTML',
            'Table': 'Tabelle',
            'Link': 'Link',
            'Hyperlink': 'Link',
            'Marker': 'Markierung',
            'Bold': 'Fett',
            'Italic': 'Kursiv',
            'InlineCode': 'Inline-Code',
            'Underline': 'Unterstrichen',
            'Strikethrough': 'Durchgestrichen',
            'Image': 'Bild',
            'Attachment': 'Anhang',
            'Alert': 'Alert-Box',
            'Accordion': 'Akkordeon',
            'Embed': 'Einbettung',
            'Spoiler': 'Spoiler',
            'Color': 'Farbe',
            'Text Color': 'Textfarbe',
            'Anchor': 'Anker'
        },
        tools: {
            stub: {
                'The block can not be displayed correctly.': 'Der Block kann nicht korrekt angezeigt werden.'
            },
            link: {
                'Add a link': 'Link einfügen'
            },
            hyperlink: {
                'Save': 'Übernehmen',
                'Select target': 'Ziel wählen',
                'Select rel': 'rel-Attribut wählen'
            },
            paragraph: {
                'Enter something': 'Text eingeben'
            },
            header: {
                'Heading 1': 'Überschrift 1',
                'Heading 2': 'Überschrift 2',
                'Heading 3': 'Überschrift 3',
                'Heading 4': 'Überschrift 4',
                'Heading 5': 'Überschrift 5',
                'Heading 6': 'Überschrift 6'
            },
            list: {
                'Unordered': 'Aufzählung',
                'Ordered': 'Nummeriert',
                'Checklist': 'Checkliste',
                'Counter type': 'Zählweise',
                'Start with': 'Beginnen mit',
                'Numeric': 'Zahlen',
                'Lower Roman': 'Römisch (klein)',
                'Upper Roman': 'Römisch (groß)',
                'Lower Alpha': 'Buchstaben (klein)',
                'Upper Alpha': 'Buchstaben (groß)'
            },
            image: {
                'Caption': 'Bildunterschrift',
                'Select an Image': 'Bild auswählen',
                'With border': 'Mit Rahmen',
                'Stretch image': 'Bild strecken',
                'With background': 'Mit Hintergrund',
                'With caption': 'Mit Bildunterschrift',
                'Couldn’t upload image. Please try another.': 'Das Bild konnte nicht hochgeladen werden. Bitte ein anderes versuchen.'
            },
            quote: {
                'Enter a quote': 'Zitat eingeben',
                'Enter a caption': 'Quelle eingeben',
                'Align Left': 'Linksbündig',
                'Align Center': 'Zentriert'
            },
            code: {
                'Enter a code': 'Code eingeben'
            },
            warning: {
                'Title': 'Titel',
                'Message': 'Hinweistext'
            },
            embed: {
                'Enter a caption': 'Bildunterschrift eingeben'
            },
            linkTool: {
                'Link': 'Link',
                "Couldn't fetch the link data": 'Die Link-Daten konnten nicht abgerufen werden',
                "Couldn't get this link data, try the other one": 'Für diesen Link sind keine Daten verfügbar, bitte einen anderen versuchen',
                'Wrong response format from the server': 'Unerwartetes Antwortformat vom Server'
            },
            attaches: {
                'File title': 'Dateititel',
                'Select file to upload': 'Datei zum Hochladen auswählen'
            },
            table: {
                'Heading': 'Überschrift',
                'With headings': 'Mit Kopfzeile',
                'Without headings': 'Ohne Kopfzeile',
                'Stretch': 'Volle Breite',
                'Collapse': 'Normale Breite',
                'Add column to left': 'Spalte links einfügen',
                'Add column to right': 'Spalte rechts einfügen',
                'Add row above': 'Zeile oberhalb einfügen',
                'Add row below': 'Zeile unterhalb einfügen',
                'Delete column': 'Spalte löschen',
                'Delete row': 'Zeile löschen'
            },
            accordion: {
                'Settings': 'Einstellungen',
                'Block Count': 'Anzahl Blöcke',
                'Default Expanded': 'Standardmäßig geöffnet',
                'Save': 'Übernehmen'
            },
            textVariant: {
                'Call-out': 'Hervorhebung',
                'Citation': 'Zitat',
                'Details': 'Details'
            },
            indentTune: {
                'Indent': 'Einrücken'
            }
        },
        blockTunes: {
            delete: {
                'Delete': 'Löschen',
                'Click to delete': 'Zum Löschen erneut klicken'
            },
            moveUp: {
                'Move up': 'Nach oben'
            },
            moveDown: {
                'Move down': 'Nach unten'
            },
            textVariant: {
                'Call-out': 'Hervorhebung',
                'Citation': 'Zitat',
                'Details': 'Details'
            },
            indentTune: {
                'Indent': 'Einrücken'
            }
        }
    };
    var THEME_PREVIEW_STYLE_CACHE = {};
    var TOOL_GLOBALS = {
        paragraph: ['CmsParagraphTool', 'Paragraph'],
        header: ['CmsHeaderTool', 'Header'],
        list: ['EditorjsList', 'List', 'CmsListTool'],
        image: ['CmsImageTool', 'ImageTool'],
        quote: ['CmsQuoteTool', 'Quote'],
        code: ['CmsCodeTool', 'CodeTool'],
        table: ['Table'],
        delimiter: ['CmsDelimiterTool', 'Delimiter'],
        spacer: ['CmsSpacerTool'],
        embed: ['Embed'],
        linkTool: ['LinkTool'],
        attaches: ['AttachesTool'],
        warning: ['CmsWarningTool', 'Warning'],
        alert: ['Alert'],
        raw: ['RawTool'],
        accordion: ['Accordion', 'AccordionBlock'],
        imageGallery: ['CmsImageGalleryTool'],
        mediaText: ['CmsMediaTextTool'],
        button: ['CmsButtonTool'],
        inlineCode: ['InlineCode'],
        underline: ['Underline'],
        strikethrough: ['Strikethrough'],
        hyperlink: ['Hyperlink'],
        marker: ['CmsMarkerTool', 'Marker'],
        spoiler: ['TgSpoilerEditorJS', 'Spoiler'],
        textColor: ['ColorPlugin'],
        anchor: ['Anchor'],
        alignmentTune: ['AlignmentBlockTune'],
        indentTune: ['IndentPlugin'],
        textVariant: ['TextVariantTune'],
        blockClipboard: ['CmsBlockClipboardTune']
    };
    var PLUGIN_GLOBALS = {
        undo: ['Undo'],
        dragDrop: ['DragDrop']
    };
    var TOOL_ALIASES = {
        checklist: 'list',
        link: 'linkTool',
        gallery: 'imageGallery',
        image_gallery: 'imageGallery',
        imageGallery: 'imageGallery',
        media_text: 'mediaText',
        mediatext: 'mediaText',
        callout: 'warning',
        space: 'spacer',
        details: 'accordion'
    };

    function logInfo(message, payload) {
        if (typeof console !== 'undefined' && typeof console.info === 'function') {
            console.info('[EditorJS][CMS] ' + message, payload || null);
        }
    }

    function logWarn(message, payload) {
        if (typeof console !== 'undefined' && typeof console.warn === 'function') {
            console.warn('[EditorJS][CMS] ' + message, payload || null);
        }
    }

    function stripTags(value) {
        var element = document.createElement('div');
        element.innerHTML = String(value || '').replace(/<br\s*\/?>/gi, '\n');
        return (element.textContent || element.innerText || '').trim();
    }

    function escapeHtml(value) {
        return String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function normalizeCmsClipboardBlock(block) {
        var source = block && typeof block === 'object' ? block : {};
        var type = String(source.type || source.tool || '');

        type = TOOL_ALIASES[type] || type;
        if (BLOCK_TOOL_NAMES.indexOf(type) === -1) {
            return null;
        }

        return {
            type: type,
            data: source.data && typeof source.data === 'object' ? source.data : {},
            tunes: source.tunes && typeof source.tunes === 'object' ? source.tunes : {}
        };
    }

    function createCmsBlockClipboardText(blocks) {
        var normalizedBlocks = (Array.isArray(blocks) ? blocks : [])
            .map(normalizeCmsClipboardBlock)
            .filter(Boolean);

        if (normalizedBlocks.length === 0) {
            return '';
        }

        return CMS_BLOCK_CLIPBOARD_PREFIX + JSON.stringify({
            format: '365cms-editorjs-blocks',
            version: 1,
            blocks: normalizedBlocks
        });
    }

    function parseCmsBlockClipboardText(value) {
        var text = String(value || '').trim();
        var payload;
        var blocks;

        if (text.indexOf(CMS_BLOCK_CLIPBOARD_PREFIX) !== 0 || text.length > 2 * 1024 * 1024) {
            return [];
        }

        try {
            payload = JSON.parse(text.slice(CMS_BLOCK_CLIPBOARD_PREFIX.length));
        } catch (_error) {
            return [];
        }

        if (!payload || payload.format !== '365cms-editorjs-blocks' || payload.version !== 1 || !Array.isArray(payload.blocks)) {
            return [];
        }

        blocks = payload.blocks.slice(0, 100)
            .map(normalizeCmsClipboardBlock)
            .filter(Boolean)
            .map(function (block) {
                return normalizeBlock(block);
            });

        return blocks;
    }

    function fallbackCopyText(text) {
        var textarea = document.createElement('textarea');
        var copied = false;

        textarea.value = text;
        textarea.setAttribute('readonly', 'readonly');
        textarea.style.position = 'fixed';
        textarea.style.left = '-9999px';
        textarea.style.top = '0';
        document.body.appendChild(textarea);
        textarea.select();
        try {
            copied = document.execCommand('copy');
        } catch (_error) {
            copied = false;
        }
        textarea.remove();

        return copied;
    }

    function writeCmsBlocksToClipboard(blocks) {
        var text = createCmsBlockClipboardText(blocks);

        if (text === '') {
            return Promise.reject(new Error('Der Block konnte nicht serialisiert werden.'));
        }

        if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
            return navigator.clipboard.writeText(text).then(function () {
                return true;
            }).catch(function () {
                return fallbackCopyText(text);
            });
        }

        return Promise.resolve(fallbackCopyText(text));
    }

    function showCmsEditorNotice(api, message, type) {
        try {
            if (api && api.notifier && typeof api.notifier.show === 'function') {
                api.notifier.show({ message: message, style: type === 'error' ? 'error' : 'success' });
                return;
            }
        } catch (_error) {
            // Console fallback below.
        }

        if (type === 'error') {
            logWarn(message);
        } else {
            logInfo(message);
        }
    }

    function sanitizeEditableUrl(value) {
        var url = String(value || '').trim();
        var parser;

        if (url === '' || /[\u0000\r\n<>"']/.test(url)) {
            return '';
        }

        if (/^(?:\/|\.\/|\.\.\/|#)/.test(url)) {
            return url;
        }

        try {
            parser = new URL(url, window.location.origin);
        } catch (_error) {
            return '';
        }

        return /^(https?:|mailto:|tel:)$/i.test(parser.protocol) ? url : '';
    }

    function sanitizeImageSourceUrl(value) {
        var url = sanitizeEditableUrl(value);

        return /^https?:\/\//i.test(url) ? url : '';
    }

    function sanitizeEditableColor(value) {
        var color = String(value || '').trim();

        if (/^#[0-9a-f]{3,8}$/i.test(color)) {
            return color;
        }

        if (/^rgba?\(\s*(?:\d{1,3}\s*,\s*){2}\d{1,3}(?:\s*,\s*(?:0|1|0?\.\d+))?\s*\)$/i.test(color)) {
            return color;
        }

        if (/^var\(--[a-z0-9_-]+\)$/i.test(color)) {
            return color;
        }

        return '';
    }

    function sanitizeEditableColorStyle(value) {
        var declarations = [];

        String(value || '').split(';').forEach(function (declaration) {
            var separatorIndex = declaration.indexOf(':');
            var property;
            var color;

            if (separatorIndex <= 0) {
                return;
            }

            property = declaration.slice(0, separatorIndex).trim().toLowerCase();
            if (['color', 'background-color'].indexOf(property) === -1) {
                return;
            }

            color = sanitizeEditableColor(declaration.slice(separatorIndex + 1));
            if (color !== '') {
                declarations.push(property + ': ' + color);
            }
        });

        return declarations.join('; ');
    }

    function sanitizeEditableNode(node, allowBlocks) {
        var allowedTags = {
            a: true,
            b: true,
            strong: true,
            i: true,
            em: true,
            u: true,
            s: true,
            strike: true,
            del: true,
            code: true,
            mark: true,
            font: true,
            sub: true,
            sup: true,
            br: true,
            span: true
        };
        var blockedTags = {
            script: true,
            style: true,
            object: true,
            embed: true,
            iframe: true,
            form: true,
            input: true,
            button: true,
            textarea: true,
            select: true,
            svg: true,
            math: true,
            link: true,
            meta: true
        };
        if (allowBlocks) {
            ['p', 'ul', 'ol', 'li', 'blockquote'].forEach(function (tagName) {
                allowedTags[tagName] = true;
            });
        }

        var children = Array.prototype.slice.call(node.childNodes || []);

        children.forEach(function (child) {
            var tagName;
            var href;
            var title;
            var className;
            var originalAttributes = {};

            if (child.nodeType !== 1) {
                return;
            }

            tagName = String(child.tagName || '').toLowerCase();
            sanitizeEditableNode(child, allowBlocks);

            if (blockedTags[tagName]) {
                child.parentNode.removeChild(child);
                return;
            }

            if (!allowedTags[tagName]) {
                while (child.firstChild) {
                    child.parentNode.insertBefore(child.firstChild, child);
                }
                child.parentNode.removeChild(child);
                return;
            }

            Array.prototype.slice.call(child.attributes || []).forEach(function (attribute) {
                originalAttributes[String(attribute.name || '').toLowerCase()] = String(attribute.value || '');
                child.removeAttribute(attribute.name);
            });

            if (tagName === 'a') {
                href = sanitizeEditableUrl(originalAttributes.href || '');
                var target = String(originalAttributes.target || '').trim().toLowerCase();
                var rel = String(originalAttributes.rel || '').trim().toLowerCase();
                var relTokens = [];
                if (href === '') {
                    while (child.firstChild) {
                        child.parentNode.insertBefore(child.firstChild, child);
                    }
                    child.parentNode.removeChild(child);
                    return;
                }

                child.setAttribute('href', href);
                title = String(originalAttributes.title || '').trim();
                if (title !== '') {
                    child.setAttribute('title', title.slice(0, 160));
                }

                if (['_blank', '_self'].indexOf(target) === -1) {
                    target = '';
                }
                if (/^https?:\/\//i.test(href) && target === '') {
                    target = '_blank';
                }
                if (target !== '') {
                    child.setAttribute('target', target);
                }

                rel.split(/\s+/).forEach(function (token) {
                    if (['noopener', 'noreferrer', 'nofollow', 'external', 'author', 'bookmark', 'license', 'tag'].indexOf(token) !== -1 && relTokens.indexOf(token) === -1) {
                        relTokens.push(token);
                    }
                });
                if (target === '_blank') {
                    if (relTokens.indexOf('noopener') === -1) {
                        relTokens.push('noopener');
                    }
                    if (relTokens.indexOf('noreferrer') === -1) {
                        relTokens.push('noreferrer');
                    }
                }
                if (relTokens.length > 0) {
                    child.setAttribute('rel', relTokens.join(' '));
                }
            }

            if (tagName === 's' || tagName === 'strike' || tagName === 'del') {
                className = String(originalAttributes.class || '');
                if (/\bcdx-strikethroughs?\b/.test(className) || /\bcdx-strikethrough\b/.test(className)) {
                    child.setAttribute('class', 'cdx-strikethrough');
                }
            }

            if (tagName === 'span') {
                className = String(originalAttributes.class || '');
                if (/\btg-spoiler\b/.test(className)) {
                    child.setAttribute('class', 'tg-spoiler');
                }

                var style = sanitizeEditableColorStyle(originalAttributes.style || '');
                if (style !== '') {
                    // CSSOM statt setAttribute('style'): Letzteres blockiert die CSP (style-src-attr).
                    child.style.cssText = style;
                }
            }

            if (tagName === 'font') {
                var color = sanitizeEditableColor(originalAttributes.color || originalAttributes.style || '');
                if (color !== '') {
                    child.setAttribute('color', color);
                }
            }

            if (tagName === 'code') {
                className = String(originalAttributes.class || '');
                if (/\blanguage-[a-z0-9_+\-#]+\b/i.test(className)) {
                    child.setAttribute('class', className.match(/\blanguage-[a-z0-9_+\-#]+\b/i)[0].toLowerCase());
                }
            }
        });
    }

    function sanitizeEditableHtml(html, allowBlocks) {
        var template = document.createElement('template');

        template.innerHTML = String(html || '');
        sanitizeEditableNode(template.content, !!allowBlocks);

        return template.innerHTML;
    }

    function getMediaTextSanitizeConfig() {
        var config = getInlineSanitizeConfig();

        config.p = true;
        config.ul = {};
        config.ol = {};
        config.li = true;
        config.blockquote = true;

        return config;
    }

    function getInlineSanitizeConfig() {
        return {
            a: {
                href: true,
                title: true,
                target: true,
                rel: true
            },
            b: {},
            strong: {},
            i: {},
            em: {},
            u: {},
            s: {
                class: true
            },
            strike: {
                class: true
            },
            del: {
                class: true
            },
            code: {
                class: true
            },
            mark: {},
            font: {
                color: true
            },
            sub: {},
            sup: {},
            br: true,
            span: function (element) {
                var className = String(element && element.getAttribute ? element.getAttribute('class') || '' : '');
                var style = String(element && element.getAttribute ? element.getAttribute('style') || '' : '');

                if (/\btg-spoiler\b/.test(className)) {
                    return { class: true };
                }

                return sanitizeEditableColorStyle(style) !== '' ? { style: true } : {};
            }
        };
    }

    function isImageUrlCandidate(value) {
        var url = String(value || '').trim();

        if (url === '' || /\s/.test(url) || sanitizeEditableUrl(url) === '') {
            return false;
        }

        return /^(?:https?:\/\/|\/|\.\/|\.\.\/)/i.test(url)
            && (/\.(?:jpe?g|png|gif|webp|bmp|ico)(?:[?#].*)?$/i.test(url) || /^\/media-file\?/i.test(url));
    }

    function validateEditorImageFile(file) {
        var maxSize = 25 * 1024 * 1024;
        var mimeType = file ? String(file.type || '') : '';
        var fileName = file ? String(file.name || '') : '';
        var hasAllowedMime = /^image\/(?:jpeg|jpg|png|gif|webp|bmp|x-icon|vnd\.microsoft\.icon)$/i.test(mimeType);
        var hasAllowedExtension = /\.(?:jpe?g|png|gif|webp|bmp|ico)$/i.test(fileName);

        if (!file) {
            return 'Keine Datei ausgewählt.';
        }

        if (!hasAllowedMime && !hasAllowedExtension) {
            return 'Nur Bilddateien sind erlaubt.';
        }

        if (Number(file.size || 0) > maxSize) {
            return 'Bild ist zu groß. Maximal erlaubt sind 25 MB.';
        }

        return '';
    }

    function dataImageUrlToFile(value, fallbackName) {
        var dataUrl = String(value || '').trim();
        var match = dataUrl.match(/^data:(image\/(?:jpeg|jpg|png|gif|webp|bmp|x-icon|vnd\.microsoft\.icon));base64,([a-z0-9+/=\s]+)$/i);
        var mimeType;
        var extension;
        var binary;
        var bytes;
        var index;

        if (!match || typeof window.atob !== 'function' || typeof window.File !== 'function') {
            return null;
        }

        mimeType = match[1].toLowerCase() === 'image/jpg' ? 'image/jpeg' : match[1].toLowerCase();
        extension = {
            'image/jpeg': 'jpg',
            'image/png': 'png',
            'image/gif': 'gif',
            'image/webp': 'webp',
            'image/bmp': 'bmp',
            'image/x-icon': 'ico',
            'image/vnd.microsoft.icon': 'ico'
        }[mimeType] || 'png';

        try {
            binary = window.atob(match[2].replace(/\s+/g, ''));
        } catch (_error) {
            return null;
        }

        if (binary.length > 25 * 1024 * 1024) {
            return null;
        }

        bytes = new Uint8Array(binary.length);
        for (index = 0; index < binary.length; index++) {
            bytes[index] = binary.charCodeAt(index);
        }

        return new File([bytes], (fallbackName || 'clipboard-image') + '.' + extension, { type: mimeType });
    }

    function sanitizePreviewFontStack(value) {
        var stack = String(value || '').trim();

        return /^[a-z0-9\s'",.\-]+$/i.test(stack) ? stack : '';
    }

    function sanitizePreviewFontSize(value, fallback) {
        var size = parseFloat(String(value || '').replace('px', ''));

        if (!isFinite(size)) {
            return fallback;
        }

        return Math.max(12, Math.min(24, size)) + 'px';
    }

    function sanitizePreviewLineHeight(value, fallback) {
        var lineHeight = parseFloat(String(value || ''));

        if (!isFinite(lineHeight)) {
            return fallback;
        }

        return String(Math.max(1.1, Math.min(2.2, lineHeight)));
    }

    function sanitizePreviewCssValue(value, fallback) {
        var cssValue = String(value || '').trim();

        if (cssValue === '' || cssValue.length > 240 || /[;{}<>]/.test(cssValue) || /(?:expression|javascript:|url\s*\()/i.test(cssValue)) {
            return fallback || '';
        }

        return cssValue;
    }

    function parseCssDeclarations(block) {
        var declarations = {};

        String(block || '').split(';').forEach(function (part) {
            var separatorIndex = part.indexOf(':');
            var property;
            var value;

            if (separatorIndex <= 0) {
                return;
            }

            property = part.slice(0, separatorIndex).trim().toLowerCase();
            value = sanitizePreviewCssValue(part.slice(separatorIndex + 1).trim(), '');
            if (property !== '' && value !== '') {
                declarations[property] = value;
            }
        });

        return declarations;
    }

    function selectorMatchesAny(selectorText, candidates) {
        var selectors = String(selectorText || '').split(',').map(function (selector) {
            return selector.trim().toLowerCase();
        }).filter(Boolean);

        return selectors.some(function (selector) {
            return candidates.some(function (candidate) {
                var normalized = String(candidate || '').toLowerCase();

                return selector === normalized || selector.endsWith(' ' + normalized) || selector.indexOf(normalized + '.') !== -1;
            });
        });
    }

    function collectCssDeclarations(css, candidates) {
        var cleanedCss = String(css || '').replace(/\/\*[\s\S]*?\*\//g, '');
        var declarations = {};
        var rulePattern = /([^{}]+)\{([^{}]*)\}/g;
        var match;

        while ((match = rulePattern.exec(cleanedCss)) !== null) {
            if (selectorMatchesAny(match[1], candidates)) {
                declarations = Object.assign(declarations, parseCssDeclarations(match[2]));
            }
        }

        return declarations;
    }

    function applyDeclarationValue(holder, declarations, propertyName, variableName) {
        var value = declarations[propertyName];

        if (holder && value) {
            holder.style.setProperty(variableName, value);
        }
    }

    function applyThemePreviewCss(holder, css) {
        var rootDeclarations = collectCssDeclarations(css, [':root']);
        var contentDeclarations = collectCssDeclarations(css, ['body', '.entry-content', '.post-content', '.page-content', '.cms-content', 'article']);
        var headingDeclarations = collectCssDeclarations(css, ['h1', 'h2', 'h3', 'h4', 'h5', 'h6']);
        var h2Declarations = collectCssDeclarations(css, ['h2']);
        var h3Declarations = collectCssDeclarations(css, ['h3']);
        var h4Declarations = collectCssDeclarations(css, ['h4']);

        if (!holder || !holder.style) {
            return;
        }

        Object.keys(rootDeclarations).forEach(function (propertyName) {
            if (propertyName.indexOf('--') === 0) {
                holder.style.setProperty(propertyName, rootDeclarations[propertyName]);
            }
        });

        applyDeclarationValue(holder, contentDeclarations, 'font-family', '--cms-editor-preview-body-font');
        applyDeclarationValue(holder, contentDeclarations, 'font-size', '--cms-editor-preview-font-size');
        applyDeclarationValue(holder, contentDeclarations, 'line-height', '--cms-editor-preview-line-height');
        applyDeclarationValue(holder, headingDeclarations, 'font-family', '--cms-editor-preview-heading-font');
        applyDeclarationValue(holder, headingDeclarations, 'font-weight', '--cms-editor-preview-heading-weight');
        applyDeclarationValue(holder, h2Declarations, 'font-size', '--cms-editor-preview-h2-size');
        applyDeclarationValue(holder, h3Declarations, 'font-size', '--cms-editor-preview-h3-size');
        applyDeclarationValue(holder, h4Declarations, 'font-size', '--cms-editor-preview-h4-size');
    }

    function loadEditorThemePreviewStyles(holder, typography) {
        var settings = typography && typeof typography === 'object' ? typography : {};
        var stylesheetUrl = sanitizePreviewCssValue(settings.themeStylesheetUrl || settings.stylesheetUrl || '', '');

        if (!holder || stylesheetUrl === '' || typeof fetch !== 'function') {
            return;
        }

        if (THEME_PREVIEW_STYLE_CACHE[stylesheetUrl]) {
            THEME_PREVIEW_STYLE_CACHE[stylesheetUrl].then(function (css) {
                applyThemePreviewCss(holder, css);
            }).catch(function (error) {
                logWarn('Theme preview stylesheet could not be applied.', error);
            });
            return;
        }

        THEME_PREVIEW_STYLE_CACHE[stylesheetUrl] = fetch(stylesheetUrl, { credentials: 'same-origin' }).then(function (response) {
            if (!response.ok) {
                throw new Error('Theme stylesheet HTTP ' + response.status);
            }

            return response.text();
        });

        THEME_PREVIEW_STYLE_CACHE[stylesheetUrl].then(function (css) {
            applyThemePreviewCss(holder, css);
        }).catch(function (error) {
            logWarn('Theme preview stylesheet could not be loaded.', error);
        });
    }

    function applyEditorPreviewTypography(holder, typography) {
        var settings = typography && typeof typography === 'object' ? typography : {};
        var bodyFont = sanitizePreviewFontStack(settings.bodyFontStack || settings.bodyFont || '');
        var headingFont = sanitizePreviewFontStack(settings.headingFontStack || settings.headingFont || bodyFont);
        var fontSize = sanitizePreviewFontSize(settings.fontSize || settings.fontSizeBase, '16px');
        var lineHeight = sanitizePreviewLineHeight(settings.lineHeight, '1.65');

        if (!holder || !holder.style) {
            return;
        }

        holder.classList.add('cms-editor-theme-preview');
        if (bodyFont !== '') {
            holder.style.setProperty('--cms-editor-preview-body-font', bodyFont);
        }
        if (headingFont !== '') {
            holder.style.setProperty('--cms-editor-preview-heading-font', headingFont);
        }
        holder.style.setProperty('--cms-editor-preview-font-size', fontSize);
        holder.style.setProperty('--cms-editor-preview-line-height', lineHeight);
        if (settings.activeThemeSlug) {
            holder.dataset.cmsActiveTheme = String(settings.activeThemeSlug).replace(/[^a-z0-9_-]/gi, '').slice(0, 80);
        }
        loadEditorThemePreviewStyles(holder, settings);
    }

    function autoresizeTextarea(textarea, minRows) {
        var lineHeight;

        if (!textarea) {
            return;
        }

        textarea.rows = Math.max(1, minRows || 1);

        function resize() {
            textarea.style.height = 'auto';
            lineHeight = parseFloat(window.getComputedStyle(textarea).lineHeight || '20') || 20;
            textarea.style.height = Math.max(textarea.scrollHeight, lineHeight * Math.max(1, minRows || 1) + 18) + 'px';
        }

        textarea.addEventListener('input', resize);
        window.setTimeout(resize, 0);
    }

    function normalizeBlock(block) {
        var type = block && typeof block.type === 'string' ? block.type : 'paragraph';
        var data = block && block.data && typeof block.data === 'object' ? block.data : {};
        var normalizedBlock;

        type = TOOL_ALIASES[type] || type;

        if (block && block.type === 'checklist') {
            normalizedBlock = {
                type: 'list',
                data: normalizeListData(Object.assign({}, data, { style: 'checklist' }))
            };
            return appendNormalizedTunes(block, normalizedBlock);
        }

        if (BLOCK_TOOL_NAMES.indexOf(type) === -1) {
            normalizedBlock = {
                type: 'paragraph',
                data: { text: stripTags(data.text || data.content || data.html || data.caption || '') }
            };
            return appendNormalizedTunes(block, normalizedBlock);
        }

        if (type === 'list') {
            normalizedBlock = { type: type, data: normalizeListData(data) };
            return appendNormalizedTunes(block, normalizedBlock);
        }

        if (type === 'paragraph' || type === 'header') {
            normalizedBlock = { type: type, data: normalizeTextBlockData(data, type) };
            return appendNormalizedTunes(block, normalizedBlock);
        }

        if (type === 'image') {
            normalizedBlock = { type: type, data: normalizeImageData(data) };
            return appendNormalizedTunes(block, normalizedBlock);
        }

        if (type === 'mediaText') {
            normalizedBlock = { type: type, data: normalizeMediaTextData(data) };
            return appendNormalizedTunes(block, normalizedBlock);
        }

        if (type === 'warning') {
            normalizedBlock = { type: type, data: normalizeWarningData(data) };
            return appendNormalizedTunes(block, normalizedBlock);
        }

        if (type === 'alert') {
            normalizedBlock = { type: type, data: normalizeAlertData(data) };
            return appendNormalizedTunes(block, normalizedBlock);
        }

        if (type === 'button') {
            normalizedBlock = { type: type, data: normalizeButtonData(data) };
            return appendNormalizedTunes(block, normalizedBlock);
        }

        if (type === 'raw') {
            normalizedBlock = { type: type, data: { html: String(data.html || data.text || data.content || '') } };
            return appendNormalizedTunes(block, normalizedBlock);
        }

        if (type === 'imageGallery') {
            normalizedBlock = {
                type: type,
                data: normalizeGalleryData(data)
            };
            return appendNormalizedTunes(block, normalizedBlock);
        }

        if (type === 'spacer') {
            normalizedBlock = {
                type: type,
                data: normalizeSpacerData(data)
            };
            return appendNormalizedTunes(block, normalizedBlock);
        }

        if (type === 'delimiter') {
            normalizedBlock = {
                type: type,
                data: normalizeDelimiterData(data)
            };
            return appendNormalizedTunes(block, normalizedBlock);
        }

        if (type === 'quote') {
            normalizedBlock = {
                type: type,
                data: normalizeQuoteData(data)
            };
            return appendNormalizedTunes(block, normalizedBlock);
        }

        return appendNormalizedTunes(block, { type: type, data: data });
    }

    function appendNormalizedTunes(sourceBlock, normalizedBlock) {
        var tunes = normalizeBlockTunes(sourceBlock && sourceBlock.tunes);

        if (Object.keys(tunes).length > 0) {
            normalizedBlock.tunes = tunes;
        }

        return normalizedBlock;
    }

    function normalizeBlockTunes(tunes) {
        var source = tunes && typeof tunes === 'object' ? tunes : {};
        var normalized = {};
        var anchor = sanitizeAnchorValue(source.anchor);
        var alignmentTune = source.alignmentTune && typeof source.alignmentTune === 'object' ? source.alignmentTune : {};
        var indentTune = source.indentTune && typeof source.indentTune === 'object' ? source.indentTune : {};
        var textVariant = sanitizeTextVariantValue(source.textVariant);
        var alignment = String(alignmentTune.alignment || alignmentTune.align || '');
        var indentLevel = parseInt(indentTune.indentLevel || 0, 10) || 0;

        if (anchor !== '') {
            normalized.anchor = anchor;
        }
        if (['left', 'center', 'right', 'justify'].indexOf(alignment) !== -1) {
            normalized.alignmentTune = { alignment: alignment };
        }
        if (indentLevel > 0) {
            normalized.indentTune = { indentLevel: Math.max(0, Math.min(8, indentLevel)) };
        }
        if (textVariant !== '') {
            normalized.textVariant = textVariant;
        }

        ['Cropper', 'CropperTune'].forEach(function (key) {
            var tune = source[key] && typeof source[key] === 'object' ? source[key] : {};
            var croppedImage = sanitizeEditableUrl(tune.croppedImage || '');

            if (croppedImage !== '' && isImageUrlCandidate(croppedImage)) {
                normalized[key] = { croppedImage: croppedImage };
            }
        });

        return normalized;
    }

    function sanitizeAnchorValue(value) {
        return String(value || '').trim().toLowerCase().replace(/\s+/g, '-').replace(/[^a-z0-9_-]/g, '').replace(/^-+|-+$/g, '').slice(0, 80);
    }

    function sanitizeTextVariantValue(value) {
        var variant = String(value || '').trim();

        return ['call-out', 'citation', 'details'].indexOf(variant) !== -1 ? variant : '';
    }

    function normalizeTextBlockData(data, type) {
        var value = data && typeof data === 'object' ? data : {};
        var alignment = String(value.alignment || 'left');
        var spacing = String(value.spacing || 'normal');
        var text = String(value.text || value.content || value.html || '');

        if (['left', 'center', 'right', 'justify'].indexOf(alignment) === -1) {
            alignment = 'left';
        }
        if (['compact', 'normal', 'relaxed', 'loose'].indexOf(spacing) === -1) {
            spacing = 'normal';
        }

        return Object.assign({}, value, {
            text: sanitizeEditableHtml(text),
            alignment: alignment,
            spacing: spacing,
            level: type === 'header' ? Math.min(4, Math.max(2, parseInt(value.level || 2, 10) || 2)) : value.level
        });
    }

    function normalizeWarningData(data) {
        var source = data && typeof data === 'object' ? data : {};
        var variant = String(source.variant || source.type || source.tone || 'info').toLowerCase();

        if (['info', 'warning', 'success', 'danger'].indexOf(variant) === -1) {
            variant = 'info';
        }

        return {
            variant: variant,
            title: sanitizeEditableHtml(source.title || source.caption || 'Hinweis'),
            message: sanitizeEditableHtml(source.message || source.text || source.content || '')
        };
    }

    function normalizeAlertData(data) {
        var source = data && typeof data === 'object' ? data : {};
        var type = String(source.type || source.variant || 'info').toLowerCase();
        var align = String(source.align || source.alignment || 'left').toLowerCase();

        if (['primary', 'secondary', 'info', 'success', 'warning', 'danger', 'light', 'dark'].indexOf(type) === -1) {
            type = 'info';
        }
        if (['left', 'center', 'right'].indexOf(align) === -1) {
            align = 'left';
        }

        return {
            type: type,
            align: align,
            message: sanitizeEditableHtml(source.message || source.text || source.content || '')
        };
    }

    function normalizeButtonData(data) {
        var source = data && typeof data === 'object' ? data : {};
        var align = String(source.align || source.alignment || 'left').toLowerCase();
        var color = String(source.color || source.variant || 'primary').toLowerCase();
        var size = String(source.size || 'medium').toLowerCase();

        if (['left', 'center', 'right'].indexOf(align) === -1) {
            align = 'left';
        }
        if (['primary', 'secondary', 'info', 'success', 'warning', 'danger', 'light', 'dark'].indexOf(color) === -1) {
            color = 'primary';
        }
        if (['small', 'medium', 'large'].indexOf(size) === -1) {
            size = 'medium';
        }

        return {
            url: sanitizeEditableUrl(source.url || source.link || ''),
            text: stripTags(String(source.text || source.label || '')),
            align: align,
            color: color,
            size: size
        };
    }

    function normalizeImageData(data) {
        var imageData = data && typeof data === 'object' ? data : {};
        var file = imageData.file && typeof imageData.file === 'object' ? imageData.file : {};
        var alignment = String(imageData.alignment || imageData.align || 'center');
        var size = String(imageData.size || imageData.widthPreset || (imageData.stretched ? 'full' : 'normal'));
        var borderStyle = String(imageData.borderStyle || (imageData.withBorder ? 'thin' : 'none'));
        var imageFit = normalizeImageFitValue(imageData.imageFit || imageData.objectFit || imageData.fit || 'contain', 'contain');
        var maxHeight = normalizeImageMaxHeightValue(imageData.maxHeight || imageData.imageMaxHeight || imageData.max_height || '0');
        var sourceUrl = sanitizeImageSourceUrl(imageData.sourceUrl || imageData.source_url || imageData.imageSource || imageData.source || '');

        if (['left', 'center', 'right'].indexOf(alignment) === -1) {
            alignment = 'center';
        }
        if (['normal', 'wide', 'full'].indexOf(size) === -1) {
            size = 'normal';
        }
        if (['none', 'thin', 'medium', 'thick'].indexOf(borderStyle) === -1) {
            borderStyle = imageData.withBorder ? 'thin' : 'none';
        }

        return Object.assign({}, imageData, {
            file: Object.assign({}, file, { url: String(file.url || imageData.url || '') }),
            caption: String(imageData.caption || ''),
            sourceUrl: sourceUrl,
            alignment: alignment,
            size: size,
            widthPreset: size,
            borderStyle: borderStyle,
            imageFit: imageFit,
            objectFit: imageFit,
            maxHeight: maxHeight,
            withBorder: borderStyle !== 'none',
            withBackground: !!imageData.withBackground,
            stretched: size === 'full' || !!imageData.stretched,
            rounded: imageData.rounded !== false,
            shadow: !!imageData.shadow
        });
    }

    function normalizeImageFitValue(value, fallback) {
        var fit = String(value || fallback || 'contain');

        return ['contain', 'cover', 'fill', 'none', 'scale-down'].indexOf(fit) !== -1 ? fit : (fallback || 'contain');
    }

    function normalizeImageMaxHeightValue(value) {
        var height = parseInt(String(value || '0').replace(/[^0-9]/g, ''), 10) || 0;
        var allowed = [0, 200, 300, 400, 500, 600, 800, 1000];

        return String(snapNumberToPreset(height, allowed, 0));
    }

    function normalizeGalleryData(data) {
        var galleryData = data && typeof data === 'object' ? data : {};
        var columns = parseInt(galleryData.columns || galleryData.cols || galleryData.columnCount || 3, 10) || 3;
        var borderStyle = String(galleryData.borderStyle || (galleryData.withBorder ? 'thin' : 'none'));
        var images = [];
        var seenUrls = new Set();

        if ([2, 3, 4, 5, 6].indexOf(columns) === -1) {
            columns = 3;
        }
        if (['none', 'thin', 'medium', 'thick'].indexOf(borderStyle) === -1) {
            borderStyle = galleryData.withBorder ? 'thin' : 'none';
        }

        ['images', 'items', 'files', 'gallery', 'urls'].forEach(function (key) {
            if (Array.isArray(galleryData[key])) {
                images = images.concat(galleryData[key]);
            } else if (typeof galleryData[key] === 'string') {
                images = images.concat(galleryData[key].split(/[\n,]+/).map(function (url) {
                    return url.trim();
                }).filter(Boolean));
            }
        });

        images = images.map(function (item) {
            var source = item && typeof item === 'object' ? item : { url: String(item || '') };
            var file = source.file && typeof source.file === 'object' ? source.file : source;
            var url = sanitizeEditableUrl(file.url || source.url || source.src || source.source || '');

            if (url === '') {
                return null;
            }

            return {
                file: Object.assign({}, file, { url: url }),
                caption: String(source.caption || source.alt || source.title || file.caption || '')
            };
        }).filter(function (item) {
            var url = item && item.file ? String(item.file.url || '') : '';

            if (url === '' || seenUrls.has(url)) {
                return false;
            }

            seenUrls.add(url);
            return true;
        });

        return {
            columns: columns,
            borderStyle: borderStyle,
            withBorder: borderStyle !== 'none',
            images: images,
            urls: images.map(function (item) {
                return item.file.url;
            })
        };
    }

    function normalizeMediaTextData(data) {
        var source = data && typeof data === 'object' ? data : {};
        var file = source.file && typeof source.file === 'object' ? source.file : {};
        var position = String(source.imagePosition || source.position || source.mediaPosition || 'left');
        var width = normalizeMediaWidthValue(source.imageWidth || source.mediaWidth || '40');
        var showBorder = normalizeBooleanValue(resolveMediaTextBorderValue(source), false);
        var spacingTop = normalizeMediaTextSpacingValue(resolveMediaTextOptionValue(source, ['spacingTop', 'marginTop', 'blockSpacingTop'], '10'));
        var spacingBottom = normalizeMediaTextSpacingValue(resolveMediaTextOptionValue(source, ['spacingBottom', 'marginBottom', 'blockSpacingBottom'], '10'));
        var imageFit = normalizeImageFitValue(source.imageFit || source.objectFit || source.fit || 'cover', 'cover');
        var verticalAlignment = normalizeMediaTextVerticalAlignment(resolveMediaTextOptionValue(source, ['verticalAlignment', 'mediaVerticalAlignment', 'imageVerticalAlignment', 'verticalAlign', 'alignItems'], 'top'));

        if (['left', 'right'].indexOf(position) === -1) {
            position = 'left';
        }

        return {
            file: Object.assign({}, file, { url: String(file.url || source.url || '') }),
            alt: String(source.alt || source.caption || ''),
            heading: stripTags(source.heading || source.title || source.headline || '').slice(0, 180),
            text: sanitizeEditableHtml(source.text || source.content || '', true),
            imagePosition: position,
            imageWidth: width,
            imageFit: imageFit,
            objectFit: imageFit,
            verticalAlignment: verticalAlignment,
            showBorder: showBorder,
            spacingTop: spacingTop,
            spacingBottom: spacingBottom
        };
    }

    function resolveMediaTextBorderValue(source) {
        if (Object.prototype.hasOwnProperty.call(source, 'showBorder')) {
            return source.showBorder;
        }
        if (Object.prototype.hasOwnProperty.call(source, 'border')) {
            return source.border;
        }
        if (Object.prototype.hasOwnProperty.call(source, 'hasBorder')) {
            return source.hasBorder;
        }

        return false;
    }

    function resolveMediaTextOptionValue(source, keys, defaultValue) {
        var index;
        var key;

        for (index = 0; index < keys.length; index += 1) {
            key = keys[index];
            if (Object.prototype.hasOwnProperty.call(source, key)) {
                return source[key];
            }
        }

        return defaultValue;
    }

    function normalizeBooleanValue(value, defaultValue) {
        var normalized;

        if (value === true || value === 1) {
            return true;
        }
        if (value === false || value === 0) {
            return false;
        }

        normalized = String(value || '').trim().toLowerCase();
        if (['1', 'true', 'yes', 'on'].indexOf(normalized) !== -1) {
            return true;
        }
        if (['0', 'false', 'no', 'off', ''].indexOf(normalized) !== -1) {
            return false;
        }

        return !!defaultValue;
    }

    function normalizeMediaWidthValue(value) {
        var width = parseInt(String(value || '').replace(/[^0-9]/g, ''), 10) || 40;

        if (width <= 36) {
            return '33';
        }
        if (width >= 46) {
            return '50';
        }

        return '40';
    }

    function normalizeMediaTextSpacingValue(value) {
        var spacing = parseInt(String(value || '').replace(/[^0-9]/g, ''), 10);
        var allowed = [0, 5, 10, 15, 20, 30, 40, 60, 80, 100];

        if (allowed.indexOf(spacing) !== -1) {
            return String(spacing);
        }

        return '10';
    }

    function normalizeMediaTextVerticalAlignment(value) {
        var alignment = String(value || '').trim().toLowerCase();

        if (['middle', 'center', 'centre'].indexOf(alignment) !== -1) {
            return 'center';
        }
        if (['bottom', 'end', 'flex-end'].indexOf(alignment) !== -1) {
            return 'bottom';
        }
        if (['top', 'start', 'flex-start'].indexOf(alignment) !== -1) {
            return 'top';
        }

        return 'top';
    }

    function getMediaTextAlignItemsValue(verticalAlignment) {
        if (verticalAlignment === 'center') {
            return 'center';
        }
        if (verticalAlignment === 'bottom') {
            return 'flex-end';
        }

        return 'flex-start';
    }

    function normalizeSpacerData(data) {
        var allowedHeights = [0, 8, 10, 15, 16, 24, 25, 32, 40, 48, 56, 60, 64, 72, 75, 80, 96, 100, 120, 140, 150, 160, 180, 200];
        var presetMap = {
            none: 0,
            xs: 8,
            small: 15,
            sm: 15,
            medium: 40,
            md: 40,
            normal: 40,
            large: 75,
            lg: 75,
            xl: 100,
            xlarge: 100,
            huge: 140,
            xxl: 160
        };
        var source = data && typeof data === 'object' ? data : {};
        var raw = source.height || source.size || source.value || source.spacer || source.space || source.preset || 40;
        var presetKey = String(raw || '').toLowerCase().replace(/\s+/g, '');
        var height = Object.prototype.hasOwnProperty.call(presetMap, presetKey) ? presetMap[presetKey] : parseInt(presetKey.replace('px', ''), 10) || 40;

        if (allowedHeights.indexOf(height) === -1) {
            height = Math.max(0, Math.min(200, height));
        }

        return {
            height: height,
            preset: height + 'px'
        };
    }

    function normalizeDelimiterData(data) {
        var source = data && typeof data === 'object' ? data : {};
        var style = String(source.style || source.type || 'line').toLowerCase();
        var lineWidth = parseInt(source.lineWidth || source.width || 35, 10) || 35;
        var lineThickness = parseInt(source.lineThickness || source.thickness || 2, 10) || 2;

        if (['star', 'dash', 'line'].indexOf(style) === -1) {
            style = 'line';
        }
        lineWidth = snapNumberToPreset(lineWidth, [8, 15, 25, 35, 50, 60, 100], 35);
        lineThickness = snapNumberToPreset(lineThickness, [1, 2, 3, 4, 5, 6], 2);

        return style === 'line'
            ? { style: style, lineWidth: lineWidth, lineThickness: lineThickness }
            : { style: style };
    }

    function normalizeQuoteData(data) {
        var source = data && typeof data === 'object' ? data : {};
        var alignment = String(source.alignment || 'left');
        var design = String(source.design || 'bar');

        if (['left', 'center'].indexOf(alignment) === -1) {
            alignment = 'left';
        }
        if (['bar', 'card', 'minimal', 'mark'].indexOf(design) === -1) {
            design = 'bar';
        }

        return {
            text: sanitizeEditableHtml(String(source.text || source.content || '')),
            caption: sanitizeEditableHtml(String(source.caption || source.cite || '')),
            alignment: alignment,
            design: design
        };
    }

    function snapNumberToPreset(value, presets, fallback) {
        var number = parseInt(value, 10);
        var values = Array.isArray(presets) ? presets : [];

        if (!isFinite(number) || values.length === 0) {
            return fallback;
        }

        return values.reduce(function (closest, preset) {
            return Math.abs(preset - number) < Math.abs(closest - number) ? preset : closest;
        }, values.indexOf(fallback) !== -1 ? fallback : values[0]);
    }

    function normalizeListData(data) {
        var style = data && typeof data.style === 'string' ? data.style : 'unordered';
        var meta = data && data.meta && typeof data.meta === 'object' ? data.meta : {};
        var items = Array.isArray(data && data.items) ? data.items : [];

        if (['ordered', 'unordered', 'checklist'].indexOf(style) === -1) {
            style = 'unordered';
        }

        if (items.length === 0 && Array.isArray(data && data.content)) {
            items = data.content;
        }

        return {
            style: style,
            meta: meta,
            items: normalizeListItems(items, style)
        };
    }

    function normalizeListItems(items, style) {
        return items.map(function (item) {
            var content;
            var meta;
            var children;

            if (typeof item === 'string') {
                return {
                    content: sanitizeEditableHtml(item),
                    meta: style === 'checklist' ? { checked: false } : {},
                    items: []
                };
            }

            if (!item || typeof item !== 'object') {
                return null;
            }

            content = typeof item.content === 'string'
                ? item.content
                : (typeof item.text === 'string' ? item.text : '');
            meta = item.meta && typeof item.meta === 'object' ? item.meta : {};
            if (style === 'checklist' && typeof item.checked === 'boolean' && typeof meta.checked !== 'boolean') {
                meta = Object.assign({}, meta, { checked: item.checked });
            }
            children = Array.isArray(item.items) ? item.items : [];

            return {
                content: sanitizeEditableHtml(content),
                meta: meta,
                items: normalizeListItems(children, style)
            };
        }).filter(Boolean);
    }

    function textToBlocks(value) {
        return String(value || '')
            .replace(/\r\n/g, '\n')
            .split(/\n{2,}/)
            .map(function (part) {
                return part.trim();
            })
            .filter(Boolean)
            .map(function (part) {
                return {
                    type: 'paragraph',
                    data: { text: escapeHtml(part).replace(/\n/g, '<br>') }
                };
            });
    }

    function htmlToBlocks(value) {
        var root = document.createElement('div');
        var source;
        var blocks = [];

        root.innerHTML = String(value || '');
        source = root.querySelector('body') || root;

        function hasConvertibleBlockChildren(element) {
            return Array.prototype.slice.call(element.children || []).some(function (child) {
                return /^(?:h[1-6]|p|div|section|article|main|ul|ol|blockquote|pre|table|img|figure|hr)$/i.test(String(child.tagName || ''));
            });
        }

        function appendElementBlock(child) {
            var tag = String(child.tagName || '').toLowerCase();
            var text = child.innerHTML || child.textContent || '';

            if (child.classList && child.classList.contains('wp-block-media-text')) {
                blocks.push({ type: 'mediaText', data: mediaTextElementToData(child) });
                return;
            }

            if (['div', 'section', 'article', 'main'].indexOf(tag) !== -1 && hasConvertibleBlockChildren(child)) {
                Array.prototype.slice.call(child.children || []).forEach(appendElementBlock);
                return;
            }

            if (tag === 'figure' && hasConvertibleBlockChildren(child)) {
                Array.prototype.slice.call(child.children || []).forEach(appendElementBlock);
                return;
            }

            if (/^h[1-6]$/.test(tag)) {
                blocks.push({
                    type: 'header',
                    data: { text: stripTags(text), level: Math.min(4, Math.max(2, parseInt(tag.substring(1), 10) || 2)) }
                });
                return;
            }

            if (tag === 'blockquote') {
                var quoteClone = child.cloneNode(true);
                var citation = quoteClone.querySelector('cite, footer');
                var caption = citation ? sanitizeEditableHtml(citation.innerHTML || citation.textContent || '') : '';

                if (citation && citation.parentNode) {
                    citation.parentNode.removeChild(citation);
                }
                blocks.push({
                    type: 'quote',
                    data: normalizeQuoteData({
                        text: quoteClone.innerHTML || quoteClone.textContent || '',
                        caption: caption,
                        alignment: 'left',
                        design: 'bar'
                    })
                });
                return;
            }

            if (tag === 'pre') {
                blocks.push({ type: 'code', data: { code: child.textContent || '' } });
                return;
            }

            if (tag === 'hr') {
                blocks.push({ type: 'delimiter', data: {} });
                return;
            }

            if (tag === 'ul' || tag === 'ol') {
                blocks.push({
                    type: 'list',
                    data: {
                        style: tag === 'ol' ? 'ordered' : 'unordered',
                        items: listElementToItems(child, tag === 'ol' ? 'ordered' : 'unordered')
                    }
                });
                return;
            }

            if (tag === 'table') {
                blocks.push({ type: 'table', data: tableElementToData(child) });
                return;
            }

            if (tag === 'img') {
                blocks.push({ type: 'image', data: { file: { url: child.getAttribute('src') || '' }, caption: child.getAttribute('alt') || '' } });
                return;
            }

            if (stripTags(text) !== '') {
                blocks.push({ type: 'paragraph', data: { text: text } });
            }
        }

        Array.prototype.slice.call(source.children).forEach(appendElementBlock);

        if (blocks.length === 0 && stripTags(value) !== '') {
            return textToBlocks(stripTags(value));
        }

        return blocks;
    }

    function mediaTextElementToData(element) {
        var image = element.querySelector('img');
        var content = element.querySelector('.wp-block-media-text__content');
        var position = element.classList && element.classList.contains('has-media-on-the-right') ? 'right' : 'left';
        var style = element.getAttribute('style') || '';
        var widthMatch = style.match(/(?:grid-template-columns|width)\s*:\s*([0-9]{1,3})%/i);
        var className = element.getAttribute('class') || '';
        var verticalAlignment = element.getAttribute('data-vertical-alignment') || element.getAttribute('data-vertical') || 'top';

        if (/\bis-vertically-aligned-center\b/i.test(className)) {
            verticalAlignment = 'center';
        } else if (/\bis-vertically-aligned-bottom\b/i.test(className)) {
            verticalAlignment = 'bottom';
        } else if (/\bis-vertically-aligned-top\b/i.test(className)) {
            verticalAlignment = 'top';
        }

        return normalizeMediaTextData({
            file: { url: image ? image.getAttribute('src') || '' : '' },
            alt: image ? image.getAttribute('alt') || '' : '',
            text: content ? content.innerHTML || content.textContent || '' : element.textContent || '',
            imagePosition: position,
            imageWidth: widthMatch ? widthMatch[1] : '40',
            verticalAlignment: verticalAlignment
        });
    }

    function listElementToItems(list, style) {
        return Array.prototype.slice.call(list.children || []).filter(function (child) {
            return String(child.tagName || '').toLowerCase() === 'li';
        }).map(function (item) {
            var clone = item.cloneNode(true);
            var nestedItems = [];

            Array.prototype.slice.call(clone.children || []).forEach(function (child) {
                var tagName = String(child.tagName || '').toLowerCase();
                if (tagName === 'ul' || tagName === 'ol') {
                    nestedItems = nestedItems.concat(listElementToItems(child, tagName === 'ol' ? 'ordered' : 'unordered'));
                    child.parentNode.removeChild(child);
                }
            });

            return {
                content: sanitizeEditableHtml(clone.innerHTML || clone.textContent || ''),
                meta: style === 'checklist' ? { checked: false } : {},
                items: nestedItems
            };
        }).filter(function (item) {
            return stripTags(item.content || '') !== '' || item.items.length > 0;
        });
    }

    function tableElementToData(table) {
        return {
            withHeadings: false,
            content: Array.prototype.slice.call(table.querySelectorAll('tr')).map(function (row) {
                return Array.prototype.slice.call(row.children).map(function (cell) {
                    return stripTags(cell.innerHTML || cell.textContent || '');
                });
            }).filter(function (row) {
                return row.length > 0;
            })
        };
    }

    function normalizeInitialData(raw) {
        var decoded;
        var value = typeof raw === 'string' ? raw.trim() : raw;

        if (!value) {
            return { time: Date.now(), version: VERSION, blocks: [] };
        }

        if (typeof value === 'object') {
            decoded = value;
        } else {
            try {
                decoded = JSON.parse(value);
            } catch (_error) {
                decoded = null;
            }
        }

        if (decoded && Array.isArray(decoded.blocks)) {
            return {
                time: decoded.time || Date.now(),
                version: decoded.version || VERSION,
                blocks: decoded.blocks.map(normalizeBlock)
            };
        }

        return {
            time: Date.now(),
            version: VERSION,
            blocks: String(value).indexOf('<') !== -1 ? htmlToBlocks(value) : textToBlocks(value)
        };
    }

    function createElement(tagName, className, text) {
        var element = document.createElement(tagName);
        if (className) {
            element.className = className;
        }
        if (typeof text === 'string') {
            element.textContent = text;
        }
        return element;
    }

    function clearElement(element) {
        if (!element) {
            return;
        }

        while (element.firstChild) {
            element.removeChild(element.firstChild);
        }
    }

    function preparePreviewImage(image, eager) {
        if (!image || image.nodeName !== 'IMG') {
            return image;
        }

        image.loading = eager ? 'eager' : 'lazy';
        image.decoding = 'async';
        image.draggable = false;
        if ('fetchPriority' in image) {
            image.fetchPriority = eager ? 'auto' : 'low';
        }

        return image;
    }

    function setPreviewImageSource(image, url, alt) {
        var nextUrl = String(url || '');

        if (!image || image.nodeName !== 'IMG') {
            return;
        }

        if (nextUrl !== '') {
            if (image.getAttribute('src') !== nextUrl) {
                image.src = nextUrl;
            }
            image.alt = String(alt || '');
            return;
        }

        image.removeAttribute('src');
        image.alt = '';
    }

    function createEditable(className, html, placeholder, api, readOnly, allowBlocks) {
        var element = createElement('div', className);
        element.contentEditable = readOnly ? 'false' : 'true';
        element.innerHTML = sanitizeEditableHtml(html || '', !!allowBlocks);
        if (placeholder) {
            element.dataset.placeholder = placeholder;
        }
        if (!readOnly) {
            bindEditablePasteBehavior(element, api || null);
        }
        return element;
    }

    function notifyToolChanged(element) {
        if (!element || typeof element.dispatchEvent !== 'function') {
            return;
        }

        element.dispatchEvent(new Event('input', { bubbles: true }));
        element.dispatchEvent(new Event('change', { bubbles: true }));
        if (typeof window.CustomEvent === 'function') {
            element.dispatchEvent(new CustomEvent('cms-editorjs-tool-change', { bubbles: true }));
        }
    }

    function clipboardHasImageFile(clipboardData) {
        if (!clipboardData) {
            return false;
        }

        return Array.prototype.slice.call(clipboardData.items || []).some(function (item) {
            return item && String(item.kind || '') === 'file' && /^image\//i.test(String(item.type || ''));
        });
    }

    function getFirstClipboardImageFile(clipboardData) {
        var items = clipboardData ? Array.prototype.slice.call(clipboardData.items || []) : [];
        var files = clipboardData ? Array.prototype.slice.call(clipboardData.files || []) : [];
        var imageFile = null;

        items.some(function (item) {
            var file;

            if (!item || String(item.kind || '') !== 'file' || !/^image\//i.test(String(item.type || '')) || typeof item.getAsFile !== 'function') {
                return false;
            }

            file = item.getAsFile();
            if (file) {
                imageFile = file;
                return true;
            }

            return false;
        });

        if (imageFile) {
            return imageFile;
        }

        return files.find(function (file) {
            return file && /^image\//i.test(String(file.type || ''));
        }) || null;
    }

    function insertHtmlAtSelection(editable, html) {
        var selection = window.getSelection ? window.getSelection() : null;
        var range;
        var fragment;
        var lastNode;
        var wrapper;

        if (!selection || selection.rangeCount === 0 || !editable.contains(selection.anchorNode)) {
            editable.focus();
            selection = window.getSelection ? window.getSelection() : null;
        }

        if (!selection || selection.rangeCount === 0 || !editable.contains(selection.anchorNode)) {
            editable.insertAdjacentHTML('beforeend', html);
            return;
        }

        range = selection.getRangeAt(0);
        range.deleteContents();
        wrapper = document.createElement('div');
        wrapper.innerHTML = html;
        fragment = document.createDocumentFragment();

        while (wrapper.firstChild) {
            lastNode = fragment.appendChild(wrapper.firstChild);
        }

        range.insertNode(fragment);
        if (lastNode) {
            range.setStartAfter(lastNode);
            range.collapse(true);
            selection.removeAllRanges();
            selection.addRange(range);
        }
    }

    function htmlContainsBlockFormatting(html) {
        return /<(?:h[1-6]|p|div|section|article|ul|ol|li|blockquote|pre|table|tr|img|figure|hr)\b/i.test(String(html || ''));
    }

    function getStructuredPasteBlocks(html) {
        var blocks;

        if (!html || !htmlContainsBlockFormatting(html)) {
            return [];
        }

        blocks = htmlToBlocks(html).map(normalizeBlock).filter(function (block) {
            return block && typeof block.type === 'string';
        });

        if (blocks.length === 0) {
            return [];
        }

        if (blocks.length > 1 || blocks[0].type !== 'paragraph') {
            return blocks;
        }

        return /<(?:h[1-6]|ul|ol|blockquote|pre|table|tr|img|figure|hr)\b/i.test(String(html))
            ? blocks
            : [];
    }

    function getBlockIndexFromElement(element) {
        var blockElement = element && element.closest ? element.closest('.ce-block') : null;
        var editorRoot = blockElement && blockElement.closest ? blockElement.closest('.codex-editor') : null;
        var redactor = editorRoot && editorRoot.querySelector ? editorRoot.querySelector('.codex-editor__redactor') : null;
        var blockScope = redactor || editorRoot || (blockElement ? blockElement.parentElement : null);
        var blocks;

        if (!blockElement || !blockScope || !blockScope.querySelectorAll) {
            return -1;
        }

        blocks = Array.prototype.slice.call(blockScope.querySelectorAll('.ce-block'));
        return blocks.indexOf(blockElement);
    }

    function insertEditorBlocksFromPaste(api, editable, blocks, replaceEmptyCurrent) {
        var blocksApi = api && api.blocks ? api.blocks : null;
        var domIndex = getBlockIndexFromElement(editable);
        var currentIndex = domIndex;
        var shouldReplaceCurrent = replaceEmptyCurrent !== false
            && stripTags(editable ? editable.innerHTML || '' : '').trim() === '';
        var insertIndex;
        var insertedCount = 0;
        var indexedInsertedCount = 0;

        if (!blocksApi || typeof blocksApi.insert !== 'function' || !Array.isArray(blocks) || blocks.length === 0) {
            return 0;
        }

        if (currentIndex < 0 && typeof blocksApi.getCurrentBlockIndex === 'function') {
            try {
                currentIndex = blocksApi.getCurrentBlockIndex();
            } catch (_error) {
                currentIndex = -1;
            }
        }

        insertIndex = currentIndex >= 0 ? currentIndex + (shouldReplaceCurrent ? 0 : 1) : undefined;

        blocks.forEach(function (block) {
            var insertedBlock;

            try {
            insertedBlock = blocksApi.insert(block.type, block.data || {}, undefined, typeof insertIndex === 'number' ? insertIndex + indexedInsertedCount : undefined, true);
                if (insertedBlock) {
                    insertedCount += 1;
                    if (typeof insertIndex === 'number') {
                        indexedInsertedCount += 1;
                    }
                }
                if (
                    insertedBlock
                    && insertedBlock.id
                    && block.tunes
                    && Object.keys(block.tunes).length > 0
                    && typeof blocksApi.update === 'function'
                ) {
                    Promise.resolve(blocksApi.update(insertedBlock.id, block.data || {}, block.tunes)).catch(function (tuneError) {
                        logWarn('Copied block tunes could not be restored.', tuneError);
                    });
                }
            } catch (error) {
                logWarn('Indexed paste block insert failed.', error);

                try {
                    insertedBlock = blocksApi.insert(block.type, block.data || {});
                    if (insertedBlock) {
                        insertedCount += 1;
                    }
                    if (
                        insertedBlock
                        && insertedBlock.id
                        && block.tunes
                        && Object.keys(block.tunes).length > 0
                        && typeof blocksApi.update === 'function'
                    ) {
                        Promise.resolve(blocksApi.update(insertedBlock.id, block.data || {}, block.tunes)).catch(function (tuneError) {
                            logWarn('Copied block tunes could not be restored.', tuneError);
                        });
                    }
                } catch (fallbackError) {
                    logWarn('Paste block insert failed.', fallbackError);
                }
            }
        });

        if (shouldReplaceCurrent && currentIndex >= 0 && insertedCount > 0 && typeof blocksApi.delete === 'function') {
            try {
                blocksApi.delete(currentIndex + indexedInsertedCount);
            } catch (deleteError) {
                logWarn('Original empty block could not be removed after paste.', deleteError);
            }
        }

        if (insertedCount > 0 && api && api.caret && typeof api.caret.setToBlock === 'function') {
            window.setTimeout(function () {
                try {
                    api.caret.setToBlock(typeof insertIndex === 'number' ? insertIndex + insertedCount - 1 : currentIndex + insertedCount, 'end');
                } catch (_error) {
                    // Caret placement is a UX enhancement; inserted blocks already exist.
                }
            }, 0);
        }

        return insertedCount;
    }

    function bindEditablePasteBehavior(editable, api) {
        if (!editable || editable.dataset.cmsPasteBound === '1') {
            return;
        }

        editable.dataset.cmsPasteBound = '1';
        editable.addEventListener('paste', function (event) {
            var clipboardData = event.clipboardData || window.clipboardData || null;
            var html = clipboardData && typeof clipboardData.getData === 'function' ? clipboardData.getData('text/html') : '';
            var text = clipboardData && typeof clipboardData.getData === 'function' ? clipboardData.getData('text/plain') : '';
            var structuredBlocks;
            var insertHtml;

            if (clipboardData && typeof editable.cmsCustomPasteHandler === 'function') {
                try {
                    if (editable.cmsCustomPasteHandler(event, { clipboardData: clipboardData, html: html, text: text }) === true || event.defaultPrevented) {
                        return;
                    }
                } catch (error) {
                    logWarn('Custom editable paste handler failed.', error);
                }
            }

            if (!clipboardData || clipboardHasImageFile(clipboardData) || (html === '' && text === '')) {
                return;
            }

            var clipboardBlocks = parseCmsBlockClipboardText(text);
            if (clipboardBlocks.length > 0 && insertEditorBlocksFromPaste(api, editable, clipboardBlocks, false)) {
                event.preventDefault();
                event.stopPropagation();
                return;
            }

            structuredBlocks = getStructuredPasteBlocks(html);
            if (structuredBlocks.length > 0 && insertEditorBlocksFromPaste(api, editable, structuredBlocks)) {
                event.preventDefault();
                event.stopPropagation();
                return;
            }

            if (html === '' && isImageUrlCandidate(text) && insertEditorBlocksFromPaste(api, editable, [{
                type: 'image',
                data: { file: { url: text.trim() }, caption: '' }
            }])) {
                event.preventDefault();
                event.stopPropagation();
                return;
            }

            insertHtml = html !== ''
                ? sanitizeEditableHtml(html)
                : escapeHtml(text).replace(/\r\n|\r|\n/g, '<br>');

            if (insertHtml === '') {
                return;
            }

            event.preventDefault();
            event.stopPropagation();
            insertHtmlAtSelection(editable, insertHtml);
            editable.dispatchEvent(new Event('input', { bubbles: true }));
        }, true);
    }

    function getSelectedCmsEditorBlockIndices(holder) {
        if (!holder || typeof holder.querySelectorAll !== 'function') {
            return [];
        }

        return Array.prototype.slice.call(holder.querySelectorAll('.ce-block--selected')).map(function (element) {
            return getBlockIndexFromElement(element);
        }).filter(function (index, position, indices) {
            return index >= 0 && indices.indexOf(index) === position;
        }).sort(function (left, right) {
            return left - right;
        });
    }

    function copySelectedCmsEditorBlocks(editor, holder) {
        var indices = getSelectedCmsEditorBlockIndices(holder);
        var blocksApi = editor && editor.blocks ? editor.blocks : null;

        if (!blocksApi || typeof blocksApi.getBlockByIndex !== 'function' || indices.length === 0) {
            return Promise.resolve(false);
        }

        return Promise.all(indices.map(function (index) {
            var block = blocksApi.getBlockByIndex(index);

            if (!block || typeof block.save !== 'function') {
                return null;
            }

            return Promise.resolve(block.save()).then(function (saved) {
                saved = saved && typeof saved === 'object' ? saved : {};
                saved.type = saved.type || saved.tool || block.name || '';
                return saved;
            });
        })).then(function (blocks) {
            blocks = blocks.filter(Boolean);
            return blocks.length > 0 ? writeCmsBlocksToClipboard(blocks) : false;
        });
    }

    function bindCmsBlockClipboardBehavior(holder, editor) {
        var pasteHandler;
        var keydownHandler;

        if (!holder || !editor || holder.dataset.cmsBlockClipboardBound === '1') {
            return function () {};
        }

        pasteHandler = function (event) {
            var clipboardData = event.clipboardData || window.clipboardData || null;
            var text = clipboardData && typeof clipboardData.getData === 'function'
                ? clipboardData.getData('text/plain')
                : '';
            var blocks = parseCmsBlockClipboardText(text);
            var insertedCount;

            if (blocks.length === 0) {
                return;
            }

            insertedCount = insertEditorBlocksFromPaste(editor, event.target || holder, blocks, false);
            if (insertedCount === 0) {
                showCmsEditorNotice(editor, 'Die kopierten Blöcke konnten in diesem Editor nicht eingefügt werden.', 'error');
                return;
            }

            event.preventDefault();
            event.stopImmediatePropagation();
            showCmsEditorNotice(
                editor,
                insertedCount === blocks.length
                    ? (insertedCount === 1 ? 'Block eingefügt.' : insertedCount + ' Blöcke eingefügt.')
                    : insertedCount + ' von ' + blocks.length + ' Blöcken eingefügt.',
                insertedCount === blocks.length ? 'success' : 'error'
            );
        };

        keydownHandler = function (event) {
            var copyShortcut = (event.ctrlKey || event.metaKey) && !event.altKey && String(event.key || '').toLowerCase() === 'c';

            if (!copyShortcut || getSelectedCmsEditorBlockIndices(holder).length === 0) {
                return;
            }

            event.preventDefault();
            copySelectedCmsEditorBlocks(editor, holder).then(function (copied) {
                if (!copied) {
                    throw new Error('Markierte Blöcke konnten nicht kopiert werden.');
                }
                showCmsEditorNotice(editor, 'Markierte Blöcke kopiert.', 'success');
            }).catch(function (error) {
                showCmsEditorNotice(editor, error && error.message ? error.message : 'Blöcke konnten nicht kopiert werden.', 'error');
            });
        };

        holder.dataset.cmsBlockClipboardBound = '1';
        holder.addEventListener('paste', pasteHandler, true);
        holder.addEventListener('keydown', keydownHandler, true);

        return function () {
            holder.removeEventListener('paste', pasteHandler, true);
            holder.removeEventListener('keydown', keydownHandler, true);
            delete holder.dataset.cmsBlockClipboardBound;
        };
    }

    function createInput(type, className, value, placeholder) {
        var input = document.createElement('input');
        input.type = type || 'text';
        input.className = className || 'form-control';
        input.value = value || '';
        if (placeholder) {
            input.placeholder = placeholder;
        }
        return input;
    }

    function normalizeLibraryImageItem(item) {
        var source = item && typeof item === 'object' ? item : {};
        var url = sanitizeEditableUrl(source.url || source.src || source.path || '');

        if (url === '') {
            return null;
        }

        return {
            file: {
                url: url,
                name: String(source.name || source.filename || '').trim(),
                path: String(source.path || '').trim(),
                size: Number(source.size || 0) || 0
            },
            caption: String(source.caption || source.alt || source.title || '').trim()
        };
    }

    function fetchImageLibraryItems(config) {
        var settings = config && typeof config === 'object' ? config : {};
        var endpoint = settings.uploadUrl || '';
        var headers = { 'X-Requested-With': 'XMLHttpRequest' };

        if (settings.csrfToken) {
            headers['X-CSRF-Token'] = settings.csrfToken;
        }

        if (!endpoint || typeof fetch !== 'function') {
            return Promise.reject(new Error('Mediathek-Endpunkt ist nicht verfügbar.'));
        }

        return fetch(buildMediaUrl(endpoint, 'list_images'), {
            method: 'GET',
            credentials: 'same-origin',
            headers: headers
        }).then(function (response) {
            return parseEditorJsonResponse(response, 'Mediathek konnte nicht geladen werden.');
        }).then(function (payload) {
            if (!payload || !payload.success) {
                throw new Error(String(payload && payload.message ? payload.message : 'Mediathek konnte nicht geladen werden.'));
            }

            return (Array.isArray(payload.items) ? payload.items : []).map(normalizeLibraryImageItem).filter(Boolean);
        });
    }

    function createImageLibraryPicker(config, options) {
        var settings = options && typeof options === 'object' ? options : {};
        var multiple = settings.multiple !== false;
        var selectedUrls = new Set(Array.isArray(settings.selectedUrls) ? settings.selectedUrls : []);
        var selectedItems = new Map();
        var overlay = createElement('div', 'cms-editorjs-media-picker');
        var dialog = createElement('div', 'cms-editorjs-media-picker__dialog');
        var header = createElement('div', 'cms-editorjs-media-picker__header');
        var title = createElement('strong', '', settings.title || 'Bild aus Mediathek auswählen');
        var closeButton = createElement('button', 'btn btn-sm btn-outline-secondary', 'Schließen');
        var search = createInput('search', 'form-control form-control-sm', '', 'Mediathek durchsuchen ...');
        var status = createElement('div', 'cms-editorjs-media-picker__status', 'Lade Mediathek ...');
        var grid = createElement('div', 'cms-editorjs-media-picker__grid');
        var footer = createElement('div', 'cms-editorjs-media-picker__footer');
        var applyButton = createElement('button', 'btn btn-sm btn-primary', multiple ? 'Auswahl übernehmen' : 'Bild übernehmen');
        var items = [];

        function closePicker() {
            overlay.remove();
            document.removeEventListener('keydown', handleKeydown);
        }

        function handleKeydown(event) {
            if (event.key === 'Escape') {
                closePicker();
            }
        }

        function applySelection() {
            var selection = Array.from(selectedItems.values());

            if (!multiple && selection.length === 0 && items.length > 0) {
                selection = [items[0]];
            }

            if (selection.length === 0) {
                status.textContent = 'Bitte mindestens ein Bild auswählen.';
                return;
            }

            if (typeof settings.onSelect === 'function') {
                settings.onSelect(selection);
            }
            closePicker();
        }

        function updateApplyButtonLabel() {
            if (!multiple) {
                return;
            }

            applyButton.textContent = selectedItems.size > 0
                ? 'Auswahl übernehmen (' + selectedItems.size + ')'
                : 'Auswahl übernehmen';
        }

        function filterItems() {
            var query = String(search.value || '').trim().toLowerCase();

            return query === '' ? items : items.filter(function (item) {
                var file = item.file || {};
                var haystack = [file.name || '', file.path || '', item.caption || '', file.url || ''].join(' ').toLowerCase();

                return haystack.indexOf(query) !== -1;
            });
        }

        function renderItems() {
            var filteredItems = filterItems();

            clearElement(grid);
            status.textContent = filteredItems.length + (filteredItems.length === 1 ? ' Bild verfügbar' : ' Bilder verfügbar');

            if (filteredItems.length === 0) {
                grid.appendChild(createElement('div', 'cms-editorjs-media-picker__empty', 'Keine passenden Bilder gefunden.'));
                return;
            }

            filteredItems.forEach(function (item) {
                var file = item.file || {};
                var url = String(file.url || '');
                var button = createElement('button', 'cms-editorjs-media-picker__item');
                var image = preparePreviewImage(document.createElement('img'), false);
                var label = createElement('span', '', file.name || file.path || 'Bild');
                var isSelected = selectedItems.has(url) || selectedUrls.has(url);

                if (isSelected) {
                    selectedItems.set(url, item);
                }

                button.type = 'button';
                button.classList.toggle('is-selected', isSelected);
                button.setAttribute('aria-pressed', isSelected ? 'true' : 'false');
                setPreviewImageSource(image, url, item.caption || file.name || '');
                button.appendChild(image);
                button.appendChild(label);
                button.addEventListener('click', function () {
                    if (!multiple) {
                        selectedItems.clear();
                        selectedItems.set(url, item);
                        applySelection();
                        return;
                    }

                    if (selectedItems.has(url)) {
                        selectedItems.delete(url);
                        selectedUrls.delete(url);
                        button.classList.remove('is-selected');
                        button.setAttribute('aria-pressed', 'false');
                    } else {
                        selectedItems.set(url, item);
                        selectedUrls.add(url);
                        button.classList.add('is-selected');
                        button.setAttribute('aria-pressed', 'true');
                    }
                    updateApplyButtonLabel();
                });
                grid.appendChild(button);
            });

            updateApplyButtonLabel();
        }

        overlay.dataset.cmsEditorUi = 'true';
        overlay.dataset.mutationFree = 'true';
        dialog.setAttribute('role', 'dialog');
        dialog.setAttribute('aria-modal', 'true');
        dialog.setAttribute('aria-label', settings.title || 'Mediathek');
        closeButton.type = 'button';
        applyButton.type = 'button';

        header.appendChild(title);
        header.appendChild(closeButton);
        footer.appendChild(status);
        if (multiple) {
            footer.appendChild(applyButton);
        }
        dialog.appendChild(header);
        dialog.appendChild(search);
        dialog.appendChild(grid);
        dialog.appendChild(footer);
        overlay.appendChild(dialog);
        document.body.appendChild(overlay);
        document.addEventListener('keydown', handleKeydown);

        closeButton.addEventListener('click', closePicker);
        overlay.addEventListener('click', function (event) {
            if (event.target === overlay) {
                closePicker();
            }
        });
        search.addEventListener('input', renderItems);
        applyButton.addEventListener('click', applySelection);

        fetchImageLibraryItems(config).then(function (loadedItems) {
            items = loadedItems;
            renderItems();
            search.focus({ preventScroll: true });
        }).catch(function (error) {
            status.textContent = error && error.message ? error.message : 'Mediathek konnte nicht geladen werden.';
            clearElement(grid);
            grid.appendChild(createElement('div', 'cms-editorjs-media-picker__empty', status.textContent));
        });
    }

    function applyTextBlockPresentation(wrapper, editable, alignment, spacing) {
        var safeAlignment = ['left', 'center', 'right', 'justify'].indexOf(alignment) !== -1 ? alignment : 'left';
        var safeSpacing = ['compact', 'normal', 'relaxed', 'loose'].indexOf(spacing) !== -1 ? spacing : 'normal';

        if (wrapper) {
            wrapper.dataset.alignment = safeAlignment;
            wrapper.dataset.spacing = safeSpacing;
        }
        if (editable) {
            editable.style.textAlign = safeAlignment === 'justify' ? 'justify' : safeAlignment;
            editable.style.marginBottom = safeSpacing === 'compact' ? '0.25rem' : (safeSpacing === 'relaxed' ? '1rem' : (safeSpacing === 'loose' ? '1.6rem' : '0.65rem'));
        }
    }

    function applyHeaderLevelPresentation(wrapper, editable, level) {
        var safeLevel = Math.min(4, Math.max(2, parseInt(level || 2, 10) || 2));

        if (wrapper) {
            wrapper.dataset.level = String(safeLevel);
        }
        if (editable) {
            editable.dataset.level = String(safeLevel);
        }
    }

    function insertLineBreakAtSelection() {
        var selection = window.getSelection ? window.getSelection() : null;
        var range;
        var br;

        if (!selection || selection.rangeCount === 0) {
            return;
        }

        range = selection.getRangeAt(0);
        range.deleteContents();
        br = document.createElement('br');
        range.insertNode(br);
        range.setStartAfter(br);
        range.collapse(true);
        selection.removeAllRanges();
        selection.addRange(range);
    }

    function getFragmentHtml(fragment) {
        var wrapper = document.createElement('div');

        wrapper.appendChild(fragment);
        return sanitizeEditableHtml(wrapper.innerHTML || '');
    }

    function splitEditableAtSelection(editable) {
        var selection = window.getSelection ? window.getSelection() : null;
        var range;
        var afterRange;
        var afterHtml;

        if (!selection || selection.rangeCount === 0 || !editable.contains(selection.anchorNode)) {
            return '';
        }

        range = selection.getRangeAt(0);
        if (!range.collapsed) {
            range.deleteContents();
        }

        afterRange = document.createRange();
        afterRange.setStart(range.startContainer, range.startOffset);
        afterRange.setEnd(editable, editable.childNodes.length);
        afterHtml = getFragmentHtml(afterRange.extractContents());

        editable.innerHTML = sanitizeEditableHtml(editable.innerHTML || '');
        return afterHtml;
    }

    function insertParagraphAfterCurrent(api, editable, text) {
        var blocks = api && api.blocks ? api.blocks : null;
        var index = getBlockIndexFromElement(editable);

        if (!blocks || typeof blocks.insert !== 'function') {
            insertLineBreakAtSelection();
            return;
        }

        if (index < 0 && typeof blocks.getCurrentBlockIndex === 'function') {
            try {
                index = blocks.getCurrentBlockIndex();
            } catch (_error) {
                index = -1;
            }
        }

        try {
            blocks.insert('paragraph', { text: sanitizeEditableHtml(text || '') }, undefined, Math.max(0, index + 1), true);
        } catch (error) {
            if (index >= 0) {
                logWarn('Indexed paragraph insert failed.', error);
                return;
            }

            try {
                blocks.insert('paragraph', { text: sanitizeEditableHtml(text || '') });
            } catch (fallbackError) {
                logWarn('Paragraph insert failed.', fallbackError);
                return;
            }
        }

        if (api && api.caret && typeof api.caret.setToBlock === 'function') {
            window.setTimeout(function () {
                try {
                    api.caret.setToBlock(Math.max(0, index + 1), 'start');
                } catch (_error) {
                    // Caret placement is a UX enhancement; content insertion already succeeded.
                }
            }, 0);
        }
    }

    function bindEditableEnterBehavior(editable, api) {
        if (!editable || editable.dataset.cmsEnterBound === '1') {
            return;
        }

        editable.dataset.cmsEnterBound = '1';
        editable.addEventListener('keydown', function (event) {
            var trailingHtml;

            if (event.key !== 'Enter' || event.isComposing) {
                return;
            }

            event.preventDefault();
            event.stopPropagation();

            if (event.shiftKey || event.altKey) {
                insertLineBreakAtSelection();
                return;
            }

            trailingHtml = splitEditableAtSelection(editable);
            insertParagraphAfterCurrent(api, editable, trailingHtml);
        });
    }

    function bindEditableLineBreakEnterBehavior(editable) {
        if (!editable || editable.dataset.cmsLineBreakEnterBound === '1') {
            return;
        }

        editable.dataset.cmsLineBreakEnterBound = '1';
        editable.addEventListener('keydown', function (event) {
            if (event.key !== 'Enter' || event.isComposing) {
                return;
            }

            event.preventDefault();
            event.stopPropagation();
            insertLineBreakAtSelection();
            notifyToolChanged(editable);
        });
    }

    function bindEditableFocusNextOnEnter(editable, nextEditable) {
        if (!editable || editable.dataset.cmsFocusNextEnterBound === '1') {
            return;
        }

        editable.dataset.cmsFocusNextEnterBound = '1';
        editable.addEventListener('keydown', function (event) {
            if (event.key !== 'Enter' || event.isComposing) {
                return;
            }

            event.preventDefault();
            event.stopPropagation();
            if (nextEditable && typeof nextEditable.focus === 'function') {
                nextEditable.focus();
            }
        });
    }

    class ParagraphTool {
        constructor(options) {
            options = options || {};
            this.data = normalizeTextBlockData(options.data || {}, 'paragraph');
            this.api = options.api || null;
            this.readOnly = !!options.readOnly;
            this.nodes = {};
        }
        static get toolbox() {
            return { title: 'Text', icon: '¶' };
        }
        static get sanitize() {
            return {
                text: getInlineSanitizeConfig(),
                alignment: false,
                spacing: false
            };
        }
        static get isReadOnlySupported() {
            return true;
        }
        static get conversionConfig() {
            return {
                export: function (data) { return stripTags(data && data.text ? data.text : ''); },
                import: function (text) { return { text: sanitizeEditableHtml(text || '') }; }
            };
        }
        render() {
            var wrapper = createElement('div', 'cms-editorjs-tool cms-editorjs-tool--paragraph');
            var text = createEditable('form-control cms-editorjs-editable cms-editorjs-paragraph', this.data.text || '', 'Text schreiben ...', this.api, this.readOnly);

            wrapper.appendChild(text);
            this.nodes = { wrapper: wrapper, text: text };
            if (!this.readOnly) {
                bindEditableEnterBehavior(text, this.api);
            }
            applyTextBlockPresentation(wrapper, text, this.data.alignment || 'left', this.data.spacing || 'normal');

            return wrapper;
        }
        save() {
            return normalizeTextBlockData({
                text: this.nodes.text ? this.nodes.text.innerHTML.trim() : '',
                alignment: this.nodes.wrapper ? this.nodes.wrapper.dataset.alignment : 'left',
                spacing: this.nodes.wrapper ? this.nodes.wrapper.dataset.spacing : 'normal'
            }, 'paragraph');
        }
    }

    window.CmsParagraphTool = ParagraphTool;

    class HeaderTool {
        constructor(options) {
            options = options || {};
            this.data = normalizeTextBlockData(options.data || {}, 'header');
            this.api = options.api || null;
            this.readOnly = !!options.readOnly;
            this.nodes = {};
        }
        static get toolbox() {
            return { title: 'Überschrift', icon: '<b>H</b>' };
        }
        static get sanitize() {
            return {
                text: getInlineSanitizeConfig(),
                level: false,
                alignment: false,
                spacing: false
            };
        }
        static get isReadOnlySupported() {
            return true;
        }
        static get conversionConfig() {
            return {
                export: function (data) { return stripTags(data && data.text ? data.text : ''); },
                import: function (text) { return { text: sanitizeEditableHtml(text || ''), level: 2 }; }
            };
        }
        render() {
            var wrapper = createElement('div', 'cms-editorjs-tool cms-editorjs-tool--header');
            var text = createEditable('form-control cms-editorjs-editable cms-editorjs-header', this.data.text || '', 'Überschrift', this.api, this.readOnly);
            var options = this.createHeaderOptions(wrapper, text, this.readOnly);

            wrapper.appendChild(text);
            wrapper.appendChild(options);
            this.nodes = { wrapper: wrapper, text: text };
            if (!this.readOnly) {
                bindEditableEnterBehavior(text, this.api);
            }
            applyTextBlockPresentation(wrapper, text, this.data.alignment || 'left', this.data.spacing || 'normal');
            applyHeaderLevelPresentation(wrapper, text, this.data.level || 2);
            return wrapper;
        }
        createHeaderOptions(wrapper, text, readOnly) {
            var options = createElement('div', 'cms-editorjs-floating-options cms-editorjs-header-options');
            var label = createElement('span', 'cms-editorjs-floating-options__label', 'Überschrift');

            options.dataset.cmsEditorUi = 'true';
            options.dataset.mutationFree = 'true';
            options.appendChild(label);

            [2, 3, 4].forEach(function (level) {
                var button = createElement('button', 'cms-editorjs-floating-options__button', 'H' + level);
                button.type = 'button';
                button.disabled = !!readOnly;
                button.addEventListener('click', function (event) {
                    event.preventDefault();
                    if (readOnly) {
                        return;
                    }
                    applyHeaderLevelPresentation(wrapper, text, level);
                    notifyToolChanged(wrapper);
                });
                options.appendChild(button);
            });

            return options;
        }
        save() {
            return normalizeTextBlockData({
                text: this.nodes.text ? this.nodes.text.innerHTML.trim() : '',
                level: Math.min(4, Math.max(2, parseInt(this.nodes.wrapper ? this.nodes.wrapper.dataset.level : '2', 10) || 2)),
                alignment: this.nodes.wrapper ? this.nodes.wrapper.dataset.alignment : 'left',
                spacing: this.nodes.wrapper ? this.nodes.wrapper.dataset.spacing : 'normal'
            }, 'header');
        }
    }

    window.CmsHeaderTool = HeaderTool;

    class ListTool {
        constructor(options) {
            options = options || {};
            this.data = normalizeListData(options.data || {});
            this.readOnly = !!options.readOnly;
            this.nodes = {};
        }
        static get toolbox() {
            return { title: 'Liste', icon: '☰' };
        }
        static get sanitize() {
            return {
                style: false,
                meta: {},
                items: getInlineSanitizeConfig()
            };
        }
        static get isReadOnlySupported() {
            return true;
        }
        static get conversionConfig() {
            return {
                export: function (data) {
                    return normalizeListData(data || {}).items.map(function (item) {
                        return stripTags(item.content || '');
                    }).join('\n');
                },
                import: function (text) {
                    return normalizeListData({
                        style: 'unordered',
                        items: String(text || '').split('\n').map(function (line) { return line.trim(); }).filter(Boolean)
                    });
                }
            };
        }
        render() {
            var wrapper = createElement('div', 'cms-editorjs-tool cms-editorjs-tool--list');
            var style = document.createElement('select');
            var textarea = document.createElement('textarea');
            ['unordered', 'ordered', 'checklist'].forEach(function (value) {
                var option = document.createElement('option');
                option.value = value;
                option.textContent = value === 'ordered' ? 'Nummeriert' : (value === 'checklist' ? 'Checkliste' : 'Aufzählung');
                style.appendChild(option);
            });
            style.className = 'form-select form-select-sm mb-2';
            style.value = ['ordered', 'unordered', 'checklist'].indexOf(this.data.style) !== -1 ? this.data.style : 'unordered';
            style.disabled = this.readOnly;
            textarea.className = 'form-control';
            textarea.rows = 5;
            textarea.readOnly = this.readOnly;
            textarea.value = Array.isArray(this.data.items) ? this.data.items.map(function (item) {
                var content = stripTags(item && typeof item === 'object' ? item.content : item);
                var checked = !!(item && typeof item === 'object' && ((item.meta && item.meta.checked) || item.checked));

                return style.value === 'checklist' ? (checked ? '[x] ' : '[ ] ') + content : content;
            }).join('\n') : '';
            textarea.placeholder = 'Ein Listenpunkt pro Zeile' + (style.value === 'checklist' ? ' – optional mit [x] erledigt markieren' : '');
            style.addEventListener('change', function () {
                textarea.placeholder = 'Ein Listenpunkt pro Zeile' + (style.value === 'checklist' ? ' – optional mit [x] erledigt markieren' : '');
            });
            wrapper.appendChild(style);
            wrapper.appendChild(textarea);
            this.nodes = { style: style, textarea: textarea };
            return wrapper;
        }
        save() {
            var style = this.nodes.style ? this.nodes.style.value : 'unordered';
            return normalizeListData({
                style: style,
                items: String(this.nodes.textarea ? this.nodes.textarea.value : '').split('\n').map(function (item) {
                    var line = item.trim();
                    var checked = /^\[(?:x|X|✓|✔)\]\s*/.test(line);
                    var unchecked = /^\[\s\]\s*/.test(line);
                    var content = line.replace(/^\[(?:x|X|✓|✔|\s)\]\s*/, '').trim();

                    if (style === 'checklist') {
                        return {
                            content: content,
                            meta: { checked: checked && !unchecked },
                            items: []
                        };
                    }

                    return content;
                }).filter(function (item) {
                    return typeof item === 'string'
                        ? item !== ''
                        : !!(item && stripTags(item.content || '') !== '');
                })
            });
        }
    }

    window.CmsListTool = ListTool;

    class ImageTool {
        constructor(options) {
            options = options || {};
            this.data = normalizeImageData(options.data || {});
            this.config = options.config || {};
            this.block = options.block || null;
            this.readOnly = !!options.readOnly;
            this.nodes = {};
        }
        static get toolbox() {
            return { title: 'Bild', icon: '▧' };
        }
        static get sanitize() {
            return {
                url: false,
                caption: false,
                alignment: false,
                size: false,
                widthPreset: false,
                borderStyle: false,
                imageFit: false,
                objectFit: false,
                maxHeight: false,
                sourceUrl: false,
                withBorder: false,
                withBackground: false,
                stretched: false,
                rounded: false,
                shadow: false
            };
        }
        static get pasteConfig() {
            return {
                tags: ['IMG'],
                files: {
                    mimeTypes: ['image/*'],
                    extensions: ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'ico']
                },
                patterns: {
                    image: /^(?:https?:\/\/|\/|\.\/|\.\.\/).+\.(?:jpe?g|png|gif|webp|bmp|ico)(?:[?#].*)?$/i
                }
            };
        }
        static get isReadOnlySupported() {
            return true;
        }
        render() {
            var wrapper = createElement('div', 'cms-editorjs-tool cms-editorjs-tool--image');
            var url = createInput('hidden', '', (this.data.file && this.data.file.url) || this.data.url || '', '');
            var caption = createInput('text', 'form-control mb-2', this.data.caption || '', 'Bildunterschrift');
            var sourceUrl = createInput('url', 'form-control mb-2', this.data.sourceUrl || '', 'https://quelle.example');
            var sourceField = createElement('label', 'cms-editorjs-image-source-field');
            var upload = createInput('file', 'form-control form-control-sm', '', '');
            var libraryButton = createElement('button', 'btn btn-sm btn-outline-primary', 'Aus Mediathek wählen');
            var optionsPanel = createElement('div', 'cms-editorjs-floating-options cms-editorjs-image-options');
            var optionsLabel = createElement('span', 'cms-editorjs-floating-options__label', 'Bild');
            var settings = createElement('div', 'cms-editorjs-image-settings');
            var preview = createElement('figure', 'cms-editorjs-image-preview');
            var previewImage = preparePreviewImage(document.createElement('img'), false);
            var alignment = this.createSelect([
                ['left', 'Links'],
                ['center', 'Mittig'],
                ['right', 'Rechts']
            ], this.data.alignment || 'center');
            var size = this.createSelect([
                ['normal', 'Normal'],
                ['wide', 'Breit'],
                ['full', 'Strecken']
            ], this.data.size || this.data.widthPreset || (this.data.stretched ? 'full' : 'normal'));
            var borderStyle = this.createSelect([
                ['none', 'Kein Rahmen'],
                ['thin', 'Dünn'],
                ['medium', 'Mittel'],
                ['thick', 'Dick']
            ], this.data.borderStyle || (this.data.withBorder ? 'thin' : 'none'));
            var imageFit = this.createSelect(this.getImageFitOptions(), this.data.imageFit || this.data.objectFit || 'contain');
            var maxHeight = this.createSelect(this.getMaxHeightOptions(), this.data.maxHeight || '0');
            var withBackground = this.createCheckbox('Heller Hintergrund', !!this.data.withBackground);
            var rounded = this.createCheckbox('Ecken abrunden', this.data.rounded !== false);
            var shadow = this.createCheckbox('Leichter Schatten', !!this.data.shadow);
            var status = createElement('div', 'form-hint mt-1');
            var updatePreview = this.updatePreview.bind(this, wrapper, preview, previewImage, url, caption, alignment, size, borderStyle, imageFit, maxHeight, withBackground.input, rounded.input, shadow.input);
            var self = this;

            optionsPanel.dataset.cmsEditorUi = 'true';
            optionsPanel.dataset.mutationFree = 'true';
            upload.accept = 'image/*';
            upload.disabled = this.readOnly;
            libraryButton.type = 'button';
            libraryButton.disabled = this.readOnly;
            upload.addEventListener('change', this.uploadSelectedFile.bind(this, url, status));
            libraryButton.addEventListener('click', this.openImageLibraryPicker.bind(this));

            caption.classList.add('cms-editorjs-image-caption');
            caption.setAttribute('aria-label', 'Bildunterschrift');
            caption.readOnly = this.readOnly;
            sourceUrl.classList.add('cms-editorjs-image-source');
            sourceUrl.setAttribute('aria-label', 'Quellwebsite des Bildes');
            sourceUrl.readOnly = this.readOnly;
            sourceField.appendChild(createElement('span', '', 'Quellwebsite'));
            sourceField.appendChild(sourceUrl);

            [alignment, size, borderStyle, imageFit, maxHeight, withBackground.input, rounded.input, shadow.input].forEach((control) => {
                control.disabled = this.readOnly;
            });

            settings.appendChild(this.createSetting('Ausrichtung', alignment));
            settings.appendChild(this.createSetting('Breite', size));
            settings.appendChild(this.createSetting('Rahmen', borderStyle));
            settings.appendChild(this.createSetting('Skalierung', imageFit));
            settings.appendChild(this.createSetting('Max. Höhe', maxHeight));
            settings.appendChild(withBackground.wrapper);
            settings.appendChild(rounded.wrapper);
            settings.appendChild(shadow.wrapper);

            preview.appendChild(previewImage);
            [url, caption, sourceUrl, alignment, size, borderStyle, imageFit, maxHeight, withBackground.input, rounded.input, shadow.input].forEach(function (element) {
                var handleControlChange = function () {
                    updatePreview();
                    if (self.block && typeof self.block.dispatchChange === 'function') {
                        self.block.dispatchChange();
                    }
                    notifyToolChanged(self.nodes.wrapper || wrapper);
                };

                element.addEventListener('input', handleControlChange);
                element.addEventListener('change', handleControlChange);
            });

            wrapper.appendChild(url);
            wrapper.appendChild(preview);
            wrapper.appendChild(caption);
            wrapper.appendChild(sourceField);
            optionsPanel.appendChild(optionsLabel);
            optionsPanel.appendChild(upload);
            optionsPanel.appendChild(libraryButton);
            optionsPanel.appendChild(settings);
            wrapper.appendChild(optionsPanel);
            wrapper.appendChild(status);
            this.nodes = {
                wrapper: wrapper,
                url: url,
                caption: caption,
                sourceUrl: sourceUrl,
                upload: upload,
                libraryButton: libraryButton,
                alignment: alignment,
                size: size,
                borderStyle: borderStyle,
                imageFit: imageFit,
                maxHeight: maxHeight,
                withBackground: withBackground.input,
                rounded: rounded.input,
                shadow: shadow.input,
                preview: preview,
                previewImage: previewImage,
                status: status
            };
            updatePreview();
            return wrapper;
        }
        createSelect(options, value) {
            var select = document.createElement('select');
            select.className = 'form-select form-select-sm';
            options.forEach(function (item) {
                var option = document.createElement('option');
                option.value = item[0];
                option.textContent = item[1];
                select.appendChild(option);
            });
            select.value = value;
            return select;
        }
        createSetting(labelText, control) {
            var label = createElement('label', 'cms-editorjs-image-settings__field');
            var text = createElement('span', '', labelText);
            label.appendChild(text);
            label.appendChild(control);
            return label;
        }
        createCheckbox(labelText, checked) {
            var label = createElement('label', 'cms-editorjs-image-settings__check');
            var input = document.createElement('input');
            var text = createElement('span', '', labelText);
            input.type = 'checkbox';
            input.checked = checked;
            label.appendChild(input);
            label.appendChild(text);
            return { wrapper: label, input: input };
        }
        getImageFitOptions() {
            return [
                ['contain', 'Anpassen'],
                ['cover', 'Ausschnitt'],
                ['fill', 'Strecken'],
                ['scale-down', 'Verkleinern'],
                ['none', 'Original']
            ];
        }
        getMaxHeightOptions() {
            return [
                ['0', 'Automatisch'],
                ['200', '200 px'],
                ['300', '300 px'],
                ['400', '400 px'],
                ['500', '500 px'],
                ['600', '600 px'],
                ['800', '800 px'],
                ['1000', '1000 px']
            ];
        }
        updatePreview(wrapper, preview, image, urlInput, captionInput, alignmentInput, sizeInput, borderInput, imageFitInput, maxHeightInput, backgroundInput, roundedInput, shadowInput) {
            var url = String(urlInput.value || '').trim();
            var caption = String(captionInput.value || '').trim();
            var alignment = alignmentInput.value || 'center';
            var size = sizeInput.value || 'normal';
            var borderStyle = borderInput.value || 'none';
            var imageFit = normalizeImageFitValue(imageFitInput.value || 'contain', 'contain');
            var maxHeight = normalizeImageMaxHeightValue(maxHeightInput.value || '0');

            preview.className = [
                'cms-editorjs-image-preview',
                'cms-editorjs-image-preview--align-' + alignment,
                'cms-editorjs-image-preview--' + size,
                'cms-editorjs-image-preview--border-' + borderStyle,
                'cms-editorjs-image-preview--fit-' + imageFit,
                backgroundInput.checked ? 'cms-editorjs-image-preview--background' : '',
                roundedInput.checked ? 'cms-editorjs-image-preview--rounded' : '',
                shadowInput.checked ? 'cms-editorjs-image-preview--shadow' : ''
            ].filter(Boolean).join(' ');
            preview.dataset.imageFit = imageFit;
            preview.dataset.maxHeight = maxHeight;
            preview.style.setProperty('--cms-editorjs-image-fit', imageFit);
            image.style.objectFit = imageFit;
            image.style.maxHeight = maxHeight !== '0' ? maxHeight + 'px' : '';
            image.style.height = maxHeight !== '0' && ['cover', 'fill'].indexOf(imageFit) !== -1 ? maxHeight + 'px' : 'auto';

            if (url) {
                setPreviewImageSource(image, url, caption);
                preview.hidden = false;
            } else {
                setPreviewImageSource(image, '', '');
                preview.hidden = true;
            }

            wrapper.classList.toggle('is-empty', !url);
        }
        uploadSelectedFile(urlInput, status, event) {
            var file = event.target.files && event.target.files[0] ? event.target.files[0] : null;
            var validationError = validateEditorImageFile(file);

            if (validationError !== '') {
                status.textContent = validationError;
                return;
            }

            if (!file) {
                return;
            }

            status.textContent = 'Upload läuft ...';
            this.uploadImageFile(file).then(function (filePayload) {
                urlInput.value = filePayload.url || '';
                urlInput.dispatchEvent(new Event('input', { bubbles: true }));
                status.textContent = 'Upload abgeschlossen.';
            }).catch(function (error) {
                status.textContent = error && error.message ? error.message : 'Upload fehlgeschlagen.';
                logWarn('Image upload failed.', error);
            });
        }
        openImageLibraryPicker(event) {
            var self = this;

            if (event) {
                event.preventDefault();
            }
            if (this.readOnly) {
                return;
            }

            createImageLibraryPicker(this.config, {
                multiple: false,
                title: 'Bild aus Mediathek auswählen',
                selectedUrls: this.nodes.url && this.nodes.url.value ? [this.nodes.url.value] : [],
                onSelect: function (items) {
                    var item = Array.isArray(items) && items[0] ? items[0] : null;
                    var file = item && item.file ? item.file : {};

                    self.setImageUrl(file.url || '', item ? (item.caption || '') : '');
                }
            });
        }
        uploadImageFile(file) {
            var endpoint = this.config.uploadUrl || '';
            var validationError = validateEditorImageFile(file);
            var body;

            if (validationError !== '') {
                return Promise.reject(new Error(validationError));
            }

            if (this.config.uploader && typeof this.config.uploader.uploadByFile === 'function') {
                return this.config.uploader.uploadByFile(file).then(function (payload) {
                    return normalizeImageResponse(payload).file;
                });
            }

            if (!file || !endpoint || typeof fetch !== 'function') {
                return Promise.reject(new Error('Upload-Endpunkt ist nicht verfügbar.'));
            }

            body = new FormData();
            body.append('action', 'upload_image');
            body.append('image', file);
            body.append('csrf_token', this.config.csrfToken || '');

            return fetch(endpoint, {
                method: 'POST',
                body: body,
                credentials: 'same-origin',
                headers: this.config.csrfToken ? { 'X-CSRF-Token': this.config.csrfToken } : {}
            }).then(function (response) {
                return parseEditorJsonResponse(response, 'Bild konnte nicht hochgeladen werden.');
            }).then(function (payload) {
                return normalizeImageResponse(payload).file;
            });
        }
        setImageUrl(url, caption) {
            var cleanUrl = sanitizeEditableUrl(url);

            if (cleanUrl === '') {
                return;
            }

            this.data = normalizeImageData(Object.assign({}, this.data, {
                file: Object.assign({}, this.data.file || {}, { url: cleanUrl }),
                caption: typeof caption === 'string' ? caption : this.data.caption
            }));

            if (this.nodes.url) {
                this.nodes.url.value = cleanUrl;
            }
            if (this.nodes.caption && typeof caption === 'string') {
                this.nodes.caption.value = caption;
            }
            if (this.nodes.url) {
                this.nodes.url.dispatchEvent(new Event('input', { bubbles: true }));
            }
            if (this.nodes.status) {
                this.nodes.status.textContent = 'Bild eingefügt.';
            }
            notifyToolChanged(this.nodes.wrapper);
        }
        handlePastedImageUrl(url, caption) {
            var cleanUrl = sanitizeEditableUrl(url);
            var self = this;

            if (cleanUrl === '') {
                return;
            }

            if (this.nodes.status) {
                this.nodes.status.textContent = 'Bild wird übernommen ...';
            }

            if (this.config.uploader && typeof this.config.uploader.uploadByUrl === 'function' && /^https?:\/\//i.test(cleanUrl)) {
                this.config.uploader.uploadByUrl(cleanUrl).then(function (payload) {
                    var filePayload = normalizeImageResponse(payload).file;
                    self.setImageUrl(filePayload.url, caption || '');
                }).catch(function (error) {
                    logWarn('Image URL upload failed, using original URL.', error);
                    self.setImageUrl(cleanUrl, caption || '');
                });
                return;
            }

            this.setImageUrl(cleanUrl, caption || '');
        }
        onPaste(event) {
            var detail = event && event.detail ? event.detail : {};
            var pastedNode;
            var file;
            var self = this;

            if (this.readOnly) {
                return;
            }

            if (event.type === 'tag') {
                pastedNode = detail.data;
                this.handlePastedImageUrl(pastedNode && pastedNode.getAttribute ? pastedNode.getAttribute('src') || '' : '', pastedNode && pastedNode.getAttribute ? pastedNode.getAttribute('alt') || '' : '');
                return;
            }

            if (event.type === 'pattern') {
                this.handlePastedImageUrl(detail.data || '', '');
                return;
            }

            if (event.type === 'file') {
                file = detail.file || null;
                if (this.nodes.status) {
                    this.nodes.status.textContent = 'Bild wird hochgeladen ...';
                }
                this.uploadImageFile(file).then(function (filePayload) {
                    self.setImageUrl(filePayload.url || '', '');
                }).catch(function (error) {
                    if (self.nodes.status) {
                        self.nodes.status.textContent = error && error.message ? error.message : 'Upload fehlgeschlagen.';
                    }
                    logWarn('Pasted image upload failed.', error);
                });
            }
        }
        save() {
            var size = this.nodes.size ? this.nodes.size.value : 'normal';
            var borderStyle = this.nodes.borderStyle ? this.nodes.borderStyle.value : 'none';

            return normalizeImageData({
                file: { url: this.nodes.url ? this.nodes.url.value.trim() : '' },
                caption: this.nodes.caption ? this.nodes.caption.value.trim() : '',
                sourceUrl: this.nodes.sourceUrl ? this.nodes.sourceUrl.value.trim() : '',
                alignment: this.nodes.alignment ? this.nodes.alignment.value : 'center',
                size: size,
                widthPreset: size,
                borderStyle: borderStyle,
                imageFit: this.nodes.imageFit ? this.nodes.imageFit.value : 'contain',
                maxHeight: this.nodes.maxHeight ? this.nodes.maxHeight.value : '0',
                withBorder: borderStyle !== 'none',
                withBackground: !!(this.nodes.withBackground && this.nodes.withBackground.checked),
                stretched: size === 'full',
                rounded: !!(this.nodes.rounded && this.nodes.rounded.checked),
                shadow: !!(this.nodes.shadow && this.nodes.shadow.checked)
            });
        }
    }

    window.CmsImageTool = ImageTool;

    class ImageGalleryTool {
        constructor(options) {
            options = options || {};
            this.data = normalizeGalleryData(options.data || {});
            this.config = options.config || {};
            this.readOnly = !!options.readOnly;
            this.nodes = {};
            this.images = this.data.images.slice();
        }
        static get toolbox() {
            return { title: 'Galerie', icon: '▦' };
        }
        static get sanitize() {
            return {
                columns: false,
                borderStyle: false,
                withBorder: false,
                images: false,
                urls: false
            };
        }
        static get pasteConfig() {
            return {
                tags: ['IMG'],
                files: {
                    mimeTypes: ['image/*'],
                    extensions: ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'ico']
                }
            };
        }
        static get isReadOnlySupported() {
            return true;
        }
        render() {
            var wrapper = createElement('div', 'cms-editorjs-tool cms-editorjs-tool--gallery');
            var controls = createElement('div', 'cms-editorjs-floating-options cms-editorjs-gallery__controls');
            var controlsLabel = createElement('span', 'cms-editorjs-floating-options__label', 'Galerie');
            var columns = this.createColumnsSelect();
            var borderStyle = this.createBorderStyleSelect();
            var upload = createInput('file', 'form-control form-control-sm', '', '');
            var libraryButton = createElement('button', 'btn btn-sm btn-outline-primary', 'Aus Mediathek wählen');
            var okButton = createElement('button', 'btn btn-sm btn-primary', 'OK – Galerie übernehmen');
            var status = createElement('div', 'form-hint');
            var list = createElement('div', 'cms-editorjs-gallery__grid');
            var self = this;

            wrapper.classList.add('cms-editorjs-gallery');
            wrapper.classList.toggle('is-empty', this.images.length === 0);
            controls.dataset.cmsEditorUi = 'true';
            controls.dataset.mutationFree = 'true';
            upload.accept = 'image/*';
            upload.multiple = true;
            upload.disabled = this.readOnly;
            libraryButton.type = 'button';
            libraryButton.disabled = this.readOnly;
            okButton.type = 'button';
            okButton.disabled = this.readOnly;
            columns.disabled = this.readOnly;
            borderStyle.disabled = this.readOnly;

            controls.appendChild(controlsLabel);
            controls.appendChild(this.createGalleryField('Spalten', columns));
            controls.appendChild(this.createGalleryField('Gesamtrahmen', borderStyle));
            controls.appendChild(this.createGalleryField('Upload', upload));
            controls.appendChild(libraryButton);
            controls.appendChild(okButton);
            controls.appendChild(status);
            wrapper.appendChild(list);
            wrapper.appendChild(controls);

            this.nodes = { wrapper: wrapper, columns: columns, borderStyle: borderStyle, upload: upload, libraryButton: libraryButton, okButton: okButton, status: status, list: list };
            this.updateGalleryLayout();
            this.renderImageList();

            upload.addEventListener('change', this.uploadSelectedFiles.bind(this));
            libraryButton.addEventListener('click', function (event) {
                event.preventDefault();
                self.openImageLibraryPicker();
            });
            columns.addEventListener('change', function () {
                self.updateGalleryLayout();
                notifyToolChanged(wrapper);
            });
            borderStyle.addEventListener('change', function () {
                self.updateGalleryLayout();
                notifyToolChanged(wrapper);
            });
            okButton.addEventListener('click', function () {
                status.textContent = 'Galerie übernommen.';
                notifyToolChanged(wrapper);
            });

            return wrapper;
        }
        createColumnsSelect() {
            var select = document.createElement('select');
            select.className = 'form-select form-select-sm';
            [2, 3, 4, 5, 6].forEach(function (columns) {
                var option = document.createElement('option');
                option.value = String(columns);
                option.textContent = String(columns) + ' Spalten';
                select.appendChild(option);
            });
            select.value = String(this.data.columns || 3);
            return select;
        }
        createBorderStyleSelect() {
            var select = document.createElement('select');
            select.className = 'form-select form-select-sm';
            [
                ['none', 'Kein Rahmen'],
                ['thin', 'Dünn'],
                ['medium', 'Mittel'],
                ['thick', 'Dick']
            ].forEach(function (item) {
                var option = document.createElement('option');
                option.value = item[0];
                option.textContent = item[1];
                select.appendChild(option);
            });
            select.value = String(this.data.borderStyle || (this.data.withBorder ? 'thin' : 'none'));
            return select;
        }
        updateGalleryLayout() {
            var columns = parseInt(this.nodes.columns ? this.nodes.columns.value : this.data.columns || 3, 10) || 3;
            var borderStyle = String(this.nodes.borderStyle ? this.nodes.borderStyle.value : (this.data.borderStyle || (this.data.withBorder ? 'thin' : 'none')));

            if ([2, 3, 4, 5, 6].indexOf(columns) === -1) {
                columns = 3;
            }
            if (['none', 'thin', 'medium', 'thick'].indexOf(borderStyle) === -1) {
                borderStyle = 'none';
            }

            if (this.nodes.wrapper) {
                this.nodes.wrapper.dataset.columns = String(columns);
                this.nodes.wrapper.dataset.border = borderStyle;
                this.nodes.wrapper.classList.remove('cms-editorjs-gallery--border-none', 'cms-editorjs-gallery--border-thin', 'cms-editorjs-gallery--border-medium', 'cms-editorjs-gallery--border-thick');
                this.nodes.wrapper.classList.add('cms-editorjs-gallery--border-' + borderStyle);
            }
            if (this.nodes.list) {
                this.nodes.list.style.setProperty('--cms-editor-gallery-columns', String(columns));
            }
        }
        createGalleryField(labelText, control) {
            var label = createElement('label', 'cms-editorjs-gallery__field');
            var text = createElement('span', '', labelText);
            label.appendChild(text);
            label.appendChild(control);
            return label;
        }
        openImageLibraryPicker() {
            var self = this;

            if (this.readOnly) {
                return;
            }

            createImageLibraryPicker(this.config, {
                multiple: true,
                title: 'Galerie-Bilder aus Mediathek auswählen',
                selectedUrls: this.images.map(function (image) {
                    return image && image.file ? String(image.file.url || '') : '';
                }).filter(Boolean),
                onSelect: function (items) {
                    self.addLibraryImages(items);
                }
            });
        }
        addLibraryImages(items) {
            var seen = new Set(this.images.map(function (image) {
                return image && image.file ? String(image.file.url || '') : '';
            }).filter(Boolean));
            var added = 0;
            var self = this;

            (Array.isArray(items) ? items : []).forEach(function (item) {
                var normalized = normalizeGalleryData({ images: [item] }).images[0] || null;
                var url = normalized && normalized.file ? String(normalized.file.url || '') : '';

                if (!normalized || url === '' || seen.has(url)) {
                    return;
                }

                seen.add(url);
                self.images.push(normalized);
                added += 1;
            });

            this.renderImageList();
            if (this.nodes.status) {
                this.nodes.status.textContent = added > 0
                    ? added + (added === 1 ? ' Bild aus der Mediathek eingefügt.' : ' Bilder aus der Mediathek eingefügt.')
                    : 'Keine neuen Bilder eingefügt.';
            }
            notifyToolChanged(this.nodes.wrapper);
        }
        uploadSelectedFiles(event) {
            if (this.readOnly) {
                return;
            }

            var files = Array.prototype.slice.call(event.target.files || []).filter(function (file) {
                return file && validateEditorImageFile(file) === '';
            });
            var self = this;
            var queue = Promise.resolve();

            if (files.length === 0) {
                this.nodes.status.textContent = 'Bitte gültige Bilder bis maximal 25 MB auswählen.';
                return;
            }

            this.nodes.status.textContent = files.length === 1 ? 'Bild wird hochgeladen ...' : String(files.length) + ' Bilder werden hochgeladen ...';
            files.forEach(function (file) {
                queue = queue.then(function () {
                    return self.uploadGalleryImage(file).then(function (filePayload) {
                        self.images.push({ file: filePayload, caption: '' });
                        self.renderImageList();
                    });
                });
            });

            queue.then(function () {
                self.nodes.status.textContent = 'Auswahl bereit. Weitere Bilder wählen oder mit OK bestätigen.';
                self.nodes.upload.value = '';
                notifyToolChanged(self.nodes.wrapper);
            }).catch(function (error) {
                self.nodes.status.textContent = error && error.message ? error.message : 'Galerie-Upload fehlgeschlagen.';
                logWarn('Gallery upload failed.', error);
            });
        }
        uploadGalleryImage(file) {
            var endpoint = this.config.uploadUrl || '';
            var validationError = validateEditorImageFile(file);
            var body = new FormData();

            if (validationError !== '') {
                return Promise.reject(new Error(validationError));
            }

            if (this.config.uploader && typeof this.config.uploader.uploadByFile === 'function') {
                return this.config.uploader.uploadByFile(file).then(function (payload) {
                    return normalizeImageResponse(payload).file;
                });
            }

            if (!endpoint || typeof fetch !== 'function') {
                return Promise.reject(new Error('Upload-Endpunkt ist nicht verfügbar.'));
            }

            body.append('action', 'upload_image');
            body.append('image', file);
            body.append('csrf_token', this.config.csrfToken || '');

            return fetch(endpoint, {
                method: 'POST',
                body: body,
                credentials: 'same-origin',
                headers: this.config.csrfToken ? { 'X-CSRF-Token': this.config.csrfToken } : {}
            }).then(function (response) {
                return parseEditorJsonResponse(response, 'Galeriebild konnte nicht hochgeladen werden.');
            }).then(function (payload) {
                var filePayload = payload && payload.file && typeof payload.file === 'object' ? payload.file : {};
                var url = String(filePayload.url || payload && payload.url || '');

                if (!payload || !payload.success || url === '') {
                    throw new Error(String(payload && payload.message ? payload.message : 'Bild konnte nicht hochgeladen werden.'));
                }

                return Object.assign({}, filePayload, { url: url });
            });
        }
        renderImageList() {
            var self = this;

            if (!this.nodes.list) {
                return;
            }

            clearElement(this.nodes.list);
            this.updateGalleryLayout();
            if (this.nodes.wrapper) {
                this.nodes.wrapper.classList.toggle('is-empty', this.images.length === 0);
            }
            if (this.images.length === 0) {
                var empty = createElement('div', 'cms-editorjs-gallery__empty');
                var emptyText = createElement('span', '', 'Noch keine Bilder in dieser Galerie.');
                var chooseButton = createElement('button', 'btn btn-sm btn-primary', 'Bilder auswählen');

                chooseButton.type = 'button';
                chooseButton.disabled = this.readOnly;
                chooseButton.addEventListener('click', function (event) {
                    event.preventDefault();
                    event.stopPropagation();
                    if (!self.readOnly && self.nodes.upload) {
                        self.nodes.upload.click();
                    }
                });

                empty.appendChild(emptyText);
                empty.appendChild(chooseButton);
                this.nodes.list.appendChild(empty);
                return;
            }

            this.images.forEach(function (image, index) {
                var imageUrl = image && image.file && image.file.url ? image.file.url : '';

                if (imageUrl === '') {
                    return;
                }

                var item = createElement('div', 'cms-editorjs-gallery__item');
                var thumb = preparePreviewImage(document.createElement('img'), false);
                var caption = createInput('text', 'form-control form-control-sm', image.caption || '', 'Bildunterschrift');
                var moveUp = createElement('button', 'btn btn-sm btn-outline-secondary', 'Hoch');
                var moveDown = createElement('button', 'btn btn-sm btn-outline-secondary', 'Runter');
                var remove = createElement('button', 'btn btn-sm btn-outline-danger', 'Entfernen');

                setPreviewImageSource(thumb, imageUrl, image.caption || '');
                moveUp.type = 'button';
                moveDown.type = 'button';
                remove.type = 'button';
                caption.readOnly = self.readOnly;
                moveUp.disabled = self.readOnly || index === 0;
                moveDown.disabled = self.readOnly || index >= self.images.length - 1;
                remove.disabled = self.readOnly;
                caption.addEventListener('input', function () {
                    if (self.readOnly) {
                        return;
                    }
                    self.images[index].caption = caption.value;
                    thumb.alt = caption.value;
                    notifyToolChanged(self.nodes.wrapper);
                });
                moveUp.addEventListener('click', function () {
                    var previous;

                    if (self.readOnly || index <= 0) {
                        return;
                    }

                    previous = self.images[index - 1];
                    self.images[index - 1] = self.images[index];
                    self.images[index] = previous;
                    self.renderImageList();
                    notifyToolChanged(self.nodes.wrapper);
                });
                moveDown.addEventListener('click', function () {
                    var next;

                    if (self.readOnly || index >= self.images.length - 1) {
                        return;
                    }

                    next = self.images[index + 1];
                    self.images[index + 1] = self.images[index];
                    self.images[index] = next;
                    self.renderImageList();
                    notifyToolChanged(self.nodes.wrapper);
                });
                remove.addEventListener('click', function () {
                    if (self.readOnly) {
                        return;
                    }
                    self.images.splice(index, 1);
                    self.renderImageList();
                    notifyToolChanged(self.nodes.wrapper);
                });

                item.appendChild(thumb);
                item.appendChild(caption);
                item.appendChild(moveUp);
                item.appendChild(moveDown);
                item.appendChild(remove);
                self.nodes.list.appendChild(item);
            });
        }
        save() {
            return normalizeGalleryData({
                columns: this.nodes.columns ? this.nodes.columns.value : this.data.columns,
                borderStyle: this.nodes.borderStyle ? this.nodes.borderStyle.value : (this.data.borderStyle || 'none'),
                images: this.images
            });
        }
        onPaste(event) {
            var detail = event && event.detail ? event.detail : {};
            var pastedNode;
            var pastedUrl;
            var file;
            var self = this;

            if (this.readOnly) {
                return;
            }

            if (event.type === 'tag') {
                pastedNode = detail.data;
                pastedUrl = pastedNode && pastedNode.getAttribute ? sanitizeEditableUrl(pastedNode.getAttribute('src') || '') : '';
                if (pastedUrl !== '') {
                    this.images.push({ file: { url: pastedUrl }, caption: pastedNode.getAttribute('alt') || '' });
                    this.renderImageList();
                    notifyToolChanged(this.nodes.wrapper);
                }
                return;
            }

            if (event.type === 'file') {
                file = detail.file || null;
                if (this.nodes.status) {
                    this.nodes.status.textContent = 'Bild wird hochgeladen ...';
                }
                this.uploadGalleryImage(file).then(function (filePayload) {
                    self.images.push({ file: filePayload, caption: '' });
                    self.renderImageList();
                    notifyToolChanged(self.nodes.wrapper);
                    if (self.nodes.status) {
                        self.nodes.status.textContent = 'Bild eingefügt.';
                    }
                }).catch(function (error) {
                    if (self.nodes.status) {
                        self.nodes.status.textContent = error && error.message ? error.message : 'Galerie-Upload fehlgeschlagen.';
                    }
                    logWarn('Pasted gallery image upload failed.', error);
                });
            }
        }
    }

    window.CmsImageGalleryTool = ImageGalleryTool;

    class MediaTextTool {
        constructor(options) {
            options = options || {};
            this.data = normalizeMediaTextData(options.data || {});
            this.config = options.config || {};
            this.api = options.api || null;
            this.readOnly = !!options.readOnly;
            this.nodes = {};
        }
        static get toolbox() {
            return { title: 'Bild + Text', icon: '▣' };
        }
        static get sanitize() {
            return {
                file: false,
                alt: false,
                heading: false,
                text: getMediaTextSanitizeConfig(),
                imagePosition: false,
                imageWidth: false,
                imageFit: false,
                objectFit: false,
                verticalAlignment: false,
                showBorder: false,
                spacingTop: false,
                spacingBottom: false
            };
        }
        static get pasteConfig() {
            return {
                tags: ['IMG'],
                files: {
                    mimeTypes: ['image/*'],
                    extensions: ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'ico']
                },
                patterns: {
                    image: /^(?:https?:\/\/|\/|\.\/|\.\.\/).+\.(?:jpe?g|png|gif|webp|bmp|ico)(?:[?#].*)?$/i
                }
            };
        }
        static get isReadOnlySupported() {
            return true;
        }
        static get conversionConfig() {
            return {
                export: function (data) { return stripTags(data && data.text ? data.text : ''); },
                import: function (text) { return normalizeMediaTextData({ text: sanitizeEditableHtml(text || '') }); }
            };
        }
        render() {
            var wrapper = createElement('div', 'cms-editorjs-tool cms-editorjs-tool--media-text');
            var preview = createElement('div', 'cms-editorjs-media-text-preview');
            var headingPreview = createElement('h4', 'cms-editorjs-media-text-preview__heading');
            var media = createElement('figure', 'cms-editorjs-media-text-preview__media');
            var image = preparePreviewImage(document.createElement('img'), false);
            var content = createEditable('cms-editorjs-editable cms-editorjs-media-text-preview__content', this.data.text || '', 'Text neben dem Bild schreiben ...', this.api, this.readOnly, true);
            var url = createInput('hidden', '', (this.data.file && this.data.file.url) || '', '');
            var controls = createElement('div', 'cms-editorjs-floating-options cms-editorjs-media-text-options');
            var label = createElement('span', 'cms-editorjs-floating-options__label', 'Bild + Text');
            var upload = createInput('file', 'form-control form-control-sm', '', '');
            var libraryButton = createElement('button', 'btn btn-sm btn-outline-primary', 'Aus Mediathek wählen');
            var position = this.createSelect([
                ['left', 'Bild links'],
                ['right', 'Bild rechts']
            ], this.data.imagePosition || 'left');
            var width = this.createSelect([
                ['33', 'Bild 33 %'],
                ['40', 'Bild 40 %'],
                ['50', 'Bild 50 %']
            ], this.data.imageWidth || '40');
            var imageFit = this.createSelect(this.getImageFitOptions(), this.data.imageFit || this.data.objectFit || 'cover');
            var verticalAlignment = this.createSelect([
                ['top', 'Oben bündig'],
                ['center', 'Mittig bündig'],
                ['bottom', 'Unten bündig']
            ], this.data.verticalAlignment || 'top');
            var border = createInput('checkbox', 'form-check-input', '', '');
            var heading = createInput('text', 'form-control form-control-sm', this.data.heading || '', 'Optionale Überschrift');
            var spacingTop = this.createSelect(this.getSpacingOptions(), this.data.spacingTop || '10');
            var spacingBottom = this.createSelect(this.getSpacingOptions(), this.data.spacingBottom || '10');
            var alt = createInput('text', 'form-control form-control-sm', this.data.alt || '', 'Alt-Text');
            var status = createElement('div', 'form-hint');
            var updatePreview = this.updatePreview.bind(this, wrapper, preview, headingPreview, image, url, alt, position, width, imageFit, verticalAlignment, border, heading, spacingTop, spacingBottom);

            wrapper.classList.add('is-empty');
            controls.dataset.cmsEditorUi = 'true';
            controls.dataset.mutationFree = 'true';
            upload.accept = 'image/*';
            upload.disabled = this.readOnly;
            libraryButton.type = 'button';
            libraryButton.disabled = this.readOnly;
            position.disabled = this.readOnly;
            width.disabled = this.readOnly;
            imageFit.disabled = this.readOnly;
            verticalAlignment.disabled = this.readOnly;
            border.disabled = this.readOnly;
            border.checked = this.data.showBorder === true;
            heading.readOnly = this.readOnly;
            spacingTop.disabled = this.readOnly;
            spacingBottom.disabled = this.readOnly;
            alt.readOnly = this.readOnly;
            content.setAttribute('aria-label', 'Text neben Bild');
            content.cmsCustomPasteHandler = this.handleContentPaste.bind(this, content);

            preview.appendChild(headingPreview);
            media.appendChild(image);
            preview.appendChild(media);
            preview.appendChild(content);

            controls.appendChild(label);
            controls.appendChild(this.createSetting('Überschrift', heading));
            controls.appendChild(this.createSetting('Upload', upload));
            controls.appendChild(libraryButton);
            controls.appendChild(this.createSetting('Position', position));
            controls.appendChild(this.createSetting('Breite', width));
            controls.appendChild(this.createSetting('Skalierung', imageFit));
            controls.appendChild(this.createSetting('Vertikal', verticalAlignment));
            controls.appendChild(this.createCheckboxSetting('Dezenter Rahmen', border));
            controls.appendChild(this.createSetting('Abstand oben', spacingTop));
            controls.appendChild(this.createSetting('Abstand unten', spacingBottom));
            controls.appendChild(this.createSetting('Alt', alt));

            [url, alt, position, width, imageFit, verticalAlignment, border, heading, spacingTop, spacingBottom].forEach(function (element) {
                var handleChange = function () {
                    updatePreview();
                    notifyToolChanged(wrapper);
                };

                element.addEventListener('input', handleChange);
                element.addEventListener('change', handleChange);
            });
            upload.addEventListener('change', this.uploadSelectedFile.bind(this, url, status));
            libraryButton.addEventListener('click', this.openImageLibraryPicker.bind(this));
            content.addEventListener('input', function () {
                notifyToolChanged(wrapper);
            });

            if (!this.readOnly) {
                bindEditableLineBreakEnterBehavior(content);
            }

            wrapper.appendChild(url);
            wrapper.appendChild(preview);
            wrapper.appendChild(controls);
            wrapper.appendChild(status);
            this.nodes = { wrapper: wrapper, preview: preview, headingPreview: headingPreview, media: media, image: image, content: content, url: url, libraryButton: libraryButton, position: position, width: width, imageFit: imageFit, verticalAlignment: verticalAlignment, border: border, heading: heading, spacingTop: spacingTop, spacingBottom: spacingBottom, alt: alt, status: status };
            updatePreview();

            return wrapper;
        }
        createSelect(options, value) {
            var select = document.createElement('select');
            select.className = 'form-select form-select-sm';
            options.forEach(function (item) {
                var option = document.createElement('option');
                option.value = item[0];
                option.textContent = item[1];
                select.appendChild(option);
            });
            select.value = value;
            return select;
        }
        getSpacingOptions() {
            return [
                ['0', '0 px'],
                ['5', '5 px'],
                ['10', '10 px'],
                ['15', '15 px'],
                ['20', '20 px'],
                ['30', '30 px'],
                ['40', '40 px'],
                ['60', '60 px'],
                ['80', '80 px'],
                ['100', '100 px']
            ];
        }
        getImageFitOptions() {
            return [
                ['cover', 'Ausschnitt'],
                ['contain', 'Anpassen'],
                ['fill', 'Strecken'],
                ['scale-down', 'Verkleinern'],
                ['none', 'Original']
            ];
        }
        createSetting(labelText, control) {
            var label = createElement('label', 'cms-editorjs-media-text-options__field');
            var text = createElement('span', '', labelText);
            label.appendChild(text);
            label.appendChild(control);
            return label;
        }
        createCheckboxSetting(labelText, control) {
            var label = createElement('label', 'cms-editorjs-media-text-options__field cms-editorjs-media-text-options__field--checkbox');
            var text = createElement('span', '', labelText);
            label.appendChild(control);
            label.appendChild(text);
            return label;
        }
        updatePreview(wrapper, preview, headingPreview, image, urlInput, altInput, positionInput, widthInput, imageFitInput, verticalAlignmentInput, borderInput, headingInput, spacingTopInput, spacingBottomInput) {
            var url = String(urlInput.value || '').trim();
            var position = ['left', 'right'].indexOf(positionInput.value) !== -1 ? positionInput.value : 'left';
            var width = ['33', '40', '50'].indexOf(widthInput.value) !== -1 ? widthInput.value : '40';
            var imageFit = normalizeImageFitValue(imageFitInput.value || 'cover', 'cover');
            var verticalAlignment = normalizeMediaTextVerticalAlignment(verticalAlignmentInput && verticalAlignmentInput.value ? verticalAlignmentInput.value : 'top');
            var showBorder = !!(borderInput && borderInput.checked);
            var heading = String(headingInput && headingInput.value ? headingInput.value : '').trim();
            var spacingTop = normalizeMediaTextSpacingValue(spacingTopInput && spacingTopInput.value ? spacingTopInput.value : '10');
            var spacingBottom = normalizeMediaTextSpacingValue(spacingBottomInput && spacingBottomInput.value ? spacingBottomInput.value : '10');

            preview.dataset.imagePosition = position;
            preview.dataset.imageWidth = width;
            preview.dataset.imageFit = imageFit;
            preview.dataset.verticalAlignment = verticalAlignment;
            preview.classList.toggle('cms-editorjs-media-text-preview--image-right', position === 'right');
            preview.classList.toggle('cms-editorjs-media-text-preview--valign-top', verticalAlignment === 'top');
            preview.classList.toggle('cms-editorjs-media-text-preview--valign-center', verticalAlignment === 'center');
            preview.classList.toggle('cms-editorjs-media-text-preview--valign-bottom', verticalAlignment === 'bottom');
            preview.classList.toggle('cms-editorjs-media-text-preview--bordered', showBorder);
            preview.classList.toggle('has-heading', heading !== '');
            preview.style.setProperty('--cms-media-text-image-width', width + '%');
            preview.style.setProperty('--cms-media-text-object-fit', imageFit);
            preview.style.setProperty('--cms-media-text-align-items', getMediaTextAlignItemsValue(verticalAlignment));
            preview.style.setProperty('--cms-media-text-spacing-top', spacingTop + 'px');
            preview.style.setProperty('--cms-media-text-spacing-bottom', spacingBottom + 'px');
            image.style.objectFit = imageFit;

            headingPreview.textContent = heading;
            headingPreview.hidden = heading === '';

            if (url !== '') {
                setPreviewImageSource(image, url, String(altInput.value || ''));
                preview.classList.add('has-image');
                wrapper.classList.remove('is-empty');
            } else {
                setPreviewImageSource(image, '', '');
                preview.classList.remove('has-image');
                wrapper.classList.add('is-empty');
            }
        }
        uploadSelectedFile(urlInput, status, event) {
            var file = event.target.files && event.target.files[0] ? event.target.files[0] : null;
            var validationError = validateEditorImageFile(file);

            if (validationError !== '') {
                status.textContent = validationError;
                return;
            }

            if (!file) {
                return;
            }

            status.textContent = 'Upload läuft ...';
            this.uploadImageFile(file).then(function (filePayload) {
                urlInput.value = filePayload.url || '';
                urlInput.dispatchEvent(new Event('input', { bubbles: true }));
                status.textContent = 'Upload abgeschlossen.';
            }).catch(function (error) {
                status.textContent = error && error.message ? error.message : 'Upload fehlgeschlagen.';
                logWarn('MediaText image upload failed.', error);
            });
        }
        uploadImageFile(file) {
            var endpoint = this.config.uploadUrl || '';
            var validationError = validateEditorImageFile(file);
            var body;

            if (validationError !== '') {
                return Promise.reject(new Error(validationError));
            }

            if (this.config.uploader && typeof this.config.uploader.uploadByFile === 'function') {
                return this.config.uploader.uploadByFile(file).then(function (payload) {
                    return normalizeImageResponse(payload).file;
                });
            }

            if (!file || !endpoint || typeof fetch !== 'function') {
                return Promise.reject(new Error('Upload-Endpunkt ist nicht verfügbar.'));
            }

            body = new FormData();
            body.append('action', 'upload_image');
            body.append('image', file);
            body.append('csrf_token', this.config.csrfToken || '');

            return fetch(endpoint, {
                method: 'POST',
                body: body,
                credentials: 'same-origin',
                headers: this.config.csrfToken ? { 'X-CSRF-Token': this.config.csrfToken } : {}
            }).then(function (response) {
                return parseEditorJsonResponse(response, 'Bild konnte nicht hochgeladen werden.');
            }).then(function (payload) {
                return normalizeImageResponse(payload).file;
            });
        }
        openImageLibraryPicker(event) {
            var self = this;

            if (event) {
                event.preventDefault();
            }
            if (this.readOnly) {
                return;
            }

            createImageLibraryPicker(this.config, {
                multiple: false,
                title: 'Bild für Bild-Text-Block auswählen',
                selectedUrls: this.nodes.url && this.nodes.url.value ? [this.nodes.url.value] : [],
                onSelect: function (items) {
                    var item = Array.isArray(items) && items[0] ? items[0] : null;
                    var file = item && item.file ? item.file : {};

                    self.setImageUrl(file.url || '', item ? (item.caption || '') : '');
                }
            });
        }
        setImageUrl(url, alt) {
            var cleanUrl = sanitizeEditableUrl(url);

            if (cleanUrl === '') {
                return;
            }

            if (this.nodes.alt && typeof alt === 'string') {
                this.nodes.alt.value = alt;
                this.nodes.alt.dispatchEvent(new Event('input', { bubbles: true }));
            }
            if (this.nodes.url) {
                this.nodes.url.value = cleanUrl;
                this.nodes.url.dispatchEvent(new Event('input', { bubbles: true }));
            }
            if (this.nodes.status) {
                this.nodes.status.textContent = 'Bild eingefügt.';
            }
            notifyToolChanged(this.nodes.wrapper);
        }
        extractMediaTextPastePayload(clipboardData, html, text) {
            var template;
            var image;
            var imageUrl = '';
            var rawImageUrl;
            var cleanImageUrl;
            var alt = '';
            var contentHtml = '';
            var imageFile = getFirstClipboardImageFile(clipboardData);

            if (html !== '') {
                template = document.createElement('template');
                template.innerHTML = String(html || '');
                image = template.content.querySelector ? template.content.querySelector('img[src], img[data-src]') : null;

                if (image && image.getAttribute) {
                    rawImageUrl = image.getAttribute('src') || image.getAttribute('data-src') || '';
                    alt = String(image.getAttribute('alt') || image.getAttribute('title') || '').trim();
                    if (!imageFile && /^data:image\//i.test(String(rawImageUrl || ''))) {
                        imageFile = dataImageUrlToFile(rawImageUrl, 'clipboard-media-text');
                    }
                    cleanImageUrl = sanitizeEditableUrl(rawImageUrl);
                    imageUrl = isImageUrlCandidate(cleanImageUrl) ? cleanImageUrl : '';
                }

                Array.prototype.slice.call(template.content.querySelectorAll ? template.content.querySelectorAll('img, source') : []).forEach(function (node) {
                    if (node.parentNode) {
                        node.parentNode.removeChild(node);
                    }
                });
                Array.prototype.slice.call(template.content.querySelectorAll ? template.content.querySelectorAll('figure, picture') : []).forEach(function (node) {
                    if (stripTags(node.innerHTML || '') === '' && node.parentNode) {
                        node.parentNode.removeChild(node);
                    }
                });

                contentHtml = sanitizeEditableHtml(template.innerHTML, true);
            } else if (text !== '' && !isImageUrlCandidate(text)) {
                contentHtml = escapeHtml(text).replace(/\r\n|\r|\n/g, '<br>');
            }

            return {
                imageFile: imageFile,
                imageUrl: imageUrl,
                alt: alt,
                html: contentHtml
            };
        }
        handleContentPaste(editable, event, context) {
            var payload = this.extractMediaTextPastePayload(
                context && context.clipboardData ? context.clipboardData : null,
                context && context.html ? context.html : '',
                context && context.text ? context.text : ''
            );
            var hasImage = !!payload.imageFile || payload.imageUrl !== '';
            var hasText = payload.html !== '' && stripTags(payload.html) !== '';
            var self = this;

            if (this.readOnly || (!hasImage && !hasText)) {
                return false;
            }

            event.preventDefault();
            event.stopPropagation();

            if (hasText) {
                insertHtmlAtSelection(editable, payload.html);
                editable.dispatchEvent(new Event('input', { bubbles: true }));
                notifyToolChanged(this.nodes.wrapper);
            }

            if (!hasImage) {
                return true;
            }

            if (payload.imageFile) {
                if (this.nodes.status) {
                    this.nodes.status.textContent = 'Bild aus Zwischenablage wird hochgeladen ...';
                }
                this.uploadImageFile(payload.imageFile).then(function (filePayload) {
                    self.setImageUrl(filePayload.url || '', payload.alt || '');
                }).catch(function (error) {
                    if (self.nodes.status) {
                        self.nodes.status.textContent = error && error.message ? error.message : 'Clipboard-Upload fehlgeschlagen.';
                    }
                    logWarn('Pasted MediaText clipboard image upload failed.', error);
                });
                return true;
            }

            this.setImageUrl(payload.imageUrl, payload.alt || '');
            return true;
        }
        onPaste(event) {
            var detail = event && event.detail ? event.detail : {};
            var pastedNode;
            var file;
            var self = this;

            if (this.readOnly) {
                return;
            }

            if (event.type === 'tag') {
                pastedNode = detail.data;
                this.setImageUrl(pastedNode && pastedNode.getAttribute ? pastedNode.getAttribute('src') || '' : '', pastedNode && pastedNode.getAttribute ? pastedNode.getAttribute('alt') || '' : '');
                return;
            }

            if (event.type === 'pattern') {
                this.setImageUrl(detail.data || '', '');
                return;
            }

            if (event.type === 'file') {
                file = detail.file || null;
                if (this.nodes.status) {
                    this.nodes.status.textContent = 'Bild wird hochgeladen ...';
                }
                this.uploadImageFile(file).then(function (filePayload) {
                    self.setImageUrl(filePayload.url || '', '');
                }).catch(function (error) {
                    if (self.nodes.status) {
                        self.nodes.status.textContent = error && error.message ? error.message : 'Upload fehlgeschlagen.';
                    }
                    logWarn('Pasted MediaText image upload failed.', error);
                });
            }
        }
        save() {
            return normalizeMediaTextData({
                file: { url: this.nodes.url ? this.nodes.url.value.trim() : '' },
                alt: this.nodes.alt ? this.nodes.alt.value.trim() : '',
                heading: this.nodes.heading ? this.nodes.heading.value.trim() : '',
                text: this.nodes.content ? this.nodes.content.innerHTML.trim() : '',
                imagePosition: this.nodes.position ? this.nodes.position.value : 'left',
                imageWidth: this.nodes.width ? this.nodes.width.value : '40',
                imageFit: this.nodes.imageFit ? this.nodes.imageFit.value : 'cover',
                verticalAlignment: this.nodes.verticalAlignment ? this.nodes.verticalAlignment.value : 'top',
                showBorder: this.nodes.border ? this.nodes.border.checked : false,
                spacingTop: this.nodes.spacingTop ? this.nodes.spacingTop.value : '10',
                spacingBottom: this.nodes.spacingBottom ? this.nodes.spacingBottom.value : '10'
            });
        }
    }

    window.CmsMediaTextTool = MediaTextTool;

    class ButtonTool {
        constructor(options) {
            options = options || {};
            this.data = normalizeButtonData(options.data || {});
            this.api = options.api || null;
            this.readOnly = !!options.readOnly;
            this.nodes = {};
        }
        static get toolbox() {
            return { title: 'Button', icon: '⬜' };
        }
        static get sanitize() {
            return { url: true, text: true, align: true, color: true, size: true };
        }
        static get isReadOnlySupported() {
            return true;
        }
        static get conversionConfig() {
            return {
                export: function (data) {
                    var normalized = normalizeButtonData(data || {});
                    return normalized.text || '';
                },
                import: function (text) {
                    return normalizeButtonData({ text: stripTags(text || '') });
                }
            };
        }
        render() {
            var wrapper = createElement('div', 'cms-editorjs-tool cms-editorjs-tool--button');
            var preview = createElement('div', 'cms-editorjs-button-preview');
            var link = createElement('a', 'cms-editorjs-button-preview__link', this.data.text || 'Button-Text');
            var controls = createElement('div', 'cms-editorjs-floating-options cms-editorjs-button-options');
            var label = createElement('span', 'cms-editorjs-floating-options__label', 'Button');
            var text = createInput('text', 'form-control form-control-sm', this.data.text || '', 'Button-Text');
            var url = createInput('url', 'form-control form-control-sm', this.data.url || '', 'https://…');
            var align = this.createSelect([
                ['left', 'Linksbündig'],
                ['center', 'Mittig'],
                ['right', 'Rechtsbündig']
            ], this.data.align || 'left');
            var color = this.createSelect([
                ['primary', 'Primär'],
                ['secondary', 'Sekundär'],
                ['info', 'Info'],
                ['success', 'Erfolg'],
                ['warning', 'Warnung'],
                ['danger', 'Kritisch'],
                ['light', 'Hell'],
                ['dark', 'Dunkel']
            ], this.data.color || 'primary');
            var size = this.createSelect([
                ['small', 'Klein'],
                ['medium', 'Mittel'],
                ['large', 'Groß']
            ], this.data.size || 'medium');
            var updatePreview = this.updatePreview.bind(this, wrapper, preview, link, text, url, align, color, size);

            controls.dataset.cmsEditorUi = 'true';
            controls.dataset.mutationFree = 'true';
            link.setAttribute('href', 'javascript:void(0)');
            link.setAttribute('tabindex', '-1');
            text.readOnly = this.readOnly;
            url.readOnly = this.readOnly;
            align.disabled = this.readOnly;
            color.disabled = this.readOnly;
            size.disabled = this.readOnly;

            preview.appendChild(link);

            controls.appendChild(label);
            controls.appendChild(this.createSetting('Text', text));
            controls.appendChild(this.createSetting('Link', url));
            controls.appendChild(this.createSetting('Ausrichtung', align));
            controls.appendChild(this.createSetting('Farbe', color));
            controls.appendChild(this.createSetting('Größe', size));

            [text, url, align, color, size].forEach(function (element) {
                var handleChange = function () {
                    updatePreview();
                    notifyToolChanged(wrapper);
                };

                element.addEventListener('input', handleChange);
                element.addEventListener('change', handleChange);
            });

            wrapper.appendChild(preview);
            wrapper.appendChild(controls);
            this.nodes = { wrapper: wrapper, preview: preview, link: link, text: text, url: url, align: align, color: color, size: size };
            updatePreview();

            return wrapper;
        }
        createSelect(options, value) {
            var select = document.createElement('select');
            select.className = 'form-select form-select-sm';
            options.forEach(function (item) {
                var option = document.createElement('option');
                option.value = item[0];
                option.textContent = item[1];
                select.appendChild(option);
            });
            select.value = value;
            return select;
        }
        createSetting(labelText, control) {
            var label = createElement('label', 'cms-editorjs-button-options__field');
            var text = createElement('span', '', labelText);
            label.appendChild(text);
            label.appendChild(control);
            return label;
        }
        updatePreview(wrapper, preview, link, textInput, urlInput, alignInput, colorInput, sizeInput) {
            var text = String(textInput.value || '').trim();
            var url = sanitizeEditableUrl(urlInput.value || '');
            var align = ['left', 'center', 'right'].indexOf(alignInput.value) !== -1 ? alignInput.value : 'left';
            var color = ['primary', 'secondary', 'info', 'success', 'warning', 'danger', 'light', 'dark'].indexOf(colorInput.value) !== -1 ? colorInput.value : 'primary';
            var size = ['small', 'medium', 'large'].indexOf(sizeInput.value) !== -1 ? sizeInput.value : 'medium';

            link.textContent = text !== '' ? text : 'Button-Text';
            preview.className = 'cms-editorjs-button-preview cms-editorjs-button-preview--align-' + align;
            link.className = 'cms-editorjs-button-preview__link cms-editorjs-button-preview__link--' + color + ' cms-editorjs-button-preview__link--' + size;
            wrapper.classList.toggle('is-empty', text === '' || url === '');
        }
        save() {
            return normalizeButtonData({
                url: this.nodes.url ? this.nodes.url.value.trim() : '',
                text: this.nodes.text ? this.nodes.text.value.trim() : '',
                align: this.nodes.align ? this.nodes.align.value : 'left',
                color: this.nodes.color ? this.nodes.color.value : 'primary',
                size: this.nodes.size ? this.nodes.size.value : 'medium'
            });
        }
    }

    window.CmsButtonTool = ButtonTool;

    class QuoteTool {
        constructor(options) {
            options = options || {};
            this.data = options.data || {};
            this.api = options.api || null;
            this.readOnly = !!options.readOnly;
            this.nodes = {};
        }
        static get toolbox() {
            return { title: 'Zitat', icon: '“”' };
        }
        static get sanitize() {
            return {
                text: getInlineSanitizeConfig(),
                caption: false,
                alignment: false
            };
        }
        static get isReadOnlySupported() {
            return true;
        }
        static get conversionConfig() {
            return {
                export: function (data) { return stripTags(data && data.text ? data.text : ''); },
                import: function (text) { return { text: sanitizeEditableHtml(text || ''), caption: '', alignment: 'left' }; }
            };
        }
        render() {
            var wrapper = createElement('div', 'cms-editorjs-tool cms-editorjs-tool--quote');
            var text = createEditable('form-control cms-editorjs-editable mb-2', this.data.text || '', 'Zitat', this.api, this.readOnly);
            var caption = createInput('text', 'form-control', this.data.caption || '', 'Quelle');
            caption.readOnly = this.readOnly;
            wrapper.appendChild(text);
            wrapper.appendChild(caption);
            this.nodes = { text: text, caption: caption };
            return wrapper;
        }
        save() {
            return {
                text: this.nodes.text ? this.nodes.text.innerHTML.trim() : '',
                caption: this.nodes.caption ? this.nodes.caption.value.trim() : '',
                alignment: 'left'
            };
        }
    }

    window.CmsQuoteTool = QuoteTool;

    class WarningTool {
        constructor(options) {
            options = options || {};
            this.data = normalizeWarningData(options.data || {});
            this.api = options.api || null;
            this.readOnly = !!options.readOnly;
            this.nodes = {};
        }
        static get toolbox() {
            return { title: 'Hinweisbox', icon: '!' };
        }
        static get sanitize() {
            return {
                variant: false,
                title: getInlineSanitizeConfig(),
                message: getInlineSanitizeConfig()
            };
        }
        static get isReadOnlySupported() {
            return true;
        }
        static get conversionConfig() {
            return {
                export: function (data) {
                    var normalized = normalizeWarningData(data || {});
                    return [stripTags(normalized.title || ''), stripTags(normalized.message || '')].filter(Boolean).join('\n');
                },
                import: function (text) {
                    return normalizeWarningData({ title: 'Hinweis', message: sanitizeEditableHtml(text || ''), variant: 'info' });
                }
            };
        }
        render() {
            var wrapper = createElement('div', 'cms-editorjs-tool cms-editorjs-tool--warning');
            var title = createEditable('cms-editorjs-editable cms-editorjs-warning__title', this.data.title || '', 'Titel der Box', this.api, this.readOnly);
            var message = createEditable('cms-editorjs-editable cms-editorjs-warning__message', this.data.message || '', 'Hinweistext schreiben ...', this.api, this.readOnly);
            var options = createElement('div', 'cms-editorjs-floating-options cms-editorjs-warning-options');
            var label = createElement('span', 'cms-editorjs-floating-options__label', 'Box');
            var variant = document.createElement('select');
            var self = this;

            options.dataset.cmsEditorUi = 'true';
            options.dataset.mutationFree = 'true';
            variant.className = 'form-select form-select-sm cms-editorjs-warning__variant';
            variant.disabled = this.readOnly;
            [
                ['info', 'Info'],
                ['warning', 'Warnung'],
                ['success', 'Erfolg'],
                ['danger', 'Kritisch']
            ].forEach(function (item) {
                var option = document.createElement('option');
                option.value = item[0];
                option.textContent = item[1];
                variant.appendChild(option);
            });
            variant.value = this.data.variant || 'info';
            variant.addEventListener('change', function () {
                self.updateVariant(wrapper, variant.value);
                notifyToolChanged(wrapper);
            });

            options.appendChild(label);
            options.appendChild(variant);
            wrapper.appendChild(title);
            wrapper.appendChild(message);
            wrapper.appendChild(options);
            this.nodes = { wrapper: wrapper, title: title, message: message, variant: variant };

            if (!this.readOnly) {
                bindEditableFocusNextOnEnter(title, message);
                bindEditableLineBreakEnterBehavior(message);
            }
            this.updateVariant(wrapper, variant.value);
            return wrapper;
        }
        updateVariant(wrapper, variant) {
            var safeVariant = ['info', 'warning', 'success', 'danger'].indexOf(variant) !== -1 ? variant : 'info';

            if (!wrapper) {
                return;
            }

            wrapper.dataset.variant = safeVariant;
            wrapper.classList.remove('cms-editorjs-tool--warning-info', 'cms-editorjs-tool--warning-warning', 'cms-editorjs-tool--warning-success', 'cms-editorjs-tool--warning-danger');
            wrapper.classList.add('cms-editorjs-tool--warning-' + safeVariant);
        }
        save() {
            return normalizeWarningData({
                variant: this.nodes.variant ? this.nodes.variant.value : 'info',
                title: this.nodes.title ? this.nodes.title.innerHTML.trim() : '',
                message: this.nodes.message ? this.nodes.message.innerHTML.trim() : ''
            });
        }
    }

    window.CmsWarningTool = WarningTool;

    class CodeTool {
        constructor(options) {
            options = options || {};
            this.data = options.data || {};
            this.readOnly = !!options.readOnly;
            this.nodes = {};
        }
        static get toolbox() {
            return { title: 'Code', icon: '&lt;/&gt;' };
        }
        static get sanitize() {
            return {
                code: true
            };
        }
        static get enableLineBreaks() {
            return true;
        }
        static get isReadOnlySupported() {
            return true;
        }
        static get conversionConfig() {
            return {
                export: function (data) { return String(data && data.code ? data.code : ''); },
                import: function (text) { return { code: stripTags(text || '') }; }
            };
        }
        render() {
            var textarea = document.createElement('textarea');
            textarea.className = 'form-control font-monospace cms-editorjs-code-textarea';
            textarea.rows = 1;
            textarea.value = this.data.code || '';
            textarea.readOnly = this.readOnly;
            this.nodes.textarea = textarea;
            autoresizeTextarea(textarea, 1);
            return textarea;
        }
        save() {
            return { code: this.nodes.textarea ? this.nodes.textarea.value : '' };
        }
    }

    window.CmsCodeTool = CodeTool;

    class SpacerTool {
        constructor(options) {
            options = options || {};
            this.data = normalizeSpacerData(options && options.data ? options.data : {});
            this.readOnly = !!options.readOnly;
            this.nodes = {};
        }
        static get toolbox() {
            return { title: 'Abstand', icon: '↕' };
        }
        static get sanitize() {
            return {
                height: false,
                preset: false
            };
        }
        static get isReadOnlySupported() {
            return true;
        }
        render() {
            var wrapper = createElement('div', 'editorjs-spacer-tool');
            var badge = createElement('span', 'editorjs-spacer-tool__badge');
            var controls = createElement('div', 'editorjs-spacer-tool__controls');
            var label = createElement('span', 'editorjs-spacer-tool__control-label', 'Größe');
            var select = document.createElement('select');
            var preview = createElement('div', 'editorjs-spacer-tool__preview');

            wrapper.setAttribute('role', 'group');
            wrapper.setAttribute('aria-label', 'Abstand');
            wrapper.setAttribute('title', 'Abstand – Höhe per Hover-Auswahl ändern');

            select.className = 'form-select form-select-sm editorjs-spacer-tool__select';
            select.setAttribute('aria-label', 'Abstandshöhe wählen');
            select.disabled = this.readOnly;
            [10, 15, 25, 40, 60, 75, 100, 150, 200].forEach(function (height) {
                var option = document.createElement('option');
                option.value = String(height);
                option.textContent = height + ' px';
                select.appendChild(option);
            });
            select.addEventListener('input', () => this.setHeight(select.value));
            select.addEventListener('change', () => this.setHeight(select.value));

            controls.appendChild(label);
            controls.appendChild(select);
            wrapper.appendChild(badge);
            wrapper.appendChild(controls);
            wrapper.appendChild(preview);

            this.nodes = { wrapper: wrapper, preview: preview, badge: badge, select: select };
            this.updatePreview();

            return wrapper;
        }
        setHeight(height) {
            this.data = normalizeSpacerData({ height: height });
            this.updatePreview();
            notifyToolChanged(this.nodes.wrapper);
        }
        updatePreview() {
            var height = parseInt(this.data && this.data.height ? this.data.height : 40, 10) || 40;

            if (this.nodes.wrapper) {
                this.nodes.wrapper.dataset.height = String(height);
            }
            if (this.nodes.preview) {
                this.nodes.preview.style.height = height + 'px';
            }
            if (this.nodes.badge) {
                this.nodes.badge.textContent = height + ' px';
            }
            if (this.nodes.select && this.nodes.select.value !== String(height)) {
                this.nodes.select.value = String(height);
            }
        }
        save() {
            return normalizeSpacerData(this.data);
        }
    }

    window.CmsSpacerTool = SpacerTool;

    class MarkerTool {
        constructor(options) {
            options = options || {};
            this.api = options.api || null;
            this.button = null;
            this.tag = 'MARK';
        }
        static get isInline() {
            return true;
        }
        static get title() {
            return 'Markieren';
        }
        static get sanitize() {
            return {
                mark: {}
            };
        }
        render() {
            this.button = document.createElement('button');
            this.button.type = 'button';
            this.button.className = 'ce-inline-tool cms-editorjs-marker-tool';
            this.button.textContent = 'Mark';
            this.button.setAttribute('aria-label', 'Text markieren');

            return this.button;
        }
        surround(range) {
            var selection = window.getSelection ? window.getSelection() : null;
            var marker;
            var closestMark;

            if (!range || range.collapsed) {
                return;
            }

            closestMark = this.findClosestMark(selection && selection.anchorNode ? selection.anchorNode : range.commonAncestorContainer);
            if (closestMark) {
                this.unwrap(closestMark);
                return;
            }

            marker = document.createElement(this.tag);
            marker.appendChild(range.extractContents());
            range.insertNode(marker);

            if (selection) {
                selection.removeAllRanges();
                range.selectNodeContents(marker);
                selection.addRange(range);
            }
        }
        checkState(selection) {
            var active = !!this.findClosestMark(selection && selection.anchorNode ? selection.anchorNode : null);

            if (this.button) {
                this.button.classList.toggle('ce-inline-tool--active', active);
            }

            return active;
        }
        findClosestMark(node) {
            var element = node && node.nodeType === 1 ? node : (node && node.parentElement ? node.parentElement : null);

            return element && typeof element.closest === 'function' ? element.closest('mark') : null;
        }
        unwrap(element) {
            if (!element || !element.parentNode) {
                return;
            }

            while (element.firstChild) {
                element.parentNode.insertBefore(element.firstChild, element);
            }
            element.parentNode.removeChild(element);
        }
    }

    window.CmsMarkerTool = MarkerTool;

    var QUOTE_DESIGNS = ['bar', 'card', 'minimal', 'mark'];
    var QUOTE_DESIGN_LABELS = {
        bar: 'Balken',
        card: 'Karte',
        minimal: 'Minimal',
        mark: 'Anführungszeichen'
    };
    var QUOTE_DESIGN_ICONS = {
        bar: '<svg width="14" height="14" viewBox="0 0 14 14" fill="none" xmlns="http://www.w3.org/2000/svg"><rect x="1" y="1" width="2" height="12" rx="1" fill="currentColor"/><rect x="5.5" y="2" width="7.5" height="1.6" rx=".5" fill="currentColor"/><rect x="5.5" y="6.2" width="7.5" height="1.6" rx=".5" fill="currentColor"/><rect x="5.5" y="10.4" width="5" height="1.6" rx=".5" fill="currentColor"/></svg>',
        card: '<svg width="14" height="14" viewBox="0 0 14 14" fill="none" xmlns="http://www.w3.org/2000/svg"><rect x="1" y="1" width="12" height="12" rx="2" stroke="currentColor" stroke-width="1.4"/><rect x="3.2" y="4.2" width="7.6" height="1.3" rx=".4" fill="currentColor"/><rect x="3.2" y="7.2" width="5.5" height="1.3" rx=".4" fill="currentColor"/></svg>',
        minimal: '<svg width="14" height="14" viewBox="0 0 14 14" fill="none" xmlns="http://www.w3.org/2000/svg"><rect x="1" y="4.2" width="12" height="1.5" rx=".5" fill="currentColor"/><rect x="1" y="8.8" width="8" height="1.5" rx=".5" fill="currentColor"/></svg>',
        mark: '<svg width="14" height="14" viewBox="0 0 14 14" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M2 9V6.3C2 4.4 3.1 3 5 2.6v1.7c-1 .3-1.4.8-1.4 1.7H5V9H2Zm6.4 0V6.3c0-1.9 1.1-3.3 3-3.7v1.7c-1 .3-1.4.8-1.4 1.7h1.4V9H8.4Z" fill="currentColor"/></svg>'
    };

    function sanitizeQuoteDesign(value) {
        var design = String(value || 'bar');

        return QUOTE_DESIGNS.indexOf(design) !== -1 ? design : 'bar';
    }

    function applyQuoteDesignClass(wrapper, design) {
        if (!wrapper || !wrapper.classList) {
            return;
        }

        QUOTE_DESIGNS.forEach(function (name) {
            wrapper.classList.remove('cms-quote-preview--' + name);
        });
        wrapper.classList.add('cms-quote-preview--' + sanitizeQuoteDesign(design));
    }

    if (typeof window.Quote === 'function') {
        (function (BaseQuoteTool) {
            class CmsQuoteTool extends BaseQuoteTool {
                constructor(options) {
                    super(options);
                    var sourceData = options && options.data && typeof options.data === 'object' ? options.data : {};

                    this.data.design = sanitizeQuoteDesign(sourceData.design);
                }

                render() {
                    var wrapper = super.render();
                    var textEl = wrapper.querySelector('.' + this.css.text);
                    var captionEl = wrapper.querySelector('.' + this.css.caption);

                    this._cmsWrapperEl = wrapper;
                    applyQuoteDesignClass(wrapper, this.data.design);

                    // Stock-Quote-Tool implementiert kein onPaste/pasteConfig; EditorJS' generisches
                    // Paste-Routing fügt formatierten Zwischenablage-Inhalt dadurch nicht zuverlässig ein.
                    // Direkt an Text/Quelle die bewährte CMS-Paste-Behandlung binden (Capture-Phase,
                    // stoppt Propagation -> EditorJS-eigener Paste-Handler wird gar nicht erst erreicht).
                    if (textEl) {
                        bindEditablePasteBehavior(textEl, this.api);
                    }
                    if (captionEl) {
                        bindEditablePasteBehavior(captionEl, this.api);
                    }

                    return wrapper;
                }

                save(blockContent) {
                    var data = super.save(blockContent);
                    var normalized = normalizeQuoteData(data);

                    normalized.design = sanitizeQuoteDesign(this.data.design);

                    return normalized;
                }

                renderSettings() {
                    var baseSettings = super.renderSettings();
                    var self = this;
                    var designSettings = QUOTE_DESIGNS.map(function (design) {
                        return {
                            icon: QUOTE_DESIGN_ICONS[design],
                            label: self.api.i18n.t('Zitat-Design: ' + QUOTE_DESIGN_LABELS[design]),
                            onActivate: function () {
                                if (self._cmsWrapperEl && self.css) {
                                    var textEl = self._cmsWrapperEl.querySelector('.' + self.css.text);
                                    var captionEl = self._cmsWrapperEl.querySelector('.' + self.css.caption);

                                    if (textEl) {
                                        self.data.text = textEl.innerHTML;
                                    }
                                    if (captionEl) {
                                        self.data.caption = captionEl.innerHTML;
                                    }
                                }
                                self.data.design = design;
                                applyQuoteDesignClass(self._cmsWrapperEl, design);
                                self.block.dispatchChange();
                            },
                            isActive: self.data.design === design,
                            closeOnActivate: true
                        };
                    });

                    return baseSettings.concat(designSettings);
                }

                static get sanitize() {
                    return {
                        text: getInlineSanitizeConfig(),
                        caption: getInlineSanitizeConfig(),
                        alignment: false,
                        design: false
                    };
                }
            }

            window.CmsQuoteTool = CmsQuoteTool;
        })(window.Quote);
    }

    class CmsDelimiterTool {
        constructor(options) {
            options = options || {};
            this.data = normalizeDelimiterData(options.data || {});
            this.block = options.block || null;
            this.readOnly = !!options.readOnly;
            this.nodes = {};
        }

        static get toolbox() {
            return {
                title: 'Trennstrich',
                icon: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M4 12h16" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>'
            };
        }

        static get isReadOnlySupported() {
            return true;
        }

        static get sanitize() {
            return { style: false, lineWidth: false, lineThickness: false };
        }

        createSelect(options, value, label) {
            var select = document.createElement('select');

            select.className = 'form-select form-select-sm';
            select.setAttribute('aria-label', label);
            select.disabled = this.readOnly;
            options.forEach(function (optionData) {
                var option = document.createElement('option');
                option.value = String(optionData[0]);
                option.textContent = optionData[1];
                select.appendChild(option);
            });
            select.value = String(value);

            return select;
        }

        createSetting(label, control) {
            var wrapper = createElement('label', 'cms-editorjs-delimiter-settings__field');
            wrapper.appendChild(createElement('span', '', label));
            wrapper.appendChild(control);
            return wrapper;
        }

        render() {
            var wrapper = createElement('div', 'cms-editorjs-tool cms-editorjs-tool--delimiter');
            var preview = createElement('div', 'cms-editorjs-delimiter-preview');
            var settings = createElement('div', 'cms-editorjs-delimiter-settings');
            var style = this.createSelect([
                ['line', 'Linie'],
                ['dash', 'Striche'],
                ['star', 'Sterne']
            ], this.data.style || 'line', 'Trennstrich-Stil');
            var width = this.createSelect([
                [8, '8 %'],
                [15, '15 %'],
                [25, '25 %'],
                [35, '35 %'],
                [50, '50 %'],
                [60, '60 %'],
                [100, '100 %']
            ], this.data.lineWidth || 35, 'Linienbreite');
            var thickness = this.createSelect([
                [1, '1 px'], [2, '2 px'], [3, '3 px'],
                [4, '4 px'], [5, '5 px'], [6, '6 px']
            ], this.data.lineThickness || 2, 'Liniendicke');
            var self = this;

            settings.appendChild(this.createSetting('Stil', style));
            settings.appendChild(this.createSetting('Breite', width));
            settings.appendChild(this.createSetting('Dicke', thickness));
            wrapper.appendChild(preview);
            if (!this.readOnly) {
                wrapper.appendChild(settings);
            }

            this.nodes = {
                wrapper: wrapper,
                preview: preview,
                style: style,
                width: width,
                thickness: thickness,
                settings: settings
            };

            [style, width, thickness].forEach(function (control) {
                var handleChange = function () {
                    self.updatePreview();
                    if (self.block && typeof self.block.dispatchChange === 'function') {
                        self.block.dispatchChange();
                    }
                    notifyToolChanged(wrapper);
                };
                control.addEventListener('input', handleChange);
                control.addEventListener('change', handleChange);
            });

            this.updatePreview();
            return wrapper;
        }

        updatePreview() {
            var data = this.getCurrentData();
            var preview = this.nodes.preview;
            var visual;

            if (!preview) {
                return;
            }

            clearElement(preview);
            preview.className = 'cms-editorjs-delimiter-preview cms-editorjs-delimiter-preview--' + data.style;
            preview.dataset.lineWidth = String(data.lineWidth || 35);
            preview.dataset.lineThickness = String(data.lineThickness || 2);

            if (data.style === 'line') {
                visual = document.createElement('hr');
                visual.style.width = data.lineWidth + '%';
                visual.style.borderTopWidth = data.lineThickness + 'px';
            } else {
                visual = createElement('span', '', data.style === 'dash' ? '———' : '***');
            }
            preview.appendChild(visual);
        }

        getCurrentData() {
            return normalizeDelimiterData({
                style: this.nodes.style ? this.nodes.style.value : this.data.style,
                lineWidth: this.nodes.width ? this.nodes.width.value : this.data.lineWidth,
                lineThickness: this.nodes.thickness ? this.nodes.thickness.value : this.data.lineThickness
            });
        }

        save() {
            this.data = this.getCurrentData();
            return this.data;
        }
    }

    window.CmsDelimiterTool = CmsDelimiterTool;

    class CmsBlockClipboardTune {
        constructor(options) {
            options = options || {};
            this.api = options.api || null;
            this.block = options.block || null;
            this.readOnly = !!options.readOnly;
        }

        static get isTune() {
            return true;
        }

        render() {
            var self = this;

            return {
                icon: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><rect x="8" y="8" width="11" height="11" rx="2" stroke="currentColor" stroke-width="2"/><path d="M16 8V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h2" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>',
                label: 'Block kopieren',
                closeOnActivate: true,
                onActivate: function () {
                    return self.copyBlock();
                }
            };
        }

        copyBlock() {
            var self = this;

            if (this.readOnly || !this.block || typeof this.block.save !== 'function') {
                showCmsEditorNotice(this.api, 'Dieser Block kann nicht kopiert werden.', 'error');
                return Promise.resolve(false);
            }

            return Promise.resolve(this.block.save()).then(function (saved) {
                var block = saved && typeof saved === 'object' ? saved : {};

                block.type = block.type || block.tool || self.block.name || '';
                return writeCmsBlocksToClipboard([block]);
            }).then(function (copied) {
                if (!copied) {
                    throw new Error('Zwischenablage ist nicht verfügbar.');
                }
                showCmsEditorNotice(self.api, 'Block kopiert. Er kann in einem anderen EditorJS-Bereich eingefügt werden.', 'success');
                return true;
            }).catch(function (error) {
                showCmsEditorNotice(self.api, error && error.message ? error.message : 'Block konnte nicht kopiert werden.', 'error');
                return false;
            });
        }

        save() {
            return {};
        }
    }

    window.CmsBlockClipboardTune = CmsBlockClipboardTune;

    function resolveToolClass(toolName) {
        var globalNames = TOOL_GLOBALS[toolName];
        var toolClass = null;

        if (!Array.isArray(globalNames)) {
            globalNames = globalNames ? [globalNames] : [];
        }

        globalNames.some(function (globalName) {
            if (typeof window[globalName] === 'function') {
                toolClass = window[globalName];
                return true;
            }

            return false;
        });

        if (typeof toolClass !== 'function') {
            throw new Error('EditorJS tool missing: ' + toolName + ' (' + String(globalNames.join('|') || 'unknown') + ')');
        }

        return toolClass;
    }

    function tryResolveToolClass(toolName) {
        try {
            return resolveToolClass(toolName);
        } catch (error) {
            logWarn('Optional EditorJS tool skipped: ' + toolName, error);
            return null;
        }
    }

    function tryResolvePluginClass(pluginName) {
        var globalNames = PLUGIN_GLOBALS[pluginName];
        var pluginClass = null;

        if (!Array.isArray(globalNames)) {
            globalNames = globalNames ? [globalNames] : [];
        }

        globalNames.some(function (globalName) {
            if (typeof window[globalName] === 'function') {
                pluginClass = window[globalName];
                return true;
            }

            return false;
        });

        if (typeof pluginClass !== 'function') {
            logWarn('Optional EditorJS plugin skipped: ' + pluginName, { globals: globalNames });
            return null;
        }

        return pluginClass;
    }

    function addTool(tools, toolName, definition, required) {
        var toolClass = required ? resolveToolClass(toolName) : tryResolveToolClass(toolName);

        if (!toolClass) {
            return false;
        }

        tools[toolName] = Object.assign({}, definition || {}, { class: toolClass });
        return true;
    }

    function getAvailableBlockTunes(tools) {
        return TUNE_TOOL_NAMES.filter(function (tuneName) {
            return !!tools[tuneName];
        });
    }

    function withBlockTunes(definition, tunes) {
        var resolvedDefinition = Object.assign({}, definition || {});

        if (Array.isArray(tunes) && tunes.length > 0) {
            resolvedDefinition.tunes = tunes.slice();
        }

        return resolvedDefinition;
    }

    function buildMediaUrl(uploadUrl, action) {
        var baseUrl = uploadUrl || '/api/media';
        var separator = String(baseUrl).indexOf('?') === -1 ? '?' : '&';

        return String(baseUrl) + separator + 'action=' + encodeURIComponent(action);
    }

    function resolveUploadContext(getUploadContext) {
        var context;

        if (typeof getUploadContext !== 'function') {
            return {};
        }

        try {
            context = getUploadContext();
        } catch (error) {
            logWarn('Upload context could not be resolved.', error);
            return {};
        }

        return context && typeof context === 'object' ? context : {};
    }

    function normalizeUploadContextKeys(context) {
        var normalized = {};

        Object.keys(context || {}).forEach(function (key) {
            var value = context[key];
            var normalizedKey;

            if (value === null || typeof value === 'undefined' || value === false || value === '') {
                return;
            }

            normalizedKey = key.replace(/[A-Z]/g, function (match) {
                return '_' + match.toLowerCase();
            });
            normalized[normalizedKey] = value === true ? '1' : String(value);
        });

        return normalized;
    }

    function appendUploadContext(formData, getUploadContext) {
        var context = normalizeUploadContextKeys(resolveUploadContext(getUploadContext));

        Object.keys(context).forEach(function (key) {
            formData.append(key, context[key]);
        });
    }

    function buildUploadJsonPayload(basePayload, getUploadContext) {
        return Object.assign({}, normalizeUploadContextKeys(resolveUploadContext(getUploadContext)), basePayload || {});
    }

    function normalizeImageResponse(payload) {
        var response = payload && typeof payload === 'object' ? payload : {};
        var file = response.file && typeof response.file === 'object' ? response.file : {};
        var url = String(file.url || response.url || '');

        if (!response.success || url === '') {
            throw new Error(String(response.message || 'Bild konnte nicht verarbeitet werden.'));
        }

        return {
            success: 1,
            file: Object.assign({}, file, { url: url })
        };
    }

    function parseEditorJsonResponse(response, fallbackMessage) {
        return response.text().then(function (text) {
            var payload = {};

            if (String(text || '').trim() !== '') {
                try {
                    payload = JSON.parse(text);
                } catch (_error) {
                    throw new Error(fallbackMessage || 'EditorJS-Serverantwort war kein gültiges JSON.');
                }
            }

            if (!response.ok) {
                throw new Error(String(payload && payload.message ? payload.message : (fallbackMessage || 'EditorJS-Anfrage fehlgeschlagen.')));
            }

            return payload;
        });
    }

    function buildImageUploader(uploadUrl, csrfToken, getUploadContext) {
        return {
            uploadByFile: function (file) {
                var body = new FormData();
                var validationError = validateEditorImageFile(file);

                if (validationError !== '') {
                    return Promise.reject(new Error(validationError));
                }

                body.append('image', file);
                body.append('csrf_token', csrfToken || '');
                appendUploadContext(body, getUploadContext);

                return fetch(buildMediaUrl(uploadUrl, 'upload_image'), {
                    method: 'POST',
                    body: body,
                    credentials: 'same-origin',
                    headers: csrfToken ? { 'X-CSRF-Token': csrfToken } : {}
                }).then(function (response) {
                    return parseEditorJsonResponse(response, 'Bild konnte nicht hochgeladen werden.');
                }).then(normalizeImageResponse);
            },
            uploadByUrl: function (url) {
                return fetch(buildMediaUrl(uploadUrl, 'fetch_image'), {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: Object.assign({
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    }, csrfToken ? { 'X-CSRF-Token': csrfToken } : {}),
                    body: JSON.stringify(buildUploadJsonPayload({ url: String(url || '') }, getUploadContext))
                }).then(function (response) {
                    return parseEditorJsonResponse(response, 'Bild-URL konnte nicht verarbeitet werden.');
                }).then(normalizeImageResponse);
            }
        };
    }

    function buildFileUploader(uploadUrl, csrfToken, getUploadContext) {
        return {
            uploadByFile: function (file) {
                var body = new FormData();

                body.append('file', file);
                body.append('attachment', file);
                body.append('csrf_token', csrfToken || '');
                appendUploadContext(body, getUploadContext);

                return fetch(buildMediaUrl(uploadUrl, 'upload_file'), {
                    method: 'POST',
                    body: body,
                    credentials: 'same-origin',
                    headers: csrfToken ? { 'X-CSRF-Token': csrfToken } : {}
                }).then(function (response) {
                    return parseEditorJsonResponse(response, 'Datei konnte nicht hochgeladen werden.');
                }).then(function (payload) {
                    var response = payload && typeof payload === 'object' ? payload : {};
                    var filePayload = response.file && typeof response.file === 'object' ? response.file : {};
                    var url = String(filePayload.url || response.url || '');

                    if (!response.success || url === '') {
                        throw new Error(String(response.message || 'Datei konnte nicht verarbeitet werden.'));
                    }

                    return {
                        success: 1,
                        file: Object.assign({}, filePayload, {
                            url: url,
                            name: filePayload.name || (file && file.name) || 'download'
                        })
                    };
                });
            },
            uploadByUrl: function (url) {
                var cleanUrl = sanitizeEditableUrl(url);
                var fileName = cleanUrl.split(/[?#]/)[0].split('/').pop() || 'download';

                if (cleanUrl === '') {
                    return Promise.reject(new Error('Datei-URL ist ungültig.'));
                }

                return Promise.resolve({
                    success: 1,
                    file: {
                        url: cleanUrl,
                        name: fileName
                    }
                });
            }
        };
    }

    function buildTools(uploadUrl, csrfToken, options) {
        var tools = {};
        var inlineToolbar = false;
        var resolvedOptions = options && typeof options === 'object' ? options : {};
        var getUploadContext = typeof resolvedOptions.getUploadContext === 'function' ? resolvedOptions.getUploadContext : null;
        var availableBlockTunes;

        addTool(tools, 'inlineCode', {}, false);
        addTool(tools, 'underline', {}, false);
        addTool(tools, 'strikethrough', {}, false);
        addTool(tools, 'hyperlink', {
            config: {
                shortcut: 'CMD+L',
                target: '_blank',
                rel: 'noopener noreferrer',
                availableTargets: ['_blank', '_self'],
                availableRels: ['noopener noreferrer', 'nofollow', 'external'],
                validate: false
            }
        }, false);
        addTool(tools, 'marker', {}, false);
        addTool(tools, 'spoiler', {}, false);
        addTool(tools, 'textColor', {
            config: {
                colorCollections: ['#111827', '#2563eb', '#7c3aed', '#dc2626', '#ea580c', '#16a34a', '#64748b', '#ffffff'],
                defaultColor: '#2563eb',
                type: 'text',
                customPicker: true
            }
        }, false);

        addTool(tools, 'anchor', { config: { maxWords: 4, maxChars: 80 } }, false);
        addTool(tools, 'alignmentTune', {
            config: {
                default: 'left',
                blocks: {
                    header: 'left',
                    paragraph: 'left',
                    quote: 'left'
                }
            }
        }, false);
        addTool(tools, 'indentTune', {
            config: {
                version: window.EditorJS && window.EditorJS.version ? window.EditorJS.version : '2.31',
                indentSize: 24,
                maxIndent: 6,
                minIndent: 0,
                handleShortcut: false
            }
        }, false);
        addTool(tools, 'textVariant', {}, false);
        addTool(tools, 'blockClipboard', {}, true);
        availableBlockTunes = getAvailableBlockTunes(tools);

        addTool(tools, 'paragraph', withBlockTunes({
            inlineToolbar: inlineToolbar,
            config: { placeholder: 'Text schreiben ...' }
        }, availableBlockTunes), true);

        addTool(tools, 'header', withBlockTunes({
            inlineToolbar: inlineToolbar,
            config: { levels: [2, 3, 4], defaultLevel: 2 }
        }, availableBlockTunes), true);

        addTool(tools, 'list', withBlockTunes({
            inlineToolbar: inlineToolbar,
            config: { defaultStyle: 'unordered', maxLevel: 4 }
        }, availableBlockTunes), true);

        addTool(tools, 'image', withBlockTunes({
            inlineToolbar: inlineToolbar,
            config: {
                uploadUrl: uploadUrl,
                csrfToken: csrfToken,
                uploader: buildImageUploader(uploadUrl, csrfToken, getUploadContext),
                captionPlaceholder: 'Bildunterschrift',
                buttonContent: 'Bild auswählen'
            }
        }, availableBlockTunes), true);

        addTool(tools, 'quote', withBlockTunes({
            inlineToolbar: inlineToolbar,
            config: {
                quotePlaceholder: 'Zitat',
                captionPlaceholder: 'Quelle'
            }
        }, availableBlockTunes), true);

        addTool(tools, 'code', withBlockTunes({}, availableBlockTunes), true);
        addTool(tools, 'table', withBlockTunes({
            inlineToolbar: inlineToolbar,
            config: { rows: 3, cols: 3, maxRows: 20, maxCols: 10, maxrows: 20, maxcols: 10 }
        }, availableBlockTunes), true);
        addTool(tools, 'delimiter', withBlockTunes({
            config: {
                styleOptions: ['line', 'dash', 'star'],
                defaultStyle: 'line',
                lineWidthOptions: [8, 15, 25, 35, 50, 60, 100],
                defaultLineWidth: 35,
                lineThicknessOptions: [1, 2, 3, 4, 5, 6],
                defaultLineThickness: 2
            }
        }, availableBlockTunes), true);
        addTool(tools, 'spacer', withBlockTunes({}, availableBlockTunes), true);

        addTool(tools, 'embed', withBlockTunes({
            inlineToolbar: inlineToolbar,
            config: {
                services: {
                    youtube: true,
                    vimeo: true,
                    twitter: true,
                    instagram: true,
                    codepen: true
                }
            }
        }, availableBlockTunes), false);
        addTool(tools, 'linkTool', withBlockTunes({
            inlineToolbar: inlineToolbar,
            config: {
                endpoint: buildMediaUrl(uploadUrl, 'fetch_link'),
                headers: csrfToken ? { 'X-CSRF-Token': csrfToken } : {}
            }
        }, availableBlockTunes), false);
        addTool(tools, 'attaches', withBlockTunes({
            config: {
                uploader: buildFileUploader(uploadUrl, csrfToken, getUploadContext),
                buttonText: 'Datei auswählen'
            }
        }, availableBlockTunes), false);
        addTool(tools, 'warning', withBlockTunes({
            inlineToolbar: inlineToolbar,
            config: { titlePlaceholder: 'Titel', messagePlaceholder: 'Hinweistext' }
        }, availableBlockTunes), false);
        addTool(tools, 'alert', withBlockTunes({
            inlineToolbar: inlineToolbar,
            shortcut: 'CMD+SHIFT+A',
            config: {
                alertTypes: ['primary', 'secondary', 'info', 'success', 'warning', 'danger', 'light', 'dark'],
                defaultType: 'info',
                defaultAlign: 'left',
                messagePlaceholder: 'Hinweistext'
            }
        }, availableBlockTunes), false);
        addTool(tools, 'raw', withBlockTunes({}, availableBlockTunes), false);
        addTool(tools, 'accordion', withBlockTunes({
            inlineToolbar: inlineToolbar
        }, availableBlockTunes), false);
        addTool(tools, 'imageGallery', withBlockTunes({
            config: {
                uploadUrl: uploadUrl,
                csrfToken: csrfToken,
                uploader: buildImageUploader(uploadUrl, csrfToken, getUploadContext)
            }
        }, availableBlockTunes), true);

        addTool(tools, 'mediaText', withBlockTunes({
            inlineToolbar: inlineToolbar,
            config: {
                uploadUrl: uploadUrl,
                csrfToken: csrfToken,
                uploader: buildImageUploader(uploadUrl, csrfToken, getUploadContext)
            }
        }, availableBlockTunes), true);

        addTool(tools, 'button', withBlockTunes({}, availableBlockTunes), true);

        return tools;
    }

    function getEditorHistoryState(editor) {
        var undo = editor && editor.cmsPlugins ? editor.cmsPlugins.undo : null;

        return {
            canUndo: !!(undo && typeof undo.canUndo === 'function' && undo.canUndo()),
            canRedo: !!(undo && typeof undo.canRedo === 'function' && undo.canRedo())
        };
    }

    function dispatchPluginState(holder, editor) {
        var detail = {
            plugins: editor && editor.cmsPlugins ? Object.keys(editor.cmsPlugins) : [],
            history: getEditorHistoryState(editor)
        };

        if (holder && typeof holder.dispatchEvent === 'function' && typeof window.CustomEvent === 'function') {
            holder.dispatchEvent(new CustomEvent('cms-editor-plugin-state', { detail: detail }));
        }

        if (editor) {
            editor.cmsPluginState = detail;
        }
    }

    function initializeEditorPlugins(editor, holder, normalizedData) {
        var UndoPlugin = tryResolvePluginClass('undo');
        var DragDropPlugin = tryResolvePluginClass('dragDrop');
        var undoInstance;

        editor.cmsPlugins = editor.cmsPlugins || {};

        if (UndoPlugin && !editor.cmsPlugins.undo) {
            try {
                undoInstance = new UndoPlugin({
                    editor: editor,
                    maxLength: 80,
                    config: { debounceTimer: 350 },
                    onUpdate: function () {
                        dispatchPluginState(holder, editor);
                    }
                });
                if (typeof undoInstance.initialize === 'function') {
                    undoInstance.initialize(normalizedData || { blocks: [] });
                }
                editor.cmsPlugins.undo = undoInstance;
            } catch (error) {
                logWarn('EditorJS undo plugin init failed.', error);
            }
        }

        if (DragDropPlugin && !editor.cmsPlugins.dragDrop) {
            try {
                editor.cmsPlugins.dragDrop = new DragDropPlugin(editor, '2px solid #2563eb');
            } catch (error) {
                logWarn('EditorJS drag/drop plugin init failed.', error);
            }
        }

        dispatchPluginState(holder, editor);
    }

    function createCmsEditor(holderId, initialData, uploadUrl, csrfToken, options) {
        var holder = document.getElementById(holderId);
        var editor;
        var normalizedData;
        var tools;
        var resolvedOptions = options && typeof options === 'object' ? options : {};
        var toolChangeSyncTimer = null;
        var changeSyncInFlight = false;
        var changeSyncPending = false;
        var changeSyncDestroyed = false;
        var originalDestroy;
        var cleanupBlockClipboard = function () {};
        var syncEditorChange;
        var runEditorChangeSync;
        var reportEditorError = function (stage, error) {
            if (typeof resolvedOptions.onError === 'function') {
                try {
                    resolvedOptions.onError(error, { holderId: holderId, stage: stage });
                } catch (callbackError) {
                    logWarn('Editor onError callback failed.', callbackError);
                }
            }
        };

        if (!holder) {
            throw new Error('EditorJS holder missing: ' + holderId);
        }

        if (typeof window.EditorJS !== 'function') {
            throw new Error('EditorJS UMD core missing.');
        }

        normalizedData = normalizeInitialData(initialData);
        tools = buildTools(uploadUrl, csrfToken, resolvedOptions);
        holder.dataset.editorState = 'loading';
        holder.setAttribute('role', holder.getAttribute('role') || 'region');
        holder.setAttribute('aria-label', holder.getAttribute('aria-label') || 'Block-Editor');
        holder.setAttribute('aria-busy', 'true');
        if (!holder.hasAttribute('tabindex')) {
            holder.tabIndex = -1;
        }
        applyEditorPreviewTypography(holder, resolvedOptions.themeTypography || resolvedOptions.typography || {});

        runEditorChangeSync = function () {
            if (changeSyncDestroyed || typeof resolvedOptions.onChange !== 'function') {
                return;
            }

            if (!editor || typeof editor.save !== 'function') {
                changeSyncPending = true;
                return;
            }

            if (changeSyncInFlight) {
                changeSyncPending = true;
                return;
            }

            changeSyncInFlight = true;
            changeSyncPending = false;

            editor.save().then(function (output) {
                if (!changeSyncDestroyed) {
                    try {
                        resolvedOptions.onChange(normalizeInitialData(output));
                    } catch (callbackError) {
                        logWarn('Editor onChange callback failed.', callbackError);
                        reportEditorError('change-callback', callbackError);
                    }
                }
            }, function (error) {
                logWarn('Change sync failed.', error);
                reportEditorError('change-sync', error);
            }).then(function () {
                changeSyncInFlight = false;
                if (changeSyncPending && !changeSyncDestroyed) {
                    runEditorChangeSync();
                }
            });
        };

        syncEditorChange = function () {
            if (typeof resolvedOptions.onChange !== 'function') {
                return;
            }

            if (toolChangeSyncTimer !== null) {
                window.clearTimeout(toolChangeSyncTimer);
            }

            toolChangeSyncTimer = window.setTimeout(function () {
                toolChangeSyncTimer = null;
                runEditorChangeSync();
            }, 80);
        };

        if (holder.cmsEditorToolChangeHandler) {
            holder.removeEventListener('cms-editorjs-tool-change', holder.cmsEditorToolChangeHandler);
        }
        if (holder.cmsEditorNativeChangeHandler) {
            holder.removeEventListener('change', holder.cmsEditorNativeChangeHandler, true);
        }
        holder.cmsEditorToolChangeHandler = syncEditorChange;
        holder.cmsEditorNativeChangeHandler = syncEditorChange;
        holder.addEventListener('cms-editorjs-tool-change', syncEditorChange);
        holder.addEventListener('change', syncEditorChange, true);

        try {
            editor = new window.EditorJS({
                holder: holderId,
                data: normalizedData,
                tools: tools,
                defaultBlock: 'paragraph',
                readOnly: !!resolvedOptions.readOnly,
                logLevel: resolvedOptions.logLevel || 'ERROR',
                minHeight: 160,
                autofocus: false,
                placeholder: 'Schreibe Inhalt oder wähle einen Block ...',
                i18n: {
                    direction: 'ltr',
                    messages: EDITOR_I18N_DE
                },
                onReady: function () {
                    holder.dataset.editorState = 'editor';
                    holder.setAttribute('aria-busy', 'false');
                    if (!resolvedOptions.readOnly) {
                        cleanupBlockClipboard = bindCmsBlockClipboardBehavior(holder, editor);
                    }
                    initializeEditorPlugins(editor, holder, normalizedData);
                    logInfo('Editor ready.', { holderId: holderId, tools: TOOL_NAMES });
                    if (typeof resolvedOptions.onReady === 'function') {
                        try {
                            resolvedOptions.onReady(editor, { holderId: holderId, tools: Object.keys(tools) });
                        } catch (callbackError) {
                            logWarn('Editor onReady callback failed.', callbackError);
                            reportEditorError('ready-callback', callbackError);
                        }
                    }
                },
                onChange: function () {
                    syncEditorChange();
                },
                onError: function (error) {
                    reportEditorError('runtime', error);
                }
            });
        } catch (error) {
            holder.dataset.editorState = 'error';
            holder.setAttribute('aria-busy', 'false');
            reportEditorError('init', error);
            throw error;
        }
        editor.cmsAvailableTools = Object.keys(tools);
        editor.cmsReadOnly = !!resolvedOptions.readOnly;

        if (editor.isReady && typeof editor.isReady.then === 'function') {
            editor.isReady.catch(function (error) {
                holder.dataset.editorState = 'error';
                holder.setAttribute('aria-busy', 'false');
                reportEditorError('ready', error);
            });
        }

        originalDestroy = typeof editor.destroy === 'function' ? editor.destroy.bind(editor) : null;
        editor.destroy = function () {
            var destroyResult;

            changeSyncDestroyed = true;
            changeSyncPending = false;
            if (toolChangeSyncTimer !== null) {
                window.clearTimeout(toolChangeSyncTimer);
                toolChangeSyncTimer = null;
            }
            holder.removeEventListener('cms-editorjs-tool-change', syncEditorChange);
            holder.removeEventListener('change', syncEditorChange, true);
            if (holder.cmsEditorToolChangeHandler === syncEditorChange) {
                delete holder.cmsEditorToolChangeHandler;
            }
            if (holder.cmsEditorNativeChangeHandler === syncEditorChange) {
                delete holder.cmsEditorNativeChangeHandler;
            }
            cleanupBlockClipboard();
            holder.removeAttribute('aria-busy');

            destroyResult = originalDestroy ? originalDestroy() : undefined;
            if (destroyResult && typeof destroyResult.catch === 'function') {
                return destroyResult.catch(function (error) {
                    logWarn('Editor destroy failed.', error);
                });
            }

            return destroyResult;
        };

        return editor;
    }

    function saveEditorToInput(editor, input) {
        if (!editor || typeof editor.save !== 'function' || !input) {
            return Promise.resolve(false);
        }

        return editor.save().then(function (output) {
            input.value = JSON.stringify(normalizeInitialData(output));
            return true;
        });
    }

    window.createCmsEditor = createCmsEditor;
    window.saveEditorToInput = saveEditorToInput;
    window.cmsNormalizeEditorJsData = normalizeInitialData;
    window.cmsEditorJsOrgAssetTools = TOOL_NAMES.slice();
    window.cmsEditorJsOrgAssetPlugins = PLUGIN_NAMES.slice();
    window.cmsEditorJsCoreReady = Promise.resolve(typeof window.EditorJS === 'function');

    if (!window.cmsEditorDebug || typeof window.cmsEditorDebug !== 'object') {
        window.cmsEditorDebug = {};
    }
    window.cmsEditorDebug.orgAssetBoot = {
        version: VERSION,
        hasCore: typeof window.EditorJS === 'function',
        tools: TOOL_NAMES.slice(),
        plugins: PLUGIN_NAMES.slice(),
        globals: Object.assign({}, TOOL_GLOBALS),
        pluginGlobals: Object.assign({}, PLUGIN_GLOBALS),
        loadedAt: new Date().toISOString()
    };

    logInfo('Factory loaded.', window.cmsEditorDebug.orgAssetBoot);
})();
