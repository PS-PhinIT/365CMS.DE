(function () {
    'use strict';

    function parseJsonInput(id, fallback) {
        var input = document.getElementById(id);
        if (!input || !input.value) {
            return fallback;
        }

        try {
            return JSON.parse(input.value);
        } catch (_error) {
            return fallback;
        }
    }

    function showAlert(type, message) {
        if (typeof window.cmsAlert === 'function') {
            window.cmsAlert(type, message);
            return;
        }

        console[type === 'danger' ? 'error' : 'log'](message);
    }

    function log(level, message, details) {
        var method = level === 'error' ? 'error' : 'log';

        if (typeof console === 'undefined' || typeof console[method] !== 'function') {
            return;
        }
        if (typeof details === 'undefined') {
            console[method]('[Hub-Site-Editor] ' + String(message || ''));
            return;
        }
        console[method]('[Hub-Site-Editor] ' + String(message || ''), details);
    }

    function absoluteUrlFromPath(path) {
        var normalizedPath = String(path || '').trim();

        if (normalizedPath === '') {
            return '';
        }

        try {
            return new URL(normalizedPath, window.location.origin + '/').toString();
        } catch (_error) {
            return normalizedPath;
        }
    }

    function focusElement(element) {
        if (!element || typeof element.focus !== 'function') {
            return;
        }

        try {
            element.focus({ preventScroll: true });
        } catch (_error) {
            element.focus();
        }
    }

    function sanitizePreviewImageUrl(value) {
        var url = String(value || '').trim();
        var parsed;

        if (url === '' || url === '#' || /[\u0000\r\n<>"']/.test(url)) {
            return '';
        }

        if (/^(?:\/|\.\/|\.\.\/)/.test(url)) {
            return url;
        }

        try {
            parsed = new URL(url, window.location.origin);
        } catch (_error) {
            return '';
        }

        return /^(https?:)$/i.test(parsed.protocol) ? url : '';
    }

    function clearElement(element) {
        if (!element) {
            return;
        }

        while (element.firstChild) {
            element.removeChild(element.firstChild);
        }
    }

    function createElement(tag, className, text) {
        var element = document.createElement(tag);

        if (className) {
            element.className = className;
        }

        if (text !== undefined) {
            element.textContent = String(text);
        }

        return element;
    }

    function setDataAttributes(element, data) {
        Object.keys(data || {}).forEach(function (key) {
            var value = data[key];
            if (value === undefined || value === null) {
                return;
            }

            element.dataset[key] = String(value);
        });

        return element;
    }

    function createFieldLabel(text) {
        return createElement('label', 'form-label small', text);
    }

    function createFormHint(text) {
        return createElement('div', 'form-hint', text);
    }

    function createInput(value, data, options) {
        var input = createElement('input', 'form-control form-control-sm');
        var settings = options || {};

        input.type = settings.type || 'text';
        input.value = String(value || '');
        setDataAttributes(input, data);

        if (settings.placeholder) {
            input.placeholder = settings.placeholder;
        }
        if (settings.min !== undefined) {
            input.min = String(settings.min);
        }
        if (settings.max !== undefined) {
            input.max = String(settings.max);
        }
        if (settings.step !== undefined) {
            input.step = String(settings.step);
        }
        if (settings.disabled) {
            input.disabled = true;
        }

        return input;
    }

    function createTextarea(id, value, data, options) {
        var textarea = createElement('textarea', 'form-control form-control-sm');
        var settings = options || {};

        if (id) {
            textarea.id = id;
        }

        textarea.rows = String(settings.rows || 3);
        textarea.value = String(value || '');
        setDataAttributes(textarea, data);

        if (settings.editor) {
            textarea.dataset.editor = settings.editor;
        }
        if (settings.source) {
            textarea.dataset.source = settings.source;
        }

        return textarea;
    }

    function createFieldColumn(columnClass, labelText, control, hintText, actionButton) {
        var column = createElement('div', columnClass);

        if (labelText) {
            if (actionButton) {
                var labelRow = createElement('div', 'd-flex align-items-center justify-content-between gap-2');
                labelRow.appendChild(createFieldLabel(labelText));
                labelRow.appendChild(actionButton);
                column.appendChild(labelRow);
            } else {
                column.appendChild(createFieldLabel(labelText));
            }
        }

        column.appendChild(control);

        if (hintText) {
            column.appendChild(createFormHint(hintText));
        }

        return column;
    }

    function hasExcludedHubTranslationContent(value) {
        var source = String(value || '');

        return /<table\b/i.test(source) || /\[[^\]]*\]/.test(source);
    }

    function extractHubEditorText(value) {
        var parsed;
        var chunks = [];
        var textKeys = ['text', 'caption', 'title', 'message', 'code', 'html', 'content', 'description', 'label'];

        try {
            parsed = JSON.parse(String(value || ''));
        } catch (_error) {
            return null;
        }
        if (!parsed || !Array.isArray(parsed.blocks)) {
            return null;
        }

        function collect(node) {
            if (!node || typeof node !== 'object') {
                return;
            }
            if (Array.isArray(node)) {
                node.forEach(collect);
                return;
            }
            Object.keys(node).forEach(function (key) {
                var child = node[key];
                if (textKeys.indexOf(key) !== -1 && typeof child === 'string') {
                    chunks.push(child);
                } else if (child && typeof child === 'object') {
                    collect(child);
                }
            });
        }

        parsed.blocks.forEach(function (block) {
            collect(block && block.data);
        });
        return chunks.join(' ');
    }

    function hasTranslatableHubContent(value) {
        var source = String(value || '').trim();
        var editorText;

        if (source === '') {
            return false;
        }
        editorText = extractHubEditorText(source);
        if (editorText !== null) {
            source = editorText;
        }
        if (hasExcludedHubTranslationContent(source)) {
            return false;
        }
        source = source.replace(/<[^>]*>/g, ' ').replace(/&nbsp;|&#160;/gi, ' ');
        return source.trim() !== '';
    }

    function hasHubFieldContent(value) {
        var source = String(value || '').trim();
        var editorText = extractHubEditorText(source);

        return (editorText !== null ? editorText : source).trim() !== '';
    }

    function createHubCopyButton(sourceKey, targetKey, index, sourceValue) {
        if (!hasHubFieldContent(sourceValue)) {
            return null;
        }

        var button = createElement('button', 'btn btn-outline-primary btn-sm mb-1', 'Aus DE kopieren');
        button.type = 'button';
        button.title = 'Deutschen Feldinhalt in das englische Feld kopieren.';
        setDataAttributes(button, {
            hubCopyCard: '1',
            index: index,
            sourceKey: sourceKey,
            targetKey: targetKey
        });

        return button;
    }

    function createAiTranslationButton(sourceKey, targetKey, index, enabled, sourceValue) {
        if (!enabled || !hasTranslatableHubContent(sourceValue)) {
            return null;
        }

        var button = createElement('button', 'btn btn-outline-secondary btn-sm mb-1', 'Mit KI übersetzen');
        button.type = 'button';
        button.title = 'Den Inhalt des englischen Feldes mit KI übersetzen.';
        setDataAttributes(button, {
            hubAiTranslateCard: '1',
            index: index,
            sourceKey: sourceKey,
            targetKey: targetKey
        });

        return button;
    }

    function createHubCardTranslationActions(sourceKey, targetKey, index, enabled, sourceValue) {
        var group = createElement('div', 'd-flex align-items-center gap-1 flex-wrap');
        var copyButton = createHubCopyButton(sourceKey, targetKey, index, sourceValue);
        var translateButton = createAiTranslationButton(sourceKey, targetKey, index, enabled, sourceValue);

        if (copyButton) {
            group.appendChild(copyButton);
        }
        if (translateButton) {
            group.appendChild(translateButton);
        }

        return group.childNodes.length ? group : null;
    }

    function createImagePreview(url) {
        var preview = createElement('div', 'hub-card-image-preview');
        var cleanUrl = sanitizePreviewImageUrl(url);

        if (cleanUrl === '') {
            preview.classList.add('d-none');
            return preview;
        }

        var image = document.createElement('img');
        image.src = cleanUrl;
        image.alt = '';
        image.loading = 'lazy';
        image.decoding = 'async';
        preview.appendChild(image);

        return preview;
    }

    function updateImagePreview(preview, url) {
        if (!preview) {
            return;
        }

        clearElement(preview);

        var cleanUrl = sanitizePreviewImageUrl(url);
        preview.classList.toggle('d-none', cleanUrl === '');
        if (cleanUrl === '') {
            return;
        }

        var image = document.createElement('img');
        image.src = cleanUrl;
        image.alt = '';
        image.loading = 'lazy';
        image.decoding = 'async';
        preview.appendChild(image);
    }

    function createCardImageColumn(index, card, options) {
        var column = createElement('div', 'col-md-8');
        var group = createElement('div', 'input-group input-group-sm hub-card-image-control');
        var input = createInput(card.image_url || '', { index: index, key: 'image_url' }, { placeholder: 'https://… oder /uploads/...' });
        var uploadButton = createElement('button', 'btn btn-outline-primary', 'Upload');
        var libraryButton = createElement('button', 'btn btn-outline-secondary', 'Mediathek');
        var clearButton = createElement('button', 'btn btn-outline-danger', 'Leeren');
        var fileInput = createElement('input', 'd-none');
        var preview = createImagePreview(card.image_url || '');

        uploadButton.type = 'button';
        libraryButton.type = 'button';
        clearButton.type = 'button';
        fileInput.type = 'file';
        fileInput.accept = '.jpg,.jpeg,.png,.gif,.webp,.bmp,.ico,image/jpeg,image/png,image/gif,image/webp,image/bmp,image/x-bmp,image/x-icon,image/vnd.microsoft.icon';

        uploadButton.addEventListener('click', function () {
            fileInput.click();
        });

        fileInput.addEventListener('change', function () {
            var file = fileInput.files && fileInput.files[0] ? fileInput.files[0] : null;
            if (!file || typeof options.onUploadImage !== 'function') {
                return;
            }

            options.onUploadImage(index, file, input, preview, uploadButton).finally(function () {
                fileInput.value = '';
            });
        });

        libraryButton.addEventListener('click', function () {
            if (typeof options.onOpenImageLibrary === 'function') {
                options.onOpenImageLibrary(index, input, preview, libraryButton);
            }
        });

        clearButton.addEventListener('click', function () {
            if (typeof options.onSetImageUrl === 'function') {
                options.onSetImageUrl(index, '', input, preview);
            }
        });

        column.appendChild(createFieldLabel(options.schema.image_label));
        group.appendChild(input);
        group.appendChild(uploadButton);
        group.appendChild(libraryButton);
        group.appendChild(clearButton);
        column.appendChild(group);
        column.appendChild(fileInput);
        column.appendChild(preview);
        column.appendChild(createFormHint('URL manuell eintragen, neues Bild hochladen oder vorhandenes Bild aus der Mediathek übernehmen.'));

        return column;
    }

    function createFeatureToggleColumn(index, card, featureSupported) {
        var column = createElement('div', 'col-12');
        var label = createElement('label', 'form-check form-switch mb-0');
        var input = createElement('input', 'form-check-input');
        var caption = createElement('span', 'form-check-label', 'Als Feature-Kachel in voller Breite darstellen');
        var hint = createFormHint(featureSupported
            ? 'Die Kachel bleibt an ihrer Position und wird im Frontend als breite Feature-Kachel gerendert.'
            : 'Dieses Template unterstützt aktuell keine separate Feature-Darstellung; die Markierung wird deshalb deaktiviert.');

        input.type = 'checkbox';
        input.checked = card.is_feature === true;
        input.disabled = !featureSupported;
        setDataAttributes(input, { index: index, key: 'is_feature' });

        label.appendChild(input);
        label.appendChild(caption);
        column.appendChild(label);
        column.appendChild(hint);

        return column;
    }

    function createRemoveButtonColumn(index) {
        var column = createElement('div', 'col-12 text-end');
        var button = createElement('button', 'btn btn-outline-danger btn-sm remove-card', 'Entfernen');

        button.type = 'button';
        button.dataset.index = String(index);
        column.appendChild(button);

        return column;
    }

    function createCardHeaderBar(index, totalCount) {
        var header = createElement('div', 'hub-card-item-header d-flex align-items-center justify-content-between gap-2 mb-2 pb-2 border-bottom');
        var start = createElement('div', 'd-flex align-items-center gap-2');
        var handle = createElement('span', 'hub-card-drag-handle', '⇅');
        var label = createElement('span', 'fw-semibold small text-secondary', 'Kachel ' + (index + 1));
        var moveGroup = createElement('div', 'btn-group btn-group-sm');
        var upButton = createElement('button', 'btn btn-outline-secondary', '↑');
        var downButton = createElement('button', 'btn btn-outline-secondary', '↓');

        handle.setAttribute('data-card-drag-handle', '1');
        handle.setAttribute('draggable', 'true');
        handle.setAttribute('role', 'button');
        handle.setAttribute('tabindex', '0');
        handle.setAttribute('title', 'Zum Sortieren ziehen');
        handle.setAttribute('aria-label', 'Kachel ' + (index + 1) + ' zum Sortieren ziehen');

        upButton.type = 'button';
        downButton.type = 'button';
        upButton.disabled = index === 0;
        downButton.disabled = index === totalCount - 1;
        upButton.setAttribute('aria-label', 'Kachel ' + (index + 1) + ' nach oben verschieben');
        downButton.setAttribute('aria-label', 'Kachel ' + (index + 1) + ' nach unten verschieben');
        setDataAttributes(upButton, { index: index, cardMove: 'up' });
        setDataAttributes(downButton, { index: index, cardMove: 'down' });

        start.appendChild(handle);
        start.appendChild(label);
        moveGroup.appendChild(upButton);
        moveGroup.appendChild(downButton);
        header.appendChild(start);
        header.appendChild(moveGroup);

        return header;
    }

    function createCardItemNode(card, index, options) {
        var wrapper = createElement('div', 'border-bottom p-3 hub-card-item');
        var row = createElement('div', 'row g-2');
        var suffix = options.activeLanguage === 'en' ? ' (EN)' : '';

        wrapper.dataset.cardIndex = String(index);
        wrapper.appendChild(createCardHeaderBar(index, options.totalCount));

        row.appendChild(createFeatureToggleColumn(index, card, options.featureSupported));
        row.appendChild(createFieldColumn(
            'col-md-4',
            'Abstand nach oben (px)',
            createInput(String(card.feature_spacing_top || 0), { index: index, key: 'feature_spacing_top' }, {
                type: 'number',
                min: 0,
                max: 240,
                step: 4,
                disabled: !card.is_feature
            }),
            'Zusätzlicher Abstand vor dieser Feature-Kachel.'
        ));
        row.appendChild(createFieldColumn(
            'col-md-6',
            options.schema.title_label + suffix,
            createInput(card[options.titleKey] || '', { index: index, key: options.titleKey }),
            null,
            options.activeLanguage === 'en' ? createHubCardTranslationActions('title', 'title_en', index, options.aiTranslationEnabled, card.title) : null
        ));
        row.appendChild(createFieldColumn('col-md-8', 'URL', createInput(card.url || '', { index: index, key: 'url' })));
        row.appendChild(createFieldColumn(
            'col-md-6',
            options.schema.badge_label + suffix,
            createInput(card[options.badgeKey] || '', { index: index, key: options.badgeKey }),
            null,
            options.activeLanguage === 'en' ? createHubCardTranslationActions('badge', 'badge_en', index, options.aiTranslationEnabled, card.badge) : null
        ));
        row.appendChild(createFieldColumn(
            'col-md-6',
            'Legacy Meta' + suffix,
            createInput(card[options.metaKey] || '', { index: index, key: options.metaKey }),
            null,
            options.activeLanguage === 'en' ? createHubCardTranslationActions('meta', 'meta_en', index, options.aiTranslationEnabled, card.meta) : null
        ));
        row.appendChild(createFieldColumn(
            'col-md-6',
            options.schema.meta_left_label + suffix,
            createInput(card[options.metaLeftKey] || '', { index: index, key: options.metaLeftKey }),
            null,
            options.activeLanguage === 'en' ? createHubCardTranslationActions('meta_left', 'meta_left_en', index, options.aiTranslationEnabled, card.meta_left) : null
        ));
        row.appendChild(createFieldColumn(
            'col-md-6',
            options.schema.meta_right_label + suffix,
            createInput(card[options.metaRightKey] || '', { index: index, key: options.metaRightKey }),
            null,
            options.activeLanguage === 'en' ? createHubCardTranslationActions('meta_right', 'meta_right_en', index, options.aiTranslationEnabled, card.meta_right) : null
        ));
        row.appendChild(createFieldColumn(
            'col-md-6',
            options.schema.button_text_label + suffix,
            createInput(card[options.buttonTextKey] || '', { index: index, key: options.buttonTextKey }),
            null,
            options.activeLanguage === 'en' ? createHubCardTranslationActions('button_text', 'button_text_en', index, options.aiTranslationEnabled, card.button_text) : null
        ));
        row.appendChild(createFieldColumn('col-md-6', options.schema.button_link_label, createInput(card.button_link || '', { index: index, key: 'button_link' })));
        row.appendChild(createCardImageColumn(index, card, options));
        row.appendChild(createFieldColumn(
            'col-md-4',
            options.schema.image_alt_label + suffix,
            createInput(card[options.imageAltKey] || '', { index: index, key: options.imageAltKey }),
            null,
            options.activeLanguage === 'en' ? createHubCardTranslationActions('image_alt', 'image_alt_en', index, options.aiTranslationEnabled, card.image_alt) : null
        ));
        row.appendChild(createFieldColumn(
            'col-12',
            options.schema.summary_label + suffix,
            createTextarea('hub-card-summary-' + options.activeLanguage + '-' + index, card[options.summaryKey] || '', {
                index: index,
                key: options.summaryKey
            }, {
                rows: 6,
                editor: 'hub-richtext',
                source: 'cards'
            }),
            null,
            options.activeLanguage === 'en' ? createHubCardTranslationActions('summary', 'summary_en', index, options.aiTranslationEnabled, card.summary) : null
        ));
        row.appendChild(createRemoveButtonColumn(index));

        wrapper.appendChild(row);

        return wrapper;
    }

    function initializeHubSiteEditor() {
        var form = document.getElementById('hubSiteForm');
        var siteConfig = parseJsonInput('hubSiteConfigInput', {});
        var templateProfiles = parseJsonInput('hubTemplateProfilesInput', {});
        var cards = parseJsonInput('cardsJsonInput', []);
        var featureCards = parseJsonInput('featureCardsJsonInput', []);
        var container;
        var emptyState;
        var input;
        var featureInput;
        var titleInput;
        var templateSelect;
        var slugPreviewInput;
        var openPublicAfterSaveInput;
        var saveAndOpenPublicButton;
        var copySlugPreviewButton;
        var cardSchemaHint;
        var languageToggleButtons;
        var aiTranslationButtonsEnabled;
        var initialTemplateValue;
        var activeLanguage = 'de';
        var summaryEditors = new Map();
        var summaryEditorsInitTimer = null;
        var editorSubmitReady = false;
        var editorSubmitPending = false;
        var pendingEditorCleanup = Promise.resolve();
        var editorGeneration = 0;
        var dragCard = null;

        if (!form) {
            return;
        }

        container = document.getElementById('cardsContainer');
        emptyState = document.getElementById('cardsEmpty');
        input = document.getElementById('cardsJsonInput');
        featureInput = document.getElementById('featureCardsJsonInput');
        titleInput = form.querySelector('input[name="site_name"]');
        templateSelect = document.getElementById('hubTemplateSelect');
        slugPreviewInput = document.getElementById('hubSlugPreviewInput');
        openPublicAfterSaveInput = document.getElementById('openPublicAfterSaveInput');
        saveAndOpenPublicButton = document.getElementById('saveAndOpenPublicButton');
        copySlugPreviewButton = document.getElementById('copySlugPreviewButton');
        cardSchemaHint = document.getElementById('cardSchemaHint');
        languageToggleButtons = document.querySelectorAll('[data-hub-lang-toggle]');
        aiTranslationButtonsEnabled = siteConfig.aiTranslationEnabled === true;
        initialTemplateValue = templateSelect ? templateSelect.value : 'general-it';
        cards = Array.isArray(cards) ? cards : [];
        featureCards = Array.isArray(featureCards) ? featureCards : [];

        function templateSupportsFeatureCards(templateKey) {
            var profile = templateProfiles[templateKey] || {};
            var baseTemplate = String(profile.base_template || templateKey || 'general-it');

            return ['general-it', 'services', 'microsoft-365', 'datenschutz', 'compliance'].indexOf(baseTemplate) !== -1;
        }

        function getTemplateProfile() {
            var key = templateSelect ? templateSelect.value : initialTemplateValue;
            return templateProfiles[key] || templateProfiles['general-it'] || {};
        }

        function getCardSchema() {
            var profile = getTemplateProfile();
            var schema = profile.card_schema || {};

            return {
                columns: Math.min(3, Math.max(1, parseInt(schema.columns || 2, 10) || 2)),
                title_label: schema.title_label || 'Titel',
                summary_label: schema.summary_label || 'Kurzbeschreibung',
                badge_label: schema.badge_label || 'Badge',
                meta_left_label: schema.meta_left_label || 'Meta links',
                meta_right_label: schema.meta_right_label || 'Meta rechts',
                image_label: schema.image_label || 'Bild-URL',
                image_alt_label: schema.image_alt_label || 'Bild-Alt',
                button_text_label: schema.button_text_label || 'Button-Text',
                button_link_label: schema.button_link_label || 'Button-Link'
            };
        }

        function defaultCard() {
            return {
                is_feature: false,
                feature_spacing_top: 0,
                title: '',
                title_en: '',
                url: '#',
                badge: '',
                badge_en: '',
                meta: '',
                meta_en: '',
                meta_left: '',
                meta_left_en: '',
                meta_right: '',
                meta_right_en: '',
                image_url: '',
                image_alt: '',
                image_alt_en: '',
                summary: '',
                summary_en: '',
                button_text: '',
                button_text_en: '',
                button_link: ''
            };
        }

        function defaultFeatureCard() {
            return {
                insert_after: 0,
                title: '',
                title_en: '',
                text: '',
                text_en: '',
                image_url: '',
                image_alt: '',
                image_alt_en: ''
            };
        }

        function normalizeCard(card) {
            var normalized = Object.assign(defaultCard(), card || {});
            var featureSpacingTop = parseInt(normalized.feature_spacing_top, 10);

            normalized.is_feature = normalized.is_feature === true
                || normalized.is_feature === 1
                || normalized.is_feature === '1'
                || normalized.is_feature === 'true'
                || normalized.isFeature === true
                || normalized.display_variant === 'feature';
            normalized.feature_spacing_top = Number.isFinite(featureSpacingTop)
                ? Math.max(0, Math.min(240, featureSpacingTop))
                : 0;

            return normalized;
        }

        function normalizeFeatureCard(card) {
            var normalized = Object.assign(defaultFeatureCard(), card || {});
            var insertAfter = parseInt(normalized.insert_after, 10);

            normalized.insert_after = Number.isFinite(insertAfter) && insertAfter > 0 ? insertAfter : 0;

            return normalized;
        }

        function featureCardToHubCard(card) {
            var normalized = normalizeFeatureCard(card);

            return normalizeCard({
                is_feature: true,
                title: normalized.title,
                title_en: normalized.title_en,
                url: '#',
                badge: '',
                badge_en: '',
                meta: '',
                meta_en: '',
                meta_left: '',
                meta_left_en: '',
                meta_right: '',
                meta_right_en: '',
                image_url: normalized.image_url,
                image_alt: normalized.image_alt,
                image_alt_en: normalized.image_alt_en,
                summary: normalized.text,
                summary_en: normalized.text_en,
                button_text: '',
                button_text_en: '',
                button_link: ''
            });
        }

        function migrateLegacyFeatureCardsIntoCards() {
            var legacyInterval = parseInt(siteConfig.legacyFeatureCardInterval || '0', 10);
            var legacyInsertMap = {};
            var migratedCards = [];
            var regularCardCount = 0;

            if (!Array.isArray(featureCards) || featureCards.length === 0) {
                return;
            }

            featureCards.map(function (card) {
                return normalizeFeatureCard(card);
            }).forEach(function (card, index) {
                var insertAfter = card.insert_after;

                if (insertAfter <= 0 && Number.isFinite(legacyInterval) && legacyInterval > 0) {
                    insertAfter = (index + 1) * legacyInterval;
                }

                if (insertAfter <= 0) {
                    insertAfter = Number.MAX_SAFE_INTEGER;
                }

                legacyInsertMap[insertAfter] = legacyInsertMap[insertAfter] || [];
                legacyInsertMap[insertAfter].push(featureCardToHubCard(card));
            });

            cards.map(function (card) {
                return normalizeCard(card);
            }).forEach(function (card) {
                migratedCards.push(card);

                if (!card.is_feature) {
                    regularCardCount += 1;
                    if (legacyInsertMap[regularCardCount]) {
                        legacyInsertMap[regularCardCount].forEach(function (legacyCard) {
                            migratedCards.push(legacyCard);
                        });
                        delete legacyInsertMap[regularCardCount];
                    }
                }
            });

            Object.keys(legacyInsertMap)
                .map(function (key) {
                    return parseInt(key, 10);
                })
                .sort(function (left, right) {
                    return left - right;
                })
                .forEach(function (key) {
                    legacyInsertMap[key].forEach(function (legacyCard) {
                        migratedCards.push(legacyCard);
                    });
                });

            cards = migratedCards;
            featureCards = [];
            siteConfig.legacyFeatureCardInterval = 0;
            sync();
        }

        function sync() {
            input.value = JSON.stringify(cards);
            if (featureInput) {
                featureInput.value = JSON.stringify(featureCards);
            }
        }

        function destroySummaryEditors() {
            editorGeneration += 1;
            summaryEditors.forEach(function (editor, key) {
                var textarea = document.getElementById(key);

                if (textarea && textarea.dataset.source === 'form' && textarea.isConnected && !container.contains(textarea)) {
                    return;
                }

                try {
                    if (editor && typeof editor.destroy === 'function') {
                        pendingEditorCleanup = pendingEditorCleanup.then(function () {
                            return editor.destroy();
                        }).catch(function (error) {
                            log('error', 'EditorJS konnte beim Aktualisieren des Hub-Editors nicht beendet werden.', error);
                        });
                    }
                } catch (error) {
                    log('error', 'EditorJS konnte beim Aktualisieren des Hub-Editors nicht beendet werden.', error);
                }

                summaryEditors.delete(key);
            });
        }

        function initSummaryEditors() {
            var generation = editorGeneration;
            if (summaryEditorsInitTimer !== null) {
                window.clearTimeout(summaryEditorsInitTimer);
            }

            summaryEditorsInitTimer = window.setTimeout(function () {
                summaryEditorsInitTimer = null;
                pendingEditorCleanup.then(function () {
                    if (generation === editorGeneration) {
                        createSummaryEditors();
                    }
                });
            }, 0);
        }

        function createSummaryEditors() {
            if (!form) {
                return;
            }
            if (typeof window.createCmsEditor !== 'function') {
                log('error', 'EditorJS-Laufzeit für den Hub-Site-Editor wurde nicht geladen.');
                return;
            }

            form.querySelectorAll('textarea[data-editor="hub-richtext"], textarea[data-editor="hub-summary"]').forEach(function (textarea) {
                var languagePane = textarea.closest('[data-lang-pane]');

                if (languagePane && languagePane.classList.contains('d-none')) {
                    return;
                }

                createRichTextEditor(textarea);
            });
        }

        function createRichTextEditor(textarea) {
            var id = textarea.id;
            var index = parseInt(textarea.dataset.index || '-1', 10);
            var key = textarea.dataset.key || '';
            var source = textarea.dataset.source || 'cards';
            var isFormField = source === 'form';
            var targetCollection = source === 'feature' ? featureCards : cards;
            var targetRecord = !isFormField ? targetCollection[index] : null;
            var holder;
            var instance;

            if (!id || summaryEditors.has(id)) {
                return;
            }

            if (!isFormField && (index < 0 || !targetCollection[index] || !key)) {
                return;
            }

            try {
                holder = document.createElement('div');
                holder.id = id + 'EditorJs';
                holder.className = 'editorjs-holder hub-editorjs-holder';
                holder.setAttribute('aria-label', textarea.getAttribute('aria-label') || 'Hub-Texteditor');
                textarea.insertAdjacentElement('beforebegin', holder);
                textarea.hidden = true;

                instance = window.createCmsEditor(
                    holder.id,
                    textarea.value || '',
                    String(siteConfig.mediaUploadUrl || '/api/media'),
                    String(siteConfig.mediaToken || ''),
                    {
                        onChange: function (data) {
                            textarea.value = JSON.stringify(data);
                            if (targetRecord) {
                                targetRecord[key] = textarea.value;
                                sync();
                            }
                            if (isFormField) {
                                updateStaticAiTranslationButtons();
                            }
                        },
                        onError: function (error, context) {
                            log('error', 'EditorJS-Hubfeld konnte nicht initialisiert werden.', {
                                field: id,
                                context: context,
                                error: error
                            });
                        }
                    }
                );

                var editor = {
                    getContents: function () {
                        return textarea.value || '';
                    },
                    setContents: function (contents) {
                        textarea.value = String(contents || '');
                        if (!instance || !instance.blocks || typeof instance.blocks.render !== 'function') {
                            return Promise.resolve(textarea.value);
                        }

                        var normalized = typeof window.cmsNormalizeEditorJsData === 'function'
                            ? window.cmsNormalizeEditorJsData(textarea.value)
                            : { blocks: [{ type: 'paragraph', data: { text: textarea.value } }] };
                        return Promise.resolve(instance.isReady)
                            .then(function () {
                                return instance.blocks.render(normalized);
                            })
                            .then(function () {
                                return editor.save();
                            });
                    },
                    save: function () {
                        if (!instance || typeof instance.save !== 'function') {
                            return Promise.resolve(textarea.value || '');
                        }
                        return Promise.resolve(instance.isReady).then(function () {
                            return instance.save();
                        }).then(function (data) {
                            textarea.value = JSON.stringify(data);
                            if (targetRecord) {
                                targetRecord[key] = textarea.value;
                                sync();
                            }
                            return textarea.value;
                        });
                    },
                    destroy: function () {
                        return Promise.resolve(instance && instance.isReady).then(function () {
                            return editor.save();
                        }).catch(function (error) {
                            log('error', 'EditorJS-Inhalt konnte vor dem Neuaufbau nicht gesichert werden.', {
                                field: id,
                                error: error
                            });
                        }).then(function () {
                            if (instance && typeof instance.destroy === 'function') {
                                return Promise.resolve(instance.destroy()).catch(function (error) {
                                    log('error', 'EditorJS konnte beim Neuaufbau nicht beendet werden.', {
                                        field: id,
                                        error: error
                                    });
                                });
                            }
                        }).then(function () {
                            if (holder && holder.parentNode) {
                                holder.parentNode.removeChild(holder);
                            }
                            if (textarea.isConnected) {
                                textarea.hidden = false;
                            }
                        });
                    }
                };

                summaryEditors.set(id, editor);
            } catch (error) {
                if (holder && holder.parentNode) {
                    holder.parentNode.removeChild(holder);
                }
                textarea.hidden = false;
                log('error', 'EditorJS-Hubfeld konnte nicht erstellt werden.', { field: id, error: error });
            }
        }

        function slugify(value) {
            return String(value || '')
                .trim()
                .toLowerCase()
                .normalize('NFD')
                .replace(/[\u0300-\u036f]/g, '')
                .replace(/[^a-z0-9]+/g, '-')
                .replace(/^-+|-+$/g, '');
        }

        function currentPublicUrl() {
            var slugValue = String(slugPreviewInput.value || '').trim();
            var publicPath = slugValue === '' ? '/' : (slugValue.charAt(0) === '/' ? slugValue : '/' + slugValue);

            return absoluteUrlFromPath(publicPath);
        }

        function currentHubSlug() {
            var slugValue = String(slugPreviewInput && slugPreviewInput.value ? slugPreviewInput.value : '').trim();

            if (slugValue.charAt(0) === '/') {
                slugValue = slugValue.slice(1);
            }

            return slugValue || slugify(titleInput ? titleInput.value : '') || 'hub-site';
        }

        function mediaApiUrl(action) {
            var endpoint = String(siteConfig.mediaUploadUrl || '/api/media');
            var separator = endpoint.indexOf('?') === -1 ? '?' : '&';

            return endpoint + separator + 'action=' + encodeURIComponent(action);
        }

        function parseMediaPayload(response, fallbackMessage) {
            return response.json().catch(function () {
                throw new Error(fallbackMessage);
            }).then(function (payload) {
                if (!response.ok || !payload || Number(payload.success) !== 1) {
                    throw new Error(String(payload && payload.message ? payload.message : fallbackMessage));
                }

                return payload;
            });
        }

        function validateImageFile(file) {
            var maxSize = 25 * 1024 * 1024;
            var allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/bmp', 'image/x-icon', 'image/vnd.microsoft.icon'];
            var allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'ico'];
            var extension = String(file && file.name ? file.name.split('.').pop() : '').toLowerCase();

            if (!file) {
                return 'Bitte eine Bilddatei auswählen.';
            }
            if (file.size > maxSize) {
                return 'Das Bild ist größer als 25 MB.';
            }
            if (allowedTypes.indexOf(String(file.type || '').toLowerCase()) === -1 && allowedExtensions.indexOf(extension) === -1) {
                return 'Nur JPG, PNG, GIF, WebP, BMP oder ICO sind erlaubt.';
            }

            return '';
        }

        function setCardImageUrl(index, url, input, preview) {
            var cleanUrl = String(url || '').trim();

            if (!cards[index]) {
                return;
            }

            cards[index].image_url = cleanUrl;
            if (input) {
                input.value = cleanUrl;
            }
            updateImagePreview(preview, cleanUrl);
            sync();
        }

        function uploadHubCardImage(index, file, input, preview, button) {
            var validationError = validateImageFile(file);
            var originalText = button ? button.textContent : '';

            if (validationError !== '') {
                showAlert('danger', validationError);
                return Promise.resolve();
            }

            if (button) {
                button.disabled = true;
                button.textContent = 'Upload …';
            }

            var body = new FormData();
            body.append('action', 'upload_image');
            body.append('image', file);
            body.append('csrf_token', siteConfig.mediaToken || '');
            body.append('content_type', 'hub');
            body.append('content_slug', currentHubSlug());
            body.append('content_title', titleInput ? String(titleInput.value || '') : 'Hub Site');

            return fetch(mediaApiUrl('upload_image'), {
                method: 'POST',
                body: body,
                credentials: 'same-origin',
                headers: siteConfig.mediaToken ? { 'X-CSRF-Token': siteConfig.mediaToken } : {}
            }).then(function (response) {
                return parseMediaPayload(response, 'Bild konnte nicht hochgeladen werden.');
            }).then(function (payload) {
                var filePayload = payload.file || {};
                var url = String(filePayload.url || '').trim();

                if (url === '') {
                    throw new Error('Upload erfolgreich, aber keine Bild-URL erhalten.');
                }

                setCardImageUrl(index, url, input, preview);
                showAlert('success', 'Bild wurde hochgeladen und übernommen.');
            }).catch(function (error) {
                showAlert('danger', error && error.message ? error.message : 'Bild konnte nicht hochgeladen werden.');
            }).finally(function () {
                if (button) {
                    button.disabled = false;
                    button.textContent = originalText || 'Upload';
                }
            });
        }

        function normalizeLibraryItem(item) {
            var source = item && typeof item === 'object' ? item : {};
            var url = String(source.url || source.src || '').trim();

            if (url === '') {
                return null;
            }

            return {
                url: url,
                name: String(source.name || source.filename || 'Bild').trim(),
                path: String(source.path || '').trim(),
                caption: String(source.caption || source.alt || source.title || '').trim()
            };
        }

        function fetchLibraryImages() {
            return fetch(mediaApiUrl('list_images'), {
                method: 'GET',
                credentials: 'same-origin',
                headers: siteConfig.mediaToken ? { 'X-CSRF-Token': siteConfig.mediaToken } : {}
            }).then(function (response) {
                return parseMediaPayload(response, 'Mediathek konnte nicht geladen werden.');
            }).then(function (payload) {
                return (Array.isArray(payload.items) ? payload.items : []).map(normalizeLibraryItem).filter(Boolean);
            });
        }

        function openHubImageLibrary(index, input, preview, triggerElement) {
            var overlay = createElement('div', 'cms-editorjs-media-picker hub-card-media-picker');
            var dialog = createElement('div', 'cms-editorjs-media-picker__dialog');
            var header = createElement('div', 'cms-editorjs-media-picker__header');
            var title = createElement('strong', '', 'HubSite-Bild aus Mediathek auswählen');
            var closeButton = createElement('button', 'btn btn-sm btn-outline-secondary', 'Schließen');
            var search = createInput('', {}, { type: 'search', placeholder: 'Mediathek durchsuchen …' });
            var status = createElement('div', 'cms-editorjs-media-picker__status', 'Lade Mediathek …');
            var grid = createElement('div', 'cms-editorjs-media-picker__grid');
            var items = [];
            var previousFocus = document.activeElement;

            search.className = 'form-control form-control-sm';
            closeButton.type = 'button';
            dialog.setAttribute('role', 'dialog');
            dialog.setAttribute('aria-modal', 'true');
            dialog.setAttribute('aria-label', 'HubSite-Bild aus Mediathek auswählen');

            function closePicker() {
                overlay.remove();
                document.removeEventListener('keydown', handleKeydown);

                if (triggerElement && document.contains(triggerElement)) {
                    focusElement(triggerElement);
                    return;
                }
                if (previousFocus && document.contains(previousFocus)) {
                    focusElement(previousFocus);
                }
            }

            function getFocusableElements() {
                return Array.prototype.slice.call(dialog.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'))
                    .filter(function (element) {
                        return element.offsetParent !== null || element === document.activeElement;
                    });
            }

            function handleKeydown(event) {
                if (event.key === 'Escape') {
                    closePicker();
                    return;
                }

                if (event.key !== 'Tab') {
                    return;
                }

                var focusable = getFocusableElements();
                var first = focusable[0];
                var last = focusable[focusable.length - 1];

                if (!first || !last) {
                    event.preventDefault();
                    return;
                }

                if (event.shiftKey && document.activeElement === first) {
                    event.preventDefault();
                    focusElement(last);
                    return;
                }

                if (!event.shiftKey && document.activeElement === last) {
                    event.preventDefault();
                    focusElement(first);
                }
            }

            function filteredItems() {
                var query = String(search.value || '').trim().toLowerCase();

                if (query === '') {
                    return items;
                }

                return items.filter(function (item) {
                    return [item.name, item.path, item.caption, item.url].join(' ').toLowerCase().indexOf(query) !== -1;
                });
            }

            function renderItems() {
                var visibleItems = filteredItems();

                clearElement(grid);
                status.textContent = visibleItems.length + (visibleItems.length === 1 ? ' Bild verfügbar' : ' Bilder verfügbar');

                if (visibleItems.length === 0) {
                    grid.appendChild(createElement('div', 'cms-editorjs-media-picker__empty', 'Keine passenden Bilder gefunden.'));
                    return;
                }

                visibleItems.forEach(function (item) {
                    var button = createElement('button', 'cms-editorjs-media-picker__item');
                    var image = document.createElement('img');
                    var label = createElement('span', '', item.name || item.path || 'Bild');

                    button.type = 'button';
                    image.src = item.url;
                    image.alt = item.caption || item.name || 'Bild';
                    image.loading = 'lazy';
                    image.decoding = 'async';
                    button.appendChild(image);
                    button.appendChild(label);
                    button.addEventListener('click', function () {
                        setCardImageUrl(index, item.url, input, preview);
                        closePicker();
                    });
                    grid.appendChild(button);
                });
            }

            header.appendChild(title);
            header.appendChild(closeButton);
            dialog.appendChild(header);
            dialog.appendChild(search);
            dialog.appendChild(grid);
            dialog.appendChild(status);
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

            fetchLibraryImages().then(function (loadedItems) {
                items = loadedItems;
                renderItems();
                focusElement(search);
            }).catch(function (error) {
                status.textContent = error && error.message ? error.message : 'Mediathek konnte nicht geladen werden.';
                clearElement(grid);
                grid.appendChild(createElement('div', 'cms-editorjs-media-picker__empty', status.textContent));
            });
        }

        function prepareFormSubmission(resetOpenPublicAfterSave) {
            summaryEditors.forEach(function (editor, key) {
                var textarea = document.getElementById(key);
                if (editor && textarea && typeof editor.getContents === 'function') {
                    var source = textarea.dataset.source || 'cards';
                    var index = parseInt(textarea.dataset.index || '-1', 10);
                    textarea.value = editor.getContents();

                    if (source !== 'form') {
                        var collection = source === 'feature' ? featureCards : cards;
                        if (index >= 0 && collection[index] && textarea.dataset.key) {
                            collection[index][textarea.dataset.key] = textarea.value;
                        }
                    }
                }
            });
            sync();

            if (resetOpenPublicAfterSave && document.activeElement !== saveAndOpenPublicButton) {
                openPublicAfterSaveInput.value = '0';
            }
        }

        function getHubEditorValue(field) {
            var editor = field && field.id ? summaryEditors.get(field.id) : null;

            return editor && typeof editor.getContents === 'function'
                ? editor.getContents()
                : String(field && field.value || '');
        }

        function setHubEditorValue(field, value) {
            var editor = field && field.id ? summaryEditors.get(field.id) : null;

            if (editor && typeof editor.setContents === 'function') {
                return editor.setContents(value);
            }
            if (field) {
                field.value = value;
            }
            return Promise.resolve(String(value || ''));
        }

        function updateStaticAiTranslationButtons() {
            form.querySelectorAll('[data-hub-ai-translate-field], [data-hub-copy-field]').forEach(function (button) {
                var sourceName = button.dataset.sourceName || '';
                var sourceField = sourceName ? form.querySelector('[name="' + sourceName + '"]') : null;
                var sourceText = sourceField ? getHubEditorValue(sourceField) : '';

                if (!hasHubFieldContent(sourceText) && button.dataset.sourceFallbackName) {
                    var fallbackField = form.querySelector('[name="' + button.dataset.sourceFallbackName + '"]');
                    sourceText = fallbackField ? getHubEditorValue(fallbackField) : '';
                }

                button.hidden = button.hasAttribute('data-hub-copy-field')
                    ? !hasHubFieldContent(sourceText)
                    : !hasTranslatableHubContent(sourceText);
            });
        }

        function requestHubFieldTranslation(sourceText, richTextTarget) {
            var body = new FormData();
            var editorData;
            var input = String(sourceText || '');
            var isEditorPayload = false;

            if (!aiTranslationButtonsEnabled || !siteConfig.aiTranslationUrl || !siteConfig.aiTranslationToken) {
                return Promise.reject(new Error('Die KI-Übersetzung ist derzeit nicht verfügbar.'));
            }
            if (!input.trim()) {
                return Promise.reject(new Error('Das deutsche Quellfeld ist leer.'));
            }

            try {
                editorData = JSON.parse(input);
                isEditorPayload = Boolean(editorData && Array.isArray(editorData.blocks));
            } catch (_error) {
                editorData = null;
            }

            if (!isEditorPayload) {
                editorData = typeof window.cmsNormalizeEditorJsData === 'function'
                    ? window.cmsNormalizeEditorJsData(input)
                    : { blocks: [{ type: 'paragraph', data: { text: input } }] };
            }
            if (!richTextTarget) {
                editorData = { blocks: [{ type: 'paragraph', data: { text: input } }] };
            }

            body.append('csrf_token', siteConfig.aiTranslationToken);
            body.append('content_type', 'editorjs');
            body.append('source_locale', 'de');
            body.append('target_locale', 'en');
            body.append('editor_data', JSON.stringify(editorData));

            return fetch(siteConfig.aiTranslationUrl, {
                method: 'POST',
                credentials: 'same-origin',
                body: body
            }).then(function (response) {
                return response.json().catch(function () {
                    throw new Error('Der Übersetzungsdienst hat eine ungültige Antwort geliefert.');
                }).then(function (result) {
                    if (!response.ok || !result || result.success !== true) {
                        throw new Error(result && result.error ? result.error : 'Die Übersetzung konnte nicht erstellt werden.');
                    }

                    var contentData = result.translation
                        && result.translation.content_data
                        && Array.isArray(result.translation.content_data.blocks)
                        ? result.translation.content_data
                        : null;

                    if (!contentData || contentData.blocks.length === 0) {
                        throw new Error('Der Übersetzungsdienst hat keinen übersetzten Feldinhalt geliefert.');
                    }

                    if (richTextTarget) {
                        return JSON.stringify(contentData);
                    }

                    var firstBlock = contentData.blocks[0];
                    var translatedText = firstBlock && firstBlock.data ? firstBlock.data.text : null;
                    if (typeof translatedText !== 'string' || !translatedText.trim()) {
                        throw new Error('Der Übersetzungsdienst hat keinen übersetzten Feldinhalt geliefert.');
                    }
                    return translatedText;
                });
            });
        }

        function translateHubField(button) {
            var cardIndex = button.dataset.index;
            var sourceText;
            var sourceField;
            var targetField;
            var sourceName;
            var targetName;

            if (button.hasAttribute('data-hub-ai-translate-card')) {
                var index = parseInt(cardIndex || '-1', 10);

                if (index < 0 || !cards[index]) {
                    return Promise.reject(new Error('Das Quellfeld der Hub-Kachel wurde nicht gefunden.'));
                }

                targetName = button.dataset.targetKey || '';
                if (!targetName) {
                    return Promise.reject(new Error('Das Zielfeld der Hub-Kachel wurde nicht angegeben.'));
                }
                sourceText = String(cards[index][button.dataset.sourceKey || ''] || '');
                if (!hasTranslatableHubContent(sourceText)) {
                    return Promise.reject(new Error('Das deutsche Quellfeld der Hub-Kachel ist leer.'));
                }

                var cardTarget = targetName === 'summary_en'
                    ? document.getElementById('hub-card-summary-en-' + index)
                    : null;
                var cardItem = button.closest('.hub-card-item');
                var cardTargetField = cardTarget;

                if (!cardTargetField && cardItem) {
                    cardTargetField = Array.prototype.slice.call(cardItem.querySelectorAll('[data-key]')).find(function (field) {
                        return field.dataset.key === targetName;
                    }) || null;
                }

                if (!cardTargetField) {
                    return Promise.reject(new Error('Das englische Zielfeld der Hub-Kachel wurde nicht gefunden.'));
                }

                return requestHubFieldTranslation(
                    sourceText,
                    cardTargetField.matches('textarea[data-editor="hub-richtext"], textarea[data-editor="hub-summary"]')
                ).then(function (translatedText) {
                    return setHubEditorValue(cardTargetField, translatedText).then(function () {
                        cardTargetField.dispatchEvent(new Event('input', { bubbles: true }));
                        cards[index][targetName] = cardTargetField.value || translatedText;
                        sync();
                        render();
                        return translatedText;
                    });
                });
            }

            sourceName = button.dataset.sourceName || '';
            targetName = button.dataset.targetName || '';
            sourceField = sourceName ? form.querySelector('[name="' + sourceName + '"]') : null;
            targetField = targetName ? form.querySelector('[name="' + targetName + '"]') : null;

            if (!sourceField || !targetField) {
                return Promise.reject(new Error('Quell- oder Zielfeld für die Übersetzung wurde nicht gefunden.'));
            }

            sourceText = getHubEditorValue(sourceField);

            if (!hasTranslatableHubContent(sourceText)) {
                return Promise.reject(new Error('Das deutsche Quellfeld ist leer.'));
            }

            return requestHubFieldTranslation(
                sourceText,
                targetField.matches('textarea[data-editor="hub-richtext"], textarea[data-editor="hub-summary"]')
            ).then(function (translatedText) {
                return setHubEditorValue(targetField, translatedText).then(function () {
                    targetField.dispatchEvent(new Event('input', { bubbles: true }));
                    return translatedText;
                });
            });
        }

        function copyHubField(button) {
            var sourceText;
            var targetName;
            var sourceName;
            var sourceField;
            var targetField;

            if (button.hasAttribute('data-hub-copy-card')) {
                var index = parseInt(button.dataset.index || '-1', 10);
                var sourceKey = button.dataset.sourceKey || '';
                targetName = button.dataset.targetKey || '';

                if (index < 0 || !cards[index] || !sourceKey || !targetName) {
                    return Promise.reject(new Error('Quell- oder Zielfeld der Hub-Kachel wurde nicht gefunden.'));
                }

                var cardItem = button.closest('.hub-card-item');
                targetField = cardItem
                    ? Array.prototype.slice.call(cardItem.querySelectorAll('[data-key]')).find(function (field) {
                        return field.dataset.key === targetName;
                    }) || null
                    : null;
                if (!targetField) {
                    return Promise.reject(new Error('Das englische Zielfeld der Hub-Kachel wurde nicht gefunden.'));
                }

                sourceText = String(cards[index][sourceKey] || '');
                if (!hasHubFieldContent(sourceText)) {
                    return Promise.reject(new Error('Das deutsche Quellfeld ist leer.'));
                }

                return setHubEditorValue(targetField, sourceText).then(function () {
                    targetField.dispatchEvent(new Event('input', { bubbles: true }));
                    cards[index][targetName] = targetField.value || sourceText;
                    sync();
                    return sourceText;
                });
            }

            sourceName = button.dataset.sourceName || '';
            targetName = button.dataset.targetName || '';
            sourceField = sourceName ? form.querySelector('[name="' + sourceName + '"]') : null;
            targetField = targetName ? form.querySelector('[name="' + targetName + '"]') : null;

            if (!sourceField || !targetField) {
                return Promise.reject(new Error('Quell- oder Zielfeld zum Kopieren wurde nicht gefunden.'));
            }

            sourceText = getHubEditorValue(sourceField);
            if (!hasHubFieldContent(sourceText) && button.dataset.sourceFallbackName) {
                var fallbackField = form.querySelector('[name="' + button.dataset.sourceFallbackName + '"]');
                sourceText = fallbackField ? getHubEditorValue(fallbackField) : '';
            }
            if (!hasHubFieldContent(sourceText)) {
                return Promise.reject(new Error('Das deutsche Quellfeld ist leer.'));
            }

            return setHubEditorValue(targetField, sourceText).then(function () {
                targetField.dispatchEvent(new Event('input', { bubbles: true }));
                return sourceText;
            });
        }

        form.addEventListener('click', function (event) {
            var button = event.target && typeof event.target.closest === 'function'
                ? event.target.closest('[data-hub-copy-field], [data-hub-copy-card], [data-hub-ai-translate-field], [data-hub-ai-translate-card]')
                : null;

            if (!button || !form.contains(button)) {
                return;
            }

            event.preventDefault();
            if (button.disabled) {
                return;
            }

            var originalLabel = button.textContent;
            button.disabled = true;
            var isCopyAction = button.hasAttribute('data-hub-copy-field') || button.hasAttribute('data-hub-copy-card');
            button.textContent = isCopyAction ? 'Kopiere …' : 'Übersetze …';

            (isCopyAction ? copyHubField(button) : translateHubField(button)).then(function () {
                showAlert('success', isCopyAction
                    ? 'Deutscher Feldinhalt wurde kopiert. Bitte Änderungen noch speichern.'
                    : 'Feld wurde auf Englisch übersetzt. Bitte Änderungen noch speichern.');
            }).catch(function (error) {
                showAlert('danger', error && error.message ? error.message : 'Die Übersetzung ist fehlgeschlagen.');
            }).finally(function () {
                button.disabled = false;
                button.textContent = originalLabel;
            });
        });

        function copyHubUrl(url) {
            if (!navigator.clipboard || typeof navigator.clipboard.writeText !== 'function') {
                showAlert('warning', 'Kopieren wird von diesem Browser leider nicht unterstützt.');
                return;
            }

            navigator.clipboard.writeText(url).then(function () {
                showAlert('success', 'Public URL wurde in die Zwischenablage kopiert.');
            }).catch(function () {
                showAlert('danger', 'Public URL konnte nicht kopiert werden.');
            });
        }

        function updateSlugPreview() {
            var storedSlug = String(siteConfig.storedSlug || '');
            var nextSlug = storedSlug || slugify(titleInput ? titleInput.value : '') || 'hub-site';

            slugPreviewInput.value = '/' + nextSlug;
            if (copySlugPreviewButton) {
                copySlugPreviewButton.disabled = nextSlug === '';
            }
        }

        function applyStarterCardsIfNeeded(force) {
            var profile = getTemplateProfile();
            var starters = Array.isArray(profile.starter_cards) ? profile.starter_cards : [];

            if ((!force && cards.length > 0) || starters.length === 0) {
                return;
            }

            cards = starters.map(function (card) {
                return normalizeCard(card);
            });
            sync();
            render();
        }

        function setActiveLanguage(lang) {
            activeLanguage = lang === 'en' ? 'en' : 'de';

            document.querySelectorAll('[data-lang-pane]').forEach(function (pane) {
                var isMatch = pane.getAttribute('data-lang-pane') === activeLanguage;
                pane.classList.toggle('d-none', !isMatch);
            });

            updateStaticAiTranslationButtons();

            languageToggleButtons.forEach(function (button) {
                var isActive = button.getAttribute('data-hub-lang-toggle') === activeLanguage;
                button.classList.toggle('btn-primary', isActive);
                button.classList.toggle('btn-outline-primary', !isActive);
                button.setAttribute('aria-pressed', isActive ? 'true' : 'false');
            });

            render();
        }

        function cardNodes() {
            return Array.prototype.slice.call(container.children).filter(function (node) {
                return node.classList && node.classList.contains('hub-card-item');
            });
        }

        function clearCardDropTargets() {
            cardNodes().forEach(function (node) {
                node.classList.remove('is-drop-target');
            });
        }

        function moveCard(fromIndex, toIndex) {
            var moved;

            if (fromIndex < 0 || fromIndex >= cards.length || toIndex < 0 || toIndex >= cards.length || fromIndex === toIndex) {
                return;
            }

            moved = cards.splice(fromIndex, 1)[0];
            cards.splice(toIndex, 0, moved);
            render();
        }

        function render() {
            var schema = getCardSchema();
            var templateKey = templateSelect ? templateSelect.value : initialTemplateValue;
            var featureSupported = templateSupportsFeatureCards(templateKey);
            var titleKey = activeLanguage === 'en' ? 'title_en' : 'title';
            var badgeKey = activeLanguage === 'en' ? 'badge_en' : 'badge';
            var metaKey = activeLanguage === 'en' ? 'meta_en' : 'meta';
            var metaLeftKey = activeLanguage === 'en' ? 'meta_left_en' : 'meta_left';
            var metaRightKey = activeLanguage === 'en' ? 'meta_right_en' : 'meta_right';
            var imageAltKey = activeLanguage === 'en' ? 'image_alt_en' : 'image_alt';
            var summaryKey = activeLanguage === 'en' ? 'summary_en' : 'summary';
            var buttonTextKey = activeLanguage === 'en' ? 'button_text_en' : 'button_text';
            var renderOptions;

            destroySummaryEditors();
            clearElement(container);
            emptyState.classList.toggle('d-none', cards.length !== 0);

            if (cardSchemaHint) {
                cardSchemaHint.textContent = 'Template-Vorgabe: ' + schema.columns + ' Kachel' + (schema.columns === 1 ? '' : 'n') + ' pro Reihe. Aktive Sprachansicht: ' + (activeLanguage === 'en' ? 'English' : 'Deutsch') + '. ' + (featureSupported ? 'Per Schalter kannst du jede Kachel als Feature-Kachel in Vollbreite markieren — ideal für Dienstleistungs-Highlights.' : 'Dieses Template rendert markierte Feature-Kacheln aktuell wie normale Kacheln.');
            }

            renderOptions = {
                activeLanguage: activeLanguage,
                featureSupported: featureSupported,
                schema: schema,
                totalCount: cards.length,
                titleKey: titleKey,
                badgeKey: badgeKey,
                metaKey: metaKey,
                metaLeftKey: metaLeftKey,
                metaRightKey: metaRightKey,
                imageAltKey: imageAltKey,
                summaryKey: summaryKey,
                buttonTextKey: buttonTextKey,
                aiTranslationEnabled: aiTranslationButtonsEnabled,
                onSetImageUrl: setCardImageUrl,
                onUploadImage: uploadHubCardImage,
                onOpenImageLibrary: openHubImageLibrary
            };

            cards.forEach(function (card, index) {
                card = normalizeCard(card);
                cards[index] = card;
                container.appendChild(createCardItemNode(card, index, renderOptions));
            });

            sync();
            initSummaryEditors();
        }

        document.getElementById('addCard').addEventListener('click', function () {
            cards.push(defaultCard());
            render();
        });

        if (templateSelect) {
            templateSelect.addEventListener('change', function () {
                applyStarterCardsIfNeeded(cards.length === 0);
                render();
            });
        }

        if (titleInput) {
            titleInput.addEventListener('input', updateSlugPreview);
        }

        form.addEventListener('input', updateStaticAiTranslationButtons);

        languageToggleButtons.forEach(function (button) {
            button.addEventListener('click', function () {
                setActiveLanguage(button.getAttribute('data-hub-lang-toggle') || 'de');
            });
        });

        if (copySlugPreviewButton) {
            copySlugPreviewButton.addEventListener('click', function () {
                copyHubUrl(currentPublicUrl());
            });
        }

        document.querySelectorAll('[data-copy-hub-path]').forEach(function (button) {
            button.addEventListener('click', function () {
                copyHubUrl(absoluteUrlFromPath(button.getAttribute('data-copy-hub-path') || '') || currentPublicUrl());
            });
        });

        if (saveAndOpenPublicButton) {
            saveAndOpenPublicButton.addEventListener('click', function () {
                openPublicAfterSaveInput.value = '1';
                if (typeof form.requestSubmit === 'function') {
                    form.requestSubmit();
                    return;
                }

                prepareFormSubmission(false);
                var fallbackSubmitter = document.createElement('button');
                fallbackSubmitter.type = 'submit';
                fallbackSubmitter.hidden = true;
                form.appendChild(fallbackSubmitter);
                fallbackSubmitter.click();
                fallbackSubmitter.remove();
            });
        }

        form.addEventListener('submit', function (event) {
            if (editorSubmitReady) {
                editorSubmitReady = false;
                prepareFormSubmission(true);
                return;
            }

            event.preventDefault();
            if (editorSubmitPending) {
                return;
            }
            editorSubmitPending = true;
            Promise.all([pendingEditorCleanup].concat(Array.from(summaryEditors.values()).map(function (editor) {
                return editor && typeof editor.save === 'function' ? editor.save() : Promise.resolve();
            }))).then(function () {
                prepareFormSubmission(true);
                editorSubmitPending = false;
                editorSubmitReady = true;
                if (typeof form.requestSubmit === 'function') {
                    form.requestSubmit();
                    window.setTimeout(function () {
                        editorSubmitReady = false;
                    }, 0);
                    return;
                }
                form.submit();
            }).catch(function (error) {
                editorSubmitPending = false;
                log('error', 'EditorJS-Hubinhalte konnten nicht für das Speichern vorbereitet werden.', error);
                showAlert('danger', 'Ein EditorJS-Inhalt konnte nicht gespeichert werden. Bitte Eingaben prüfen und erneut versuchen.');
            });
        });

        container.addEventListener('input', function (event) {
            var target = event.target;
            var index = parseInt(target.dataset.index || '-1', 10);
            var key = target.dataset.key || '';
            var source = target.dataset.source || 'cards';
            var collection = source === 'feature' ? featureCards : cards;
            if (index < 0 || !collection[index] || !key) {
                return;
            }

            collection[index][key] = key === 'is_feature'
                ? Boolean(target.checked)
                : target.value;

            if (key === 'image_url' && source === 'cards') {
                var imageColumn = typeof target.closest === 'function' ? target.closest('.col-md-8') : null;
                var preview = imageColumn ? imageColumn.querySelector('.hub-card-image-preview') : null;
                updateImagePreview(preview, target.value);
            }

            sync();
        });

        container.addEventListener('click', function (event) {
            var moveButton = event.target.closest('[data-card-move]');
            var removeButton;
            var index;

            if (moveButton) {
                index = parseInt(moveButton.dataset.index || '-1', 10);
                if (index < 0) {
                    return;
                }
                moveCard(index, moveButton.dataset.cardMove === 'up' ? index - 1 : index + 1);
                return;
            }

            removeButton = event.target.closest('.remove-card');
            if (!removeButton) {
                return;
            }
            index = parseInt(removeButton.dataset.index || '-1', 10);
            if (index < 0) {
                return;
            }
            cards.splice(index, 1);
            render();
        });

        container.addEventListener('dragstart', function (event) {
            var handle = event.target.closest('[data-card-drag-handle]');
            var card = handle ? handle.closest('.hub-card-item') : null;

            if (!handle || !card) {
                event.preventDefault();
                return;
            }

            dragCard = card;
            card.classList.add('is-dragging');

            if (event.dataTransfer) {
                event.dataTransfer.clearData();
                event.dataTransfer.effectAllowed = 'move';
                event.dataTransfer.setData('text/plain', card.dataset.cardIndex || '');
            }
        });

        container.addEventListener('dragend', function () {
            if (dragCard) {
                dragCard.classList.remove('is-dragging');
            }
            clearCardDropTargets();
            dragCard = null;
        });

        container.addEventListener('dragover', function (event) {
            var card = event.target.closest('.hub-card-item');
            if (!dragCard || !card || card === dragCard) {
                return;
            }

            event.preventDefault();
            clearCardDropTargets();
            card.classList.add('is-drop-target');

            if (event.dataTransfer) {
                event.dataTransfer.dropEffect = 'move';
            }
        });

        container.addEventListener('drop', function (event) {
            var card = event.target.closest('.hub-card-item');
            var nodes;
            var fromIndex;
            var toIndex;

            event.preventDefault();
            clearCardDropTargets();

            if (!dragCard || !card || card === dragCard) {
                dragCard = null;
                return;
            }

            nodes = cardNodes();
            fromIndex = nodes.indexOf(dragCard);
            toIndex = nodes.indexOf(card);
            dragCard = null;

            if (fromIndex < 0 || toIndex < 0 || fromIndex === toIndex) {
                return;
            }

            moveCard(fromIndex, toIndex);
        });

        cards = cards.map(function (card) {
            return normalizeCard(card);
        });
        featureCards = featureCards.map(function (card) {
            return normalizeFeatureCard(card);
        });
        migrateLegacyFeatureCardsIntoCards();
        applyStarterCardsIfNeeded(Boolean(siteConfig.isNew));
        setActiveLanguage('de');
        updateSlugPreview();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initializeHubSiteEditor, { once: true });
    } else {
        initializeHubSiteEditor();
    }
})();
