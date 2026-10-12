(function () {
    'use strict';

    var CMS_EDITOR_INLINE_INSERT_MAX_BLOCKS = 80;

    function parseJsonInput(id, fallback) {
        var input = getElement(id);

        if (!input || !input.value) {
            return fallback;
        }

        try {
            return JSON.parse(input.value);
        } catch (_error) {
            return fallback;
        }
    }

    function getElement(id) {
        return id ? document.getElementById(id) : null;
    }

    function queryElements(selector) {
        if (!selector) {
            return [];
        }

        try {
            return Array.prototype.slice.call(document.querySelectorAll(selector));
        } catch (_error) {
            return [];
        }
    }

    function logEditor(level, message, payload) {
        var method = level === 'warn' ? 'warn' : (level === 'error' ? 'error' : 'log');

        if (typeof console === 'undefined' || typeof console[method] !== 'function') {
            return;
        }

        if (typeof payload === 'undefined') {
            console[method]('[EditorJS] ' + String(message || ''));
            return;
        }

        console[method]('[EditorJS] ' + String(message || ''), payload);
    }

    function clearElement(element) {
        if (!element) {
            return;
        }

        while (element.firstChild) {
            element.removeChild(element.firstChild);
        }
    }

    function extractTextFromHtml(value) {
        var parser;
        var doc;

        if (typeof DOMParser === 'function') {
            try {
                parser = new DOMParser();
                doc = parser.parseFromString(String(value || ''), 'text/html');
                return String((doc && doc.body && doc.body.textContent) || '');
            } catch (_error) {
                // Fall back to a plain string below.
            }
        }

        return String(value || '');
    }

    function buildPreviewUrl(base, slug, template, placeholderSlug) {
        var sanitizedTemplate = String(template || '');
        var sanitizedBase = String(base || '').replace(/\/+$/, '');
        var sanitizedSlug = String(slug || '').trim().replace(/^\/+/, '');
        var fallbackSlug = String(placeholderSlug || 'beitrag').trim().replace(/^\/+/, '');

        if (sanitizedTemplate !== '' && sanitizedTemplate.indexOf('{slug}') !== -1) {
            return sanitizedTemplate.replace(/\{slug\}/g, sanitizedSlug !== '' ? sanitizedSlug : fallbackSlug);
        }

        return sanitizedSlug !== '' ? sanitizedBase + '/' + sanitizedSlug : sanitizedBase + '/';
    }

    function parseEditorContentLength(rawValue) {
        var normalized = String(rawValue || '').trim();
        var parsed;
        var blockText = '';

        if (normalized === '') {
            return 0;
        }

        try {
            parsed = JSON.parse(normalized);
        } catch (_error) {
            return extractTextFromHtml(normalized).trim().length;
        }

        if (!parsed || !Array.isArray(parsed.blocks)) {
            return extractTextFromHtml(normalized).trim().length;
        }

        parsed.blocks.forEach(function (block) {
            var data = block && block.data && typeof block.data === 'object' ? block.data : {};
            ['text', 'caption', 'title', 'message', 'code', 'html', 'content'].forEach(function (key) {
                if (typeof data[key] === 'string') {
                    blockText += ' ' + extractTextFromHtml(data[key]);
                }
            });
        });

        return blockText.trim().length;
    }

    function initLanguageTabCompleteness(editorConfig) {
        var editors = editorConfig && Array.isArray(editorConfig.editors) ? editorConfig.editors : [];
        var tabs = queryElements('[data-lang-tab]');
        var lengths = { de: 0, en: 0 };

        editors.forEach(function (definition) {
            var input = getElement(definition && definition.inputId ? definition.inputId : '');
            var key = definition && definition.key === 'en' ? 'en' : 'de';
            if (input) {
                lengths[key] = parseEditorContentLength(input.value);
            }
        });

        tabs.forEach(function (tab) {
            var locale = tab.getAttribute('data-lang-tab') === 'en' ? 'en' : 'de';
            var dot = tab.querySelector('.cms-lang-status-dot');
            if (!dot) {
                dot = document.createElement('span');
                dot.className = 'cms-lang-status-dot';
                dot.setAttribute('aria-hidden', 'true');
                tab.appendChild(dot);
            }
            dot.classList.toggle('is-complete', lengths[locale] > 0);
        });
    }

    function initUnsavedChangesGuard(uiConfig) {
        var form = getElement(uiConfig.formId);
        var title = queryElements(uiConfig.titleSelector || '.page-header .page-title')[0] || null;
        var backLink = getElement(uiConfig.backLinkId);
        var indicator;
        var modal;
        var modalCancel;
        var modalDiscard;
        var hasUnsavedChanges = false;
        var skipGuard = false;

        if (!form || !title) {
            return;
        }

        indicator = document.createElement('span');
        indicator.className = 'cms-unsaved-indicator';
        indicator.innerHTML = '<span class="cms-unsaved-indicator__dot" aria-hidden="true"></span><span>Ungespeicherte Änderungen</span>';
        title.insertBefore(indicator, title.firstChild);

        modal = document.createElement('div');
        modal.className = 'cms-unsaved-modal';
        modal.innerHTML = ''
            + '<div class="cms-unsaved-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="cmsUnsavedTitle">'
            + '<h3 id="cmsUnsavedTitle" class="cms-unsaved-modal__title">Änderungen verwerfen?</h3>'
            + '<div class="cms-unsaved-modal__actions">'
            + '<button type="button" class="btn btn-outline-secondary btn-sm" data-role="cancel">Abbrechen</button>'
            + '<button type="button" class="btn btn-danger btn-sm" data-role="discard">Verwerfen</button>'
            + '</div>'
            + '</div>';
        document.body.appendChild(modal);

        modalCancel = modal.querySelector('[data-role="cancel"]');
        modalDiscard = modal.querySelector('[data-role="discard"]');

        function renderIndicator() {
            indicator.classList.toggle('is-visible', hasUnsavedChanges);
        }

        function markUnsaved() {
            if (skipGuard) {
                return;
            }
            hasUnsavedChanges = true;
            renderIndicator();
        }

        function clearUnsaved() {
            hasUnsavedChanges = false;
            renderIndicator();
        }

        form.addEventListener('input', markUnsaved);
        form.addEventListener('change', markUnsaved);
        form.addEventListener('submit', function () {
            skipGuard = true;
            clearUnsaved();
        });

        if (backLink) {
            backLink.addEventListener('click', function (event) {
                if (!hasUnsavedChanges) {
                    return;
                }

                event.preventDefault();
                modal.classList.add('is-open');

                if (modalDiscard) {
                    modalDiscard.onclick = function () {
                        skipGuard = true;
                        window.location.href = backLink.href;
                    };
                }
            });
        }

        if (modalCancel) {
            modalCancel.addEventListener('click', function () {
                modal.classList.remove('is-open');
            });
        }

        modal.addEventListener('click', function (event) {
            if (event.target === modal) {
                modal.classList.remove('is-open');
            }
        });

        window.addEventListener('beforeunload', function (event) {
            if (!hasUnsavedChanges || skipGuard) {
                return;
            }
            event.preventDefault();
            event.returnValue = '';
        });
    }

    function enforceAccordionDefaults() {
        queryElements('.cms-collapsible-card').forEach(function (card) {
            if (card.classList.contains('cms-publication-card') || card.classList.contains('cms-collapsible-card--post-template')) {
                card.open = true;
                return;
            }
            card.open = false;
        });
    }

    function initUi(config) {
        var form = getElement(config.formId);
        var removeButton = getElement(config.removeButtonId);
        var imageInput = getElement(config.imageInputId);
        var tempPathInput = getElement(config.tempPathInputId);
        var previewContainer = getElement(config.previewContainerId);
        var emptyState = getElement(config.emptyStateId);
        var slugInput = getElement(config.slugInputId);
        var previewUrl = getElement(config.previewUrlId);
        var statusSelect = getElement(config.statusSelectId);
        var publishDateInput = getElement(config.publishDateId);
        var publishTimeInput = getElement(config.publishTimeId);
        var publishWarning = getElement(config.publishWarningId);
        var statusBadge = getElement(config.statusBadgeId);
        var categorySelect = getElement(config.categorySelectId);
        var categoryLabel = getElement(config.categoryLabelId);
        var templateSelect = getElement(config.templateSelectId);
        var templateMetaPanels = queryElements(config.templateMetaPanelSelector);
        var templateMetaPanelAttribute = config.templateMetaPanelAttribute || 'data-post-template-meta-panel';
        var templateMetaEmpty = queryElements(config.templateMetaEmptySelector)[0] || null;
        var toggleButtons = queryElements(config.languageToggleSelector);
        var languagePanes = queryElements(config.languagePaneSelector);
        var statusMap = config.statusMap || {};
        var countBindings = Array.isArray(config.countBindings) ? config.countBindings : [];

        if (!form) {
            return;
        }

        function resolvePublishDate() {
            var dateValue = publishDateInput ? String(publishDateInput.value || '').trim() : '';
            var timeValue = publishTimeInput ? String(publishTimeInput.value || '').trim() : '';

            if (dateValue === '') {
                return null;
            }

            return new Date(dateValue + 'T' + (timeValue !== '' ? timeValue : '00:00'));
        }

        function isScheduledPublication() {
            var publishAt;

            if (!statusSelect || statusSelect.value !== 'published') {
                return false;
            }

            publishAt = resolvePublishDate();
            return publishAt instanceof Date && !Number.isNaN(publishAt.getTime()) && publishAt.getTime() > Date.now();
        }

        function switchLanguage(lang) {
            languagePanes.forEach(function (pane) {
                pane.classList.toggle('d-none', pane.getAttribute(config.languagePaneAttribute || 'data-lang-pane') !== lang);
            });

            toggleButtons.forEach(function (button) {
                var isActive = button.getAttribute(config.languageAttribute || 'data-lang-toggle') === lang;
                button.classList.toggle('btn-primary', isActive);
                button.classList.toggle('btn-outline-primary', !isActive);
                button.setAttribute('aria-pressed', isActive ? 'true' : 'false');
            });
        }

        function updateUi() {
            var activeTemplate = templateSelect ? String(templateSelect.value || 'default') : 'default';
            var hasVisibleTemplateMetaPanel = false;

            countBindings.forEach(function (binding) {
                var source = getElement(binding.sourceId);
                var target = getElement(binding.targetId);
                if (source && target) {
                    target.textContent = String(source.value.length);
                }
            });

            if (previewUrl && slugInput) {
                previewUrl.textContent = buildPreviewUrl(config.previewBaseUrl || '', slugInput.value, config.previewUrlTemplate || '', config.previewPlaceholderSlug || 'beitrag');
            }

            if (statusSelect && statusBadge) {
                var resolvedStatusKey = isScheduledPublication() ? 'scheduled' : statusSelect.value;
                var currentStatus = statusMap[resolvedStatusKey] || statusMap.draft || null;
                if (currentStatus) {
                    statusBadge.className = currentStatus.className || statusBadge.className;
                    statusBadge.textContent = currentStatus.label || statusBadge.textContent;
                }
            }

            if (publishWarning) {
                if (isScheduledPublication()) {
                    publishWarning.textContent = 'Dieser Beitrag ist geplant und wird automatisch zum gewählten Termin veröffentlicht.';
                    publishWarning.classList.remove('d-none', 'alert-warning');
                    publishWarning.classList.add('alert-info');
                } else {
                    publishWarning.textContent = '';
                    publishWarning.classList.add('d-none');
                    publishWarning.classList.remove('alert-info');
                    publishWarning.classList.add('alert-warning');
                }
            }

            if (categorySelect && categoryLabel) {
                categoryLabel.textContent = categorySelect.options[categorySelect.selectedIndex]
                    ? categorySelect.options[categorySelect.selectedIndex].text
                    : 'Keine Kategorie';
            }

            templateMetaPanels.forEach(function (panel) {
                var isActive = String(panel.getAttribute(templateMetaPanelAttribute) || '') === activeTemplate;
                panel.hidden = !isActive;
                if ('disabled' in panel) {
                    panel.disabled = !isActive;
                }
                if (typeof panel.querySelectorAll === 'function') {
                    Array.prototype.forEach.call(panel.querySelectorAll('input, select, textarea'), function (field) {
                        field.disabled = !isActive;
                    });
                }
                if (isActive) {
                    hasVisibleTemplateMetaPanel = true;
                }
            });

            if (templateMetaEmpty) {
                templateMetaEmpty.hidden = hasVisibleTemplateMetaPanel;
            }
        }

        if (imageInput && imageInput.value && removeButton) {
            removeButton.classList.remove('d-none');
        }

        if (removeButton) {
            removeButton.addEventListener('click', function () {
                if (imageInput) {
                    imageInput.value = '';
                }
                if (tempPathInput) {
                    tempPathInput.value = '';
                }
                if (previewContainer) {
                    clearElement(previewContainer);
                    previewContainer.classList.add('d-none');
                }
                if (emptyState) {
                    emptyState.classList.remove('d-none');
                }
                removeButton.classList.add('d-none');
            });
        }

        countBindings.forEach(function (binding) {
            var source = getElement(binding.sourceId);
            if (!source) {
                return;
            }

            source.addEventListener('input', updateUi);
            source.addEventListener('change', updateUi);
        });

        if (statusSelect) {
            statusSelect.addEventListener('input', updateUi);
            statusSelect.addEventListener('change', updateUi);
        }

        if (publishDateInput) {
            publishDateInput.addEventListener('input', updateUi);
            publishDateInput.addEventListener('change', updateUi);
        }

        if (publishTimeInput) {
            publishTimeInput.addEventListener('input', updateUi);
            publishTimeInput.addEventListener('change', updateUi);
        }

        if (categorySelect) {
            categorySelect.addEventListener('input', updateUi);
            categorySelect.addEventListener('change', updateUi);
        }

        if (templateSelect) {
            templateSelect.addEventListener('input', updateUi);
            templateSelect.addEventListener('change', updateUi);
        }

        toggleButtons.forEach(function (button) {
            button.addEventListener('click', function () {
                switchLanguage(button.getAttribute(config.languageAttribute || 'data-lang-toggle') || config.defaultLanguage || 'de');
            });
        });

        updateUi();
        switchLanguage(config.defaultLanguage || 'de');
    }

    function initSeo(config) {
        if (!config || !window.cmsSeoEditor || typeof window.cmsSeoEditor.init !== 'function') {
            return;
        }

        window.cmsSeoEditor.init(config);
    }

    function initEditorJs(config) {
        var form;
        var editors = {};
        var editorDefinitions = {};
        var plainEditorStates = {};
        var submitLocked = false;
        var nativeSubmitPending = false;
        var pendingSubmitter = null;
        var translationPreviewActive = false;
        var translationPreviewSuppressClear = false;
        var pendingLazyBindings = {};
        var suppressInitialCopyForKeys = {};
        var editorMutationState = {};
        var editorUiState = {};
        var editorInitWatchdogs = {};
        var editorRuntimeRetryQueue = {};
        var EDITOR_INIT_WATCHDOG_MS = 5000;

        if (!config) {
            return;
        }

        form = getElement(config.formId);
        if (!form) {
            return;
        }

        function emitChangeEvents(element) {
            if (!element) {
                return;
            }

            element.dispatchEvent(new Event('input', { bubbles: true }));
            element.dispatchEvent(new Event('change', { bubbles: true }));
        }

        function logEditor(level, message, payload) {
            var method = level === 'warn' ? 'warn' : (level === 'error' ? 'error' : 'log');

            if (typeof console === 'undefined' || typeof console[method] !== 'function') {
                return;
            }

            if (typeof payload === 'undefined') {
                console[method]('[EditorJS] ' + String(message || ''));
                return;
            }

            console[method]('[EditorJS] ' + String(message || ''), payload);
        }

        function ensureEditorDebugChannel() {
            if (!window.cmsEditorDebug || typeof window.cmsEditorDebug !== 'object') {
                window.cmsEditorDebug = {};
            }
            if (!window.cmsEditorDebug.timestamps || typeof window.cmsEditorDebug.timestamps !== 'object') {
                window.cmsEditorDebug.timestamps = {};
            }
            if (!window.cmsEditorDebug.bindings || typeof window.cmsEditorDebug.bindings !== 'object') {
                window.cmsEditorDebug.bindings = {};
            }

            return window.cmsEditorDebug;
        }

        function getDefinitionDebugKey(definition) {
            if (!definition) {
                return 'unknown';
            }

            return String(definition.holderId || definition.key || definition.inputId || 'unknown');
        }

        function updateEditorDiagnosticView(definition, state, reason) {
            var holder = getElement(definition && definition.holderId ? definition.holderId : '');
            var diagnostics = queryElements('[data-cms-editor-diagnostic]');
            var holderId = definition && definition.holderId ? String(definition.holderId) : '';
            var nextState = String(state || 'loading');
            var nextReason = String(reason || '');
            var debug = ensureEditorDebugChannel();
            var coreReadyAt = debug.timestamps && debug.timestamps.coreReady ? String(debug.timestamps.coreReady) : '';
            var createAvailableAt = debug.timestamps && debug.timestamps.createCmsEditorAvailable ? String(debug.timestamps.createCmsEditorAvailable) : '';

            diagnostics.forEach(function (node) {
                var targetHolder = String(node.getAttribute('data-editor-holder') || '');
                if (targetHolder !== '' && holderId !== '' && targetHolder !== holderId) {
                    return;
                }

                node.setAttribute('data-editor-final-state', nextState);
                node.setAttribute('data-editor-final-reason', nextReason);
                node.setAttribute('data-editor-last-update', new Date().toISOString());
                node.setAttribute('data-editor-core-ready-at', coreReadyAt);
                node.setAttribute('data-editor-factory-ready-at', createAvailableAt);
                node.textContent = 'Editor-Diagnose: state=' + nextState
                    + (nextReason ? ' (' + nextReason + ')' : '')
                    + (coreReadyAt ? ' | core=' + coreReadyAt : '')
                    + (createAvailableAt ? ' | factory=' + createAvailableAt : '');
            });

            if (holder) {
                holder.setAttribute('data-editor-final-state', nextState);
                holder.setAttribute('data-editor-final-reason', nextReason);
                holder.setAttribute('data-editor-last-update', new Date().toISOString());
                holder.setAttribute('data-editor-core-ready-at', coreReadyAt);
                holder.setAttribute('data-editor-factory-ready-at', createAvailableAt);
            }
        }

        function recordEditorBindingMilestone(definition, stage, details) {
            var debug = ensureEditorDebugChannel();
            var key = getDefinitionDebugKey(definition);
            var bindingState = debug.bindings[key] || {};
            var timestampKey = String(stage || 'event');
            var timestamp = new Date().toISOString();
            var payload = details && typeof details === 'object' ? details : {};
            var holder = getElement(definition && definition.holderId ? definition.holderId : '');
            var wrap = holder && holder.closest ? holder.closest('.editorjs-wrap') : null;

            bindingState[timestampKey] = timestamp;
            bindingState[timestampKey + 'Details'] = payload;
            debug.bindings[key] = bindingState;

            if (holder) {
                holder.setAttribute('data-editor-last-stage', timestampKey);
                holder.setAttribute('data-editor-last-stage-at', timestamp);
                if (timestampKey === 'bindStart') {
                    holder.setAttribute('data-editor-bind-start-at', timestamp);
                } else if (timestampKey === 'bindEnd') {
                    holder.setAttribute('data-editor-bind-end-at', timestamp);
                }
            }
            if (wrap) {
                wrap.setAttribute('data-editor-last-stage', timestampKey);
                wrap.setAttribute('data-editor-last-stage-at', timestamp);
                if (timestampKey === 'bindStart') {
                    wrap.setAttribute('data-editor-bind-start-at', timestamp);
                } else if (timestampKey === 'bindEnd') {
                    wrap.setAttribute('data-editor-bind-end-at', timestamp);
                }
            }

            if (typeof console !== 'undefined' && typeof console.info === 'function') {
                console.info('[EditorJS][Debug] ' + key + ' ' + timestampKey + ' @ ' + timestamp, payload);
            }
        }

        function hasEditorFactory() {
            return typeof window.createCmsEditor === 'function';
        }

        function hasEditorCore() {
            return typeof window.EditorJS === 'function';
        }

        function hasEditorRuntime() {
            return hasEditorFactory() && hasEditorCore();
        }

        function waitForEditorRuntimeAvailability(maxWaitMs) {
            var readyPromise = window.cmsEditorJsCoreReady;
            var startedAt = Date.now();
            var waitLimit = typeof maxWaitMs === 'number' && maxWaitMs > 0 ? maxWaitMs : 7000;
            var pollDelay = 80;

            function pollRuntime() {
                if (hasEditorRuntime()) {
                    return Promise.resolve(true);
                }

                if (Date.now() - startedAt >= waitLimit) {
                    return Promise.resolve(false);
                }

                return new Promise(function (resolve) {
                    window.setTimeout(resolve, pollDelay);
                }).then(pollRuntime);
            }

            if (readyPromise && typeof readyPromise.then === 'function') {
                return Promise.resolve(readyPromise).catch(function () {
                    return false;
                }).then(function () {
                    return pollRuntime();
                });
            }

            return pollRuntime();
        }

        function setEditorStateMarker(definition, state, reason) {
            var holder = getElement(definition && definition.holderId ? definition.holderId : '');
            var wrap = holder && holder.closest ? holder.closest('.editorjs-wrap') : null;
            var input = getElement(definition && definition.inputId ? definition.inputId : '');
            var plainState = getPlainEditorState(definition, input);
            var resolvedState = state === 'editor' || state === 'fallback' ? state : 'loading';
            var resolvedReason = reason ? String(reason) : '';

            if (wrap) {
                wrap.setAttribute('data-editor-state', resolvedState);
                if (resolvedReason !== '') {
                    wrap.setAttribute('data-editor-state-reason', resolvedReason);
                } else {
                    wrap.removeAttribute('data-editor-state-reason');
                }
            }

            if (plainState && plainState.wrap) {
                plainState.wrap.setAttribute('data-editor-state', resolvedState);
                if (resolvedReason !== '') {
                    plainState.wrap.setAttribute('data-editor-state-reason', resolvedReason);
                } else {
                    plainState.wrap.removeAttribute('data-editor-state-reason');
                }
            }

            updateEditorDiagnosticView(definition, resolvedState, resolvedReason);
        }

        function clearHolderFallbackUi(definition) {
            var holder = getElement(definition && definition.holderId ? definition.holderId : '');
            var wrap = holder && holder.closest ? holder.closest('.editorjs-wrap') : null;

            if (!holder) {
                return;
            }

            holder.classList.remove('cms-editor-fallback-active');
            holder.removeAttribute('data-cms-editor-fallback-bound');

            Array.prototype.slice.call(holder.querySelectorAll('.cms-editor-fallback-warning')).forEach(function (warning) {
                if (warning && warning.parentNode === holder) {
                    warning.parentNode.removeChild(warning);
                }
            });
            Array.prototype.slice.call(holder.querySelectorAll('textarea[aria-label="EditorJS Fallback Text"]')).forEach(function (textarea) {
                if (textarea && textarea.parentNode === holder) {
                    textarea.parentNode.removeChild(textarea);
                }
            });

            if (wrap) {
                wrap.classList.remove('cms-editor-wrap-fallback-active');
            }
        }

        function createPlaintextFallbackData(value) {
            var normalizedValue = String(value || '').trim();

            if (normalizedValue === '') {
                return { blocks: [] };
            }

            return {
                time: Date.now(),
                version: 'cms-editor-fallback',
                blocks: [
                    {
                        type: 'paragraph',
                        data: { text: normalizedValue.replace(/\n/g, '<br>') }
                    }
                ]
            };
        }

        function extractFallbackTextareaValue(data) {
            var blocks = data && Array.isArray(data.blocks) ? data.blocks : [];
            var textParts = [];

            blocks.forEach(function (block) {
                var blockData = block && typeof block.data === 'object' ? block.data : {};
                var value = '';

                ['text', 'message', 'title', 'code', 'caption', 'content', 'html'].some(function (key) {
                    if (typeof blockData[key] !== 'string' || blockData[key].trim() === '') {
                        return false;
                    }

                    value = extractTextFromHtml(blockData[key]);
                    return true;
                });

                if (value.trim() !== '') {
                    textParts.push(value.trim());
                }
            });

            return textParts.join('\n\n');
        }

        function getPlainEditorState(definition, input) {
            var stateKey;
            var textarea;
            var wrap;
            var normalizedData;
            var fallbackText;

            if (!definition || !definition.plainTextareaId) {
                return null;
            }

            stateKey = String(definition.key || definition.holderId || definition.inputId || definition.plainTextareaId);
            if (plainEditorStates[stateKey]) {
                return plainEditorStates[stateKey];
            }

            textarea = getElement(definition.plainTextareaId);
            if (!textarea) {
                return null;
            }

            wrap = definition.plainWrapperId ? getElement(definition.plainWrapperId) : null;
            if (!wrap && textarea.closest) {
                wrap = textarea.closest('.cms-editor-plain-wrap');
            }

            normalizedData = normalizeEditorData(safeParseEditorInput(input));
            fallbackText = extractFallbackTextareaValue(normalizedData);
            if (!textarea.value && fallbackText) {
                textarea.value = fallbackText;
            }

            plainEditorStates[stateKey] = {
                key: stateKey,
                definition: definition,
                input: input || null,
                textarea: textarea,
                wrap: wrap || null,
                originalName: textarea.getAttribute('name') || ''
            };

            textarea.addEventListener('input', function () {
                syncPlainEditorState(plainEditorStates[stateKey], true);
            });

            return plainEditorStates[stateKey];
        }

        function syncPlainEditorState(state, shouldEmit) {
            if (!state || !state.input || !state.textarea) {
                return;
            }

            state.input.value = JSON.stringify(createPlaintextFallbackData(state.textarea.value));
            if (shouldEmit) {
                emitChangeEvents(state.input);
            }
        }

        function setPlainEditorEnhancedState(definition, isEnhanced) {
            var input = getElement(definition && definition.inputId ? definition.inputId : '');
            var state = getPlainEditorState(definition, input);

            if (!state || !state.textarea) {
                return;
            }

            if (isEnhanced) {
                if (state.wrap) {
                    state.wrap.classList.add('cms-editor-plain-wrap--enhanced');
                    state.wrap.setAttribute('hidden', 'hidden');
                    state.wrap.setAttribute('aria-hidden', 'true');
                    state.wrap.style.setProperty('display', 'none', 'important');
                    state.wrap.style.setProperty('visibility', 'hidden');
                    state.wrap.style.setProperty('opacity', '0');
                }
                state.textarea.disabled = true;
                state.textarea.removeAttribute('name');
                return;
            }

            if (state.wrap) {
                state.wrap.classList.remove('cms-editor-plain-wrap--enhanced');
                state.wrap.removeAttribute('hidden');
                state.wrap.removeAttribute('aria-hidden');
                state.wrap.style.setProperty('display', 'block', 'important');
                state.wrap.style.setProperty('visibility', 'visible');
                state.wrap.style.setProperty('opacity', '1');
            }
            state.textarea.disabled = false;
            if (state.originalName) {
                state.textarea.setAttribute('name', state.originalName);
            }
        }

        function syncAllPlainEditors() {
            Object.keys(editorDefinitions).forEach(function (definitionKey) {
                var definition = editorDefinitions[definitionKey];
                var input = definition ? getElement(definition.inputId) : null;
                var state = getPlainEditorState(definition, input);

                if (!state || !state.textarea || state.textarea.disabled) {
                    return;
                }

                if (state.wrap && (state.wrap.hidden || state.wrap.getAttribute('aria-hidden') === 'true')) {
                    return;
                }

                syncPlainEditorState(state, true);
            });
        }

        function suppressPlainEditorSubmitNames() {
            var restoreEntries = [];

            Object.keys(editorDefinitions).forEach(function (definitionKey) {
                var definition = editorDefinitions[definitionKey];
                var input = definition ? getElement(definition.inputId) : null;
                var state = getPlainEditorState(definition, input);
                var textarea;
                var currentName;
                var wasDisabled;

                if (!state || !state.textarea) {
                    return;
                }

                textarea = state.textarea;
                currentName = textarea.getAttribute('name') || '';
                wasDisabled = !!textarea.disabled;

                restoreEntries.push({
                    textarea: textarea,
                    name: currentName,
                    disabled: wasDisabled
                });

                textarea.disabled = true;
                textarea.removeAttribute('name');
            });

            return function restoreSuppressedNames() {
                restoreEntries.forEach(function (entry) {
                    if (!entry || !entry.textarea) {
                        return;
                    }

                    entry.textarea.disabled = entry.disabled;
                    if (entry.name) {
                        entry.textarea.setAttribute('name', entry.name);
                    } else {
                        entry.textarea.removeAttribute('name');
                    }
                });
            };
        }

        function renderEditorUnavailableFallback(definition, input, reason) {
            var holder = getElement(definition && definition.holderId ? definition.holderId : '');
            var wrap = holder && holder.closest ? holder.closest('.editorjs-wrap') : null;
            var pane = holder && holder.closest ? holder.closest('[data-lang-pane], .tab-pane') : null;
            var plainState = getPlainEditorState(definition, input);
            var warning;
            var textarea;
            var normalizedData;
            var fallbackText;
            var trigger;

            if (!holder || !input) {
                return;
            }

            setEditorStateMarker(definition, 'fallback', reason || 'fallback');
            recordEditorBindingMilestone(definition, 'bindEnd', { finalState: 'fallback', reason: String(reason || 'fallback') });
            setPlainEditorEnhancedState(definition, false);
            if (plainState) {
                warning = plainState.wrap ? plainState.wrap.querySelector('.cms-editor-plain-warning') : null;
                if (!warning && plainState.wrap) {
                    warning = document.createElement('div');
                    warning.className = 'alert alert-warning cms-editor-plain-warning';
                    plainState.wrap.insertBefore(warning, plainState.wrap.firstChild);
                }
                if (warning) {
                    warning.textContent = 'EditorJS konnte nicht initialisiert werden (' + String(reason || 'unknown') + '). Das Plain-Textfeld bleibt aktiv und wird beim Speichern übernommen.';
                }
                syncPlainEditorState(plainState, true);
                return;
            }

            if (holder.dataset.cmsEditorFallbackBound === '1' && holder.querySelector('textarea')) {
                return;
            }

            holder.dataset.cmsEditorFallbackBound = '1';
            holder.classList.add('cms-editor-fallback-active');
            holder.removeAttribute('hidden');
            holder.style.setProperty('display', 'block', 'important');
            holder.style.setProperty('min-height', holder.style.minHeight || '320px');
            holder.style.setProperty('opacity', '1');
            holder.style.setProperty('visibility', 'visible');
            if (wrap) {
                wrap.classList.add('cms-editor-wrap-fallback-active');
                wrap.removeAttribute('hidden');
                wrap.style.setProperty('display', 'block', 'important');
                wrap.style.setProperty('opacity', '1');
                wrap.style.setProperty('visibility', 'visible');
            }
            if (pane) {
                trigger = definition && definition.activateButtonId ? getElement(definition.activateButtonId) : null;
                if (!trigger || trigger.getAttribute('aria-pressed') === 'true') {
                    pane.classList.remove('d-none');
                    pane.removeAttribute('hidden');
                    pane.style.setProperty('display', 'block', 'important');
                    pane.style.setProperty('opacity', '1');
                    pane.style.setProperty('visibility', 'visible');
                }
            }
            clearElement(holder);

            warning = document.createElement('div');
            warning.className = 'alert alert-warning cms-editor-fallback-warning';
            warning.textContent = 'EditorJS konnte nicht initialisiert werden (' + String(reason || 'unknown') + '). Das Fallback-Textfeld bleibt aktiv und wird beim Speichern übernommen.';
            holder.appendChild(warning);

            textarea = document.createElement('textarea');
            textarea.className = 'form-control';
            textarea.rows = 14;
            textarea.setAttribute('aria-label', 'EditorJS Fallback Text');

            normalizedData = normalizeEditorData(safeParseEditorInput(input));
            fallbackText = extractFallbackTextareaValue(normalizedData);
            if (fallbackText === '') {
                fallbackText = extractTextFromHtml(String(input.value || ''));
            }
            textarea.value = fallbackText;

            textarea.addEventListener('input', function () {
                input.value = JSON.stringify(createPlaintextFallbackData(textarea.value));
                emitChangeEvents(input);
            });

            holder.appendChild(textarea);
            input.value = JSON.stringify(createPlaintextFallbackData(textarea.value));
            emitChangeEvents(input);
            logEditor('warn', 'Fallback editor activated for "' + String(definition && definition.holderId || 'unknown') + '" (' + String(reason || 'unknown') + ').');
        }

        function ensureHolderVisible(holder) {
            var wrap;
            var pane;

            if (!holder) {
                return;
            }

            wrap = holder.closest ? holder.closest('.editorjs-wrap') : null;
            pane = holder.closest ? holder.closest('[data-lang-pane], .tab-pane') : null;

            holder.removeAttribute('hidden');
            holder.style.setProperty('display', 'block', 'important');
            holder.style.setProperty('visibility', 'visible');
            holder.style.setProperty('opacity', '1');
            if (!holder.style.minHeight) {
                holder.style.minHeight = '320px';
            }

            if (wrap) {
                wrap.removeAttribute('hidden');
                wrap.style.setProperty('display', 'block', 'important');
                wrap.style.setProperty('visibility', 'visible');
                wrap.style.setProperty('opacity', '1');
            }

            if (pane && pane.classList.contains('d-none')) {
                pane.classList.remove('d-none');
                pane.removeAttribute('hidden');
                pane.style.setProperty('display', 'block', 'important');
                pane.style.setProperty('visibility', 'visible');
                pane.style.setProperty('opacity', '1');
            }
        }

        function waitForNextPaint() {
            return new Promise(function (resolve) {
                if (typeof window.requestAnimationFrame === 'function') {
                    window.requestAnimationFrame(function () {
                        window.requestAnimationFrame(resolve);
                    });
                    return;
                }

                window.setTimeout(resolve, 0);
            });
        }

        function resolveBlockLabel(blockElement) {
            if (!blockElement || !blockElement.querySelector) {
                return 'Block';
            }

            if (blockElement.querySelector('.ce-header')) { return 'H2'; }
            if (blockElement.querySelector('.ce-paragraph')) { return 'Text'; }
            if (blockElement.querySelector('.cdx-list')) { return 'Liste'; }
            if (blockElement.querySelector('.cdx-checklist')) { return 'Checklist'; }
            if (blockElement.querySelector('.cms-editorjs-tool--media-text')) { return 'Bild + Text'; }
            if (blockElement.querySelector('.image-tool, .cms-editorjs-tool--image')) { return 'Bild'; }
            if (blockElement.querySelector('.editorjs-link')) { return 'Link'; }
            if (blockElement.querySelector('.editorjs-embed')) { return 'Embed'; }
            if (blockElement.querySelector('.editorjs-details')) { return 'Details'; }
            if (blockElement.querySelector('.ce-delimiter')) { return 'Trenner'; }
            if (blockElement.querySelector('.editorjs-spacer-tool')) { return 'Abstand'; }
            if (blockElement.querySelector('.cdx-warning')) { return 'Callout'; }
            if (blockElement.querySelector('.ce-code')) { return 'Code'; }
            if (blockElement.querySelector('.tc-wrap')) { return 'Tabelle'; }
            if (blockElement.querySelector('.cdx-quote')) { return 'Zitat'; }
            if (blockElement.querySelector('.gg-box, .cms-editorjs-gallery')) { return 'Gallery'; }
            return 'Block';
        }

        function getBlockIndex(holder, blockElement) {
            var redactor = blockElement && blockElement.closest ? blockElement.closest('.codex-editor__redactor') : null;
            var scope = redactor || holder;
            var blocks = scope ? scope.querySelectorAll('.ce-block') : [];
            return Array.prototype.indexOf.call(blocks, blockElement);
        }

        function getEventBlock(holder, event) {
            var path = event && typeof event.composedPath === 'function' ? event.composedPath() : [];
            var activeElement = document.activeElement || null;
            var candidates = path.length > 0 ? path : [event && event.target ? event.target : null, activeElement];
            var block = null;

            candidates.some(function (candidate) {
                var candidateBlock;

                if (!candidate || !candidate.closest) {
                    return false;
                }

                candidateBlock = candidate.closest('.ce-block');
                if (!candidateBlock || (holder && !holder.contains(candidateBlock))) {
                    return false;
                }

                block = candidateBlock;
                return true;
            });

            if (!block && activeElement && activeElement.closest) {
                block = activeElement.closest('.ce-block');
                if (block && holder && !holder.contains(block)) {
                    block = null;
                }
            }

            return block;
        }

        function insertBlockAt(editorInstance, index, type, data) {
            var blockType = type || 'paragraph';
            var blockData = data || {};
            var hasExplicitIndex = typeof index === 'number' && index >= 0;

            if (!editorInstance || !editorInstance.blocks || typeof editorInstance.blocks.insert !== 'function') {
                return false;
            }

            try {
                editorInstance.blocks.insert(blockType, blockData, undefined, hasExplicitIndex ? index : undefined, true);
                return true;
            } catch (error) {
                if (hasExplicitIndex) {
                    logEditor('warn', 'EditorJS indexed block insert failed.', error);
                    return false;
                }

                try {
                    editorInstance.blocks.insert(blockType, blockData);
                    return true;
                } catch (fallbackError) {
                    logEditor('warn', 'EditorJS block insert failed.', fallbackError);
                    return false;
                }
            }
        }

        function getEditorAvailableTools(editorEntry) {
            return editorEntry
                && editorEntry.instance
                && Array.isArray(editorEntry.instance.cmsAvailableTools)
                ? editorEntry.instance.cmsAvailableTools
                : [];
        }

        function isEditorToolAvailable(editorEntry, blockType) {
            var availableTools = getEditorAvailableTools(editorEntry);

            return availableTools.length === 0 || availableTools.indexOf(blockType) !== -1;
        }

        function getEditorHistory(entry) {
            var undo = entry && entry.instance && entry.instance.cmsPlugins ? entry.instance.cmsPlugins.undo : null;

            return {
                undo: undo,
                canUndo: !!(undo && typeof undo.canUndo === 'function' && undo.canUndo()),
                canRedo: !!(undo && typeof undo.canRedo === 'function' && undo.canRedo())
            };
        }

        function getEditorBlockGroups() {
            return [
                {
                    label: 'Text',
                    className: 'basis',
                    buttons: [
                        { label: 'Überschrift', icon: 'H2', block: 'header', data: { level: 2 }, description: 'Abschnitt mit H2 starten' },
                        { label: 'Text', icon: '¶', block: 'paragraph', description: 'Normaler Absatz' },
                        { label: 'Liste', icon: '•', block: 'list', description: 'Aufzählung oder Nummerierung' },
                        { label: 'Checkliste', icon: '☑', block: 'list', data: { style: 'checklist', items: [{ content: '', meta: { checked: false }, items: [] }] }, description: 'Aufgabenliste' },
                        { label: 'Zitat', icon: '“”', block: 'quote', description: 'Zitat mit Quelle' }
                    ]
                },
                {
                    label: 'Medien',
                    className: 'media',
                    buttons: [
                        { label: 'Bild', icon: '▧', block: 'image', description: 'Bild hochladen oder URL nutzen' },
                        { label: 'Bild + Text', icon: '▣', block: 'mediaText', data: { imagePosition: 'left', imageWidth: '40' }, description: 'Bild links oder rechts neben Text' },
                        { label: 'Galerie', icon: '▦', block: 'imageGallery', description: 'Mehrere Bilder als Galerie' },
                        { label: 'Embed', icon: '▶', block: 'embed', description: 'YouTube/Vimeo/Codepen usw.' },
                        { label: 'Link-Karte', icon: '↗', block: 'linkTool', description: 'Link mit Vorschau' },
                        { label: 'Anhang', icon: '⎙', block: 'attaches', description: 'Download-Datei anhängen' }
                    ]
                },
                {
                    label: 'Layout & Spezial',
                    className: 'more',
                    buttons: [
                        { label: 'Tabelle', icon: '▦', block: 'table', description: 'Strukturierte Daten' },
                        { label: 'Hinweis', icon: '!', block: 'warning', description: 'Info-/Warnbox' },
                        { label: 'Alert', icon: '⚑', block: 'alert', data: { type: 'info', align: 'left' }, description: 'Klassische Alert-Box' },
                        { label: 'Akkordeon', icon: '⌄', block: 'accordion', description: 'Aufklappbarer Abschnitt' },
                        { label: 'Abstand', icon: '↕', block: 'spacer', data: { height: 40, preset: '40px' }, description: 'Vertikalen Weißraum einfügen' },
                        { label: 'Code', icon: '</>', block: 'code', description: 'Code-Beispiel' },
                        { label: 'HTML', icon: '{}', block: 'raw', description: 'Sanitizter HTML-Block' },
                        { label: 'Trenner', icon: '—', block: 'delimiter', description: 'Visueller Abschnittstrenner' }
                    ]
                }
            ];
        }

        function createEditorToolbarButton(item, editorEntry, options) {
            var button;
            var icon;
            var text;
            var insertOptions = options && typeof options === 'object' ? options : {};

            if (!isEditorToolAvailable(editorEntry, item.block)) {
                return null;
            }

            button = document.createElement('button');
            button.type = 'button';
            button.dataset.cmsEditorUi = 'true';
            button.dataset.mutationFree = 'true';
            button.dataset.block = item.block;

            icon = document.createElement('span');
            icon.className = 'cms-editor-toolbar-icon';
            icon.setAttribute('aria-hidden', 'true');
            icon.textContent = item.icon || '+';

            text = document.createElement('span');
            text.className = 'cms-editor-toolbar-label';
            text.textContent = item.label;

            button.appendChild(icon);
            button.appendChild(text);

            if (item.description) {
                button.title = item.description;
            }
            button.setAttribute('aria-label', item.description ? item.label + ' – ' + item.description : item.label);

            button.addEventListener('click', function (event) {
                var index;

                event.preventDefault();
                if (!editorEntry.instance || !editorEntry.instance.blocks) {
                    return;
                }

                index = typeof insertOptions.index === 'number'
                    ? insertOptions.index
                    : editorEntry.instance.blocks.getBlocksCount();
                insertBlockAt(editorEntry.instance, index, item.block, item.data || {});
                if (editorEntry.instance.caret && typeof editorEntry.instance.caret.setToBlock === 'function') {
                    editorEntry.instance.caret.setToBlock(Math.max(0, index), 'start');
                }
            });

            return button;
        }

        function closeInlineInserters(holder) {
            if (!holder) {
                return;
            }

            queryElements('#' + holder.id + ' .cms-editor-inline-inserter').forEach(function (panel) {
                panel.remove();
            });

            queryElements('#' + holder.id + ' .cms-editor-insert-between.is-open').forEach(function (button) {
                button.classList.remove('is-open');
                button.setAttribute('aria-expanded', 'false');
            });
        }

        function bindInlineInserterDismiss(holder) {
            var handleDocumentClick;
            var handleDocumentKeydown;

            if (!holder || holder.dataset.cmsInlineInserterDismissBound === '1') {
                return;
            }

            holder.dataset.cmsInlineInserterDismissBound = '1';
            handleDocumentClick = function (event) {
                var target = event.target;

                if (!holder.querySelector('.cms-editor-inline-inserter')) {
                    return;
                }

                if (target && target.closest && target.closest('.cms-editor-inline-inserter, .cms-editor-insert-between')) {
                    return;
                }

                closeInlineInserters(holder);
            };

            handleDocumentKeydown = function (event) {
                if (event.key === 'Escape') {
                    closeInlineInserters(holder);
                }
            };

            document.addEventListener('click', handleDocumentClick);
            document.addEventListener('keydown', handleDocumentKeydown);
            registerEditorUiCleanup(holder.id, function () {
                document.removeEventListener('click', handleDocumentClick);
                document.removeEventListener('keydown', handleDocumentKeydown);
                delete holder.dataset.cmsInlineInserterDismissBound;
            });
        }

        function buildInlineInserterPanel(holder, editorEntry, insertIndex) {
            var panel = document.createElement('div');
            var title = document.createElement('div');

            panel.className = 'cms-editor-inline-inserter';
            panel.dataset.cmsEditorUi = 'true';
            panel.dataset.mutationFree = 'true';
            panel.setAttribute('role', 'dialog');
            panel.setAttribute('aria-label', 'Blocktyp auswählen');

            title.className = 'cms-editor-inline-inserter__title';
            title.textContent = 'Was möchtest du einfügen?';
            panel.appendChild(title);

            getEditorBlockGroups().forEach(function (group) {
                var section = document.createElement('div');
                var groupTitle = document.createElement('div');
                var grid = document.createElement('div');
                var hasButtons = false;

                section.className = 'cms-editor-inline-inserter__section cms-editor-inline-inserter__section--' + group.className;
                groupTitle.className = 'cms-editor-inline-inserter__group-title';
                groupTitle.textContent = group.label;
                grid.className = 'cms-editor-inline-inserter__grid';

                group.buttons.forEach(function (item) {
                    var button = createEditorToolbarButton(item, editorEntry, { index: insertIndex });
                    var description;

                    if (!button) {
                        return;
                    }

                    button.className = 'cms-editor-inline-inserter__item';
                    button.addEventListener('click', function () {
                        closeInlineInserters(holder);
                    });

                    if (item.description) {
                        description = document.createElement('small');
                        description.textContent = item.description;
                        button.appendChild(description);
                    }

                    hasButtons = true;
                    grid.appendChild(button);
                });

                if (!hasButtons) {
                    return;
                }

                section.appendChild(groupTitle);
                section.appendChild(grid);
                panel.appendChild(section);
            });

            panel.addEventListener('click', function (event) {
                event.stopPropagation();
            });

            return panel;
        }

        function toggleInlineInserter(holder, insertButton, editorEntry, insertIndex) {
            var isOpen = insertButton.classList.contains('is-open');
            var panel;

            closeInlineInserters(holder);
            if (isOpen) {
                return;
            }

            panel = buildInlineInserterPanel(holder, editorEntry, insertIndex);
            insertButton.classList.add('is-open');
            insertButton.setAttribute('aria-expanded', 'true');
            insertButton.insertAdjacentElement('afterend', panel);
            bindInlineInserterDismiss(holder);

            window.setTimeout(function () {
                var firstButton = panel.querySelector('button');
                if (firstButton && typeof firstButton.focus === 'function') {
                    firstButton.focus({ preventScroll: true });
                }
            }, 0);
        }

        function setupImageHoverOverlay(holder, editorInstance) {
            if (holder && holder.classList.contains('cms-editor-large-document')) {
                queryElements('#' + holder.id + ' .cms-image-hover-overlay').forEach(function (overlay) {
                    overlay.remove();
                });
                return;
            }

            queryElements('#' + holder.id + ' .ce-block .image-tool').forEach(function (toolElement) {
                var block = toolElement.closest('.ce-block');
                var overlay = toolElement.querySelector('.cms-image-hover-overlay');
                var captionField = toolElement.querySelector('input.cdx-input, textarea.cdx-input');
                var removeButton;
                var altInput;

                if (!block) {
                    return;
                }

                if (!overlay) {
                    overlay = document.createElement('div');
                    overlay.className = 'cms-image-hover-overlay';
                    overlay.dataset.cmsEditorUi = 'true';
                    overlay.dataset.mutationFree = 'true';
                    overlay.innerHTML = ''
                        + '<input type="text" class="form-control form-control-sm" placeholder="Alt-Text eingeben...">'
                        + '<button type="button" class="btn btn-sm btn-danger" aria-label="Bild entfernen"><i class="ti ti-x"></i></button>';
                    toolElement.appendChild(overlay);
                }

                removeButton = overlay.querySelector('button');
                altInput = overlay.querySelector('input');

                if (captionField && altInput && altInput.value !== captionField.value) {
                    altInput.value = captionField.value || '';
                }

                if (captionField && altInput && altInput.dataset.cmsBound !== '1') {
                    altInput.dataset.cmsBound = '1';
                    altInput.addEventListener('input', function () {
                        captionField.value = altInput.value;
                        captionField.dispatchEvent(new Event('input', { bubbles: true }));
                        captionField.dispatchEvent(new Event('change', { bubbles: true }));
                    });
                }

                if (removeButton && removeButton.dataset.cmsBound !== '1') {
                    removeButton.dataset.cmsBound = '1';
                    removeButton.addEventListener('click', function (event) {
                        var index = getBlockIndex(holder, block);
                        event.preventDefault();
                        if (index < 0 || !editorInstance || !editorInstance.blocks || typeof editorInstance.blocks.delete !== 'function') {
                            return;
                        }
                        editorInstance.blocks.delete(index);
                    });
                }
            });
        }

        function renderEditorBlockUi(definition, editorEntry) {
            var holder = getElement(definition.holderId);
            var state = getEditorUiState(definition.holderId);
            var redactor;
            var blocks;
            var signature;
            var isLargeDocument;

            if (!holder || !editorEntry || !editorEntry.instance) {
                return;
            }

            if (state.rendering) {
                return;
            }

            redactor = holder.querySelector('.codex-editor__redactor') || holder;
            blocks = holder.querySelectorAll('.ce-block');
            isLargeDocument = blocks.length > CMS_EDITOR_INLINE_INSERT_MAX_BLOCKS;
            holder.classList.toggle('cms-editor-large-document', isLargeDocument);

            signature = Array.prototype.slice.call(blocks).map(function (block, index) {
                return [
                    block.getAttribute('data-id') || String(index),
                    resolveBlockLabel(block)
                ].join(':');
            }).join('|');

            if (isLargeDocument) {
                if (state.signature === signature
                        && holder.querySelectorAll('.cms-editor-insert-between').length === 0
                        && holder.querySelectorAll('.cms-editor-block-actions').length === 0) {
                    return;
                }

                state.rendering = true;

                try {
                    queryElements('#' + holder.id + ' .cms-editor-insert-between').forEach(function (button) {
                        button.remove();
                    });
                    queryElements('#' + holder.id + ' .cms-editor-block-actions').forEach(function (actions) {
                        actions.remove();
                    });
                    queryElements('#' + holder.id + ' .cms-image-hover-overlay').forEach(function (overlay) {
                        overlay.remove();
                    });
                    closeInlineInserters(holder);

                    blocks.forEach(function (block) {
                        block.classList.add('cms-editor-block-shell');
                        block.setAttribute('data-cms-block-label', resolveBlockLabel(block));
                    });

                    state.signature = signature;
                } finally {
                    state.rendering = false;
                }

                return;
            }

            if (state.signature === signature
                    && holder.querySelectorAll('.cms-editor-insert-between').length === Math.max(0, blocks.length - 1)
                    && holder.querySelectorAll('.cms-editor-block-actions').length === 0) {
                setupImageHoverOverlay(holder, editorEntry.instance);
                return;
            }

            state.rendering = true;

            try {
                queryElements('#' + holder.id + ' .cms-editor-insert-between').forEach(function (button) {
                    button.remove();
                });
                queryElements('#' + holder.id + ' .cms-editor-block-actions').forEach(function (actions) {
                    actions.remove();
                });
                closeInlineInserters(holder);

                blocks.forEach(function (block, index) {
                    var nextBlock = blocks[index + 1];
                    var insertButton;

                    block.classList.add('cms-editor-block-shell');
                    block.setAttribute('data-cms-block-label', resolveBlockLabel(block));

                    if (!nextBlock) {
                        return;
                    }

                    insertButton = document.createElement('button');
                    insertButton.type = 'button';
                    insertButton.className = 'cms-editor-insert-between';
                    insertButton.dataset.cmsEditorUi = 'true';
                    insertButton.dataset.mutationFree = 'true';
                    insertButton.setAttribute('aria-label', 'Block hier einfügen');
                    insertButton.setAttribute('aria-haspopup', 'dialog');
                    insertButton.setAttribute('aria-expanded', 'false');
                    insertButton.textContent = '+';
                    insertButton.addEventListener('click', function (event) {
                        event.preventDefault();
                        event.stopPropagation();
                        toggleInlineInserter(holder, insertButton, editorEntry, index + 1);
                    });
                    redactor.insertBefore(insertButton, nextBlock);
                });

                setupImageHoverOverlay(holder, editorEntry.instance);
                state.signature = signature;
            } finally {
                state.rendering = false;
            }
        }

        function getEditorUiState(holderId) {
            if (!editorUiState[holderId] || editorUiState[holderId] === true) {
                editorUiState[holderId] = {
                    observed: false,
                    observer: null,
                    rendering: false,
                    scheduled: false,
                    signature: '',
                    cleanups: []
                };
            }

            return editorUiState[holderId];
        }

        function registerEditorUiCleanup(holderId, cleanup) {
            var state;

            if (!holderId || typeof cleanup !== 'function') {
                return;
            }

            state = getEditorUiState(holderId);
            if (!Array.isArray(state.cleanups)) {
                state.cleanups = [];
            }

            state.cleanups.push(cleanup);
        }

        function cleanupEditorUi(holderId) {
            var state = holderId ? editorUiState[holderId] : null;
            var holder = holderId ? getElement(holderId) : null;

            if (!state || state === true) {
                return;
            }

            if (state.observer && typeof state.observer.disconnect === 'function') {
                state.observer.disconnect();
            }

            (Array.isArray(state.cleanups) ? state.cleanups : []).forEach(function (cleanup) {
                try {
                    cleanup();
                } catch (error) {
                    logEditor('warn', 'EditorJS UI cleanup failed.', error);
                }
            });

            if (holder) {
                delete holder.dataset.cmsSelectionBubbleBound;
                delete holder.dataset.cmsInlineInserterDismissBound;
                delete holder.dataset.cmsClipboardImagePasteBound;
                delete holder.dataset.cmsKeyboardDeleteBound;
                delete holder.dataset.cmsKeyboardEnterBound;
                closeInlineInserters(holder);
            }

            delete editorUiState[holderId];
        }

        function isEditorUiNode(node) {
            var element = node && node.nodeType === 1 ? node : (node && node.parentElement ? node.parentElement : null);

            if (!element || typeof element.matches !== 'function') {
                return false;
            }

            return element.matches('[data-cms-editor-ui="true"], .cms-editor-insert-between, .cms-image-hover-overlay')
                || !!element.querySelector('[data-cms-editor-ui="true"], .cms-editor-insert-between, .cms-image-hover-overlay');
        }

        function isOnlyEditorUiMutation(mutations) {
            return Array.prototype.slice.call(mutations || []).every(function (mutation) {
                var nodes = Array.prototype.slice.call(mutation.addedNodes || [])
                    .concat(Array.prototype.slice.call(mutation.removedNodes || []));

                return nodes.length > 0 && nodes.every(isEditorUiNode);
            });
        }

        function ensureEditorCommandbar(definition, editorEntry) {
            var holder = getElement(definition.holderId);
            var wrap = holder ? holder.closest('.editorjs-wrap') : null;
            var commandbar;
            var inserterButton;
            var undoButton;
            var redoButton;
            var expandButton;
            var pluginBadge;
            var panel;

            if (!holder || !wrap || wrap.querySelector('.cms-editor-commandbar')) {
                return;
            }

            commandbar = document.createElement('div');
            commandbar.className = 'cms-editor-commandbar';
            commandbar.dataset.cmsEditorUi = 'true';
            commandbar.dataset.mutationFree = 'true';
            commandbar.setAttribute('role', 'toolbar');
            commandbar.setAttribute('aria-label', 'EditorJS Werkzeuge');

            commandbar.innerHTML = ''
                + '<div class="cms-editor-commandbar__left">'
                + '<button type="button" class="cms-editor-commandbar__primary" data-role="inserter" aria-expanded="false">+ Block hinzufügen</button>'
                + '<div class="cms-editor-commandbar__title"><span>EditorJS</span><strong>Block-Editor</strong></div>'
                + '</div>'
                + '<div class="cms-editor-commandbar__right">'
                + '<span class="cms-editor-commandbar__badge" data-role="plugin-badge">Plugins werden geladen …</span>'
                + '<button type="button" class="cms-editor-commandbar__icon" data-role="undo" title="Rückgängig" disabled>↶</button>'
                + '<button type="button" class="cms-editor-commandbar__icon" data-role="redo" title="Wiederholen" disabled>↷</button>'
                + '<button type="button" class="cms-editor-commandbar__mode" data-role="expand" aria-pressed="false">Breit</button>'
                + '</div>';

            panel = document.createElement('div');
            panel.className = 'cms-editor-inserter-panel';
            panel.dataset.cmsEditorUi = 'true';
            panel.dataset.mutationFree = 'true';
            panel.setAttribute('hidden', 'hidden');

            getEditorBlockGroups().forEach(function (group) {
                var section = document.createElement('div');
                var title = document.createElement('div');
                var grid = document.createElement('div');
                var hasButtons = false;

                section.className = 'cms-editor-inserter-panel__section cms-editor-inserter-panel__section--' + group.className;
                title.className = 'cms-editor-inserter-panel__title';
                title.textContent = group.label;
                grid.className = 'cms-editor-inserter-panel__grid';

                group.buttons.forEach(function (item) {
                    var button = createEditorToolbarButton(item, editorEntry);
                    var description;

                    if (!button) {
                        return;
                    }

                    button.className = 'cms-editor-inserter-card';
                    if (item.description) {
                        description = document.createElement('small');
                        description.textContent = item.description;
                        button.appendChild(description);
                    }
                    hasButtons = true;
                    grid.appendChild(button);
                });

                if (!hasButtons) {
                    return;
                }

                section.appendChild(title);
                section.appendChild(grid);
                panel.appendChild(section);
            });

            inserterButton = commandbar.querySelector('[data-role="inserter"]');
            undoButton = commandbar.querySelector('[data-role="undo"]');
            redoButton = commandbar.querySelector('[data-role="redo"]');
            expandButton = commandbar.querySelector('[data-role="expand"]');
            pluginBadge = commandbar.querySelector('[data-role="plugin-badge"]');

            function setPanelOpen(open) {
                panel.toggleAttribute('hidden', !open);
                panel.classList.toggle('is-open', open);
                if (inserterButton) {
                    inserterButton.setAttribute('aria-expanded', open ? 'true' : 'false');
                }
            }

            function updateCommandbarState() {
                var history = getEditorHistory(editorEntry);
                var plugins = editorEntry.instance && editorEntry.instance.cmsPlugins ? Object.keys(editorEntry.instance.cmsPlugins) : [];

                if (undoButton) {
                    undoButton.disabled = !history.canUndo;
                }
                if (redoButton) {
                    redoButton.disabled = !history.canRedo;
                }
                if (pluginBadge) {
                    pluginBadge.textContent = plugins.length > 0 ? plugins.join(' · ') : 'Basis-Tools aktiv';
                }
            }

            if (inserterButton) {
                inserterButton.addEventListener('click', function (event) {
                    event.preventDefault();
                    setPanelOpen(panel.hasAttribute('hidden'));
                });
            }

            if (undoButton) {
                undoButton.addEventListener('click', function (event) {
                    var history = getEditorHistory(editorEntry);
                    event.preventDefault();
                    if (history.undo && typeof history.undo.undo === 'function') {
                        history.undo.undo();
                    }
                });
            }

            if (redoButton) {
                redoButton.addEventListener('click', function (event) {
                    var history = getEditorHistory(editorEntry);
                    event.preventDefault();
                    if (history.undo && typeof history.undo.redo === 'function') {
                        history.undo.redo();
                    }
                });
            }

            if (expandButton) {
                expandButton.addEventListener('click', function (event) {
                    var isExpanded;
                    event.preventDefault();
                    isExpanded = !wrap.classList.contains('editorjs-wrap--expanded');
                    wrap.classList.toggle('editorjs-wrap--expanded', isExpanded);
                    expandButton.setAttribute('aria-pressed', isExpanded ? 'true' : 'false');
                    expandButton.textContent = isExpanded ? 'Normal' : 'Breit';
                });
            }

            holder.addEventListener('cms-editor-plugin-state', updateCommandbarState);
            var handleDocumentClick = function (event) {
                if (!wrap.contains(event.target)) {
                    setPanelOpen(false);
                }
            };
            document.addEventListener('click', handleDocumentClick);
            registerEditorUiCleanup(definition.holderId, function () {
                holder.removeEventListener('cms-editor-plugin-state', updateCommandbarState);
                document.removeEventListener('click', handleDocumentClick);
                if (commandbar.parentNode) {
                    commandbar.parentNode.removeChild(commandbar);
                }
            });

            commandbar.appendChild(panel);
            wrap.insertBefore(commandbar, wrap.firstChild || null);
            updateCommandbarState();
        }

        function ensureGroupedToolbar(definition, editorEntry) {
            var holder = getElement(definition.holderId);
            var wrap = holder ? holder.closest('.editorjs-wrap') : null;
            var toolbar;
            var overflowButton;
            var overflowPanel;
            var groups;

            if (!holder || !wrap || wrap.querySelector('.editorjs-toolbar.cms-editor-toolbar-grouped')) {
                return;
            }

            groups = getEditorBlockGroups();

            toolbar = document.createElement('div');
            toolbar.className = 'editorjs-toolbar cms-editor-toolbar-grouped';
            toolbar.dataset.cmsEditorUi = 'true';
            toolbar.dataset.mutationFree = 'true';
            toolbar.setAttribute('role', 'toolbar');
            toolbar.setAttribute('aria-label', 'EditorJS Schnellwerkzeuge');

            function buildButton(item) {
                return createEditorToolbarButton(item, editorEntry);
            }

            groups.slice(0, 2).forEach(function (group, groupIndex) {
                var groupEl = document.createElement('div');
                var hasButtons = false;
                groupEl.className = 'cms-editor-toolbar-group cms-editor-toolbar-group--' + group.className;
                group.buttons.forEach(function (item) {
                    var button = buildButton(item);
                    if (!button) {
                        return;
                    }
                    hasButtons = true;
                    groupEl.appendChild(button);
                });
                if (!hasButtons) {
                    return;
                }
                toolbar.appendChild(groupEl);
                if (groupIndex < 1) {
                    toolbar.appendChild(document.createElement('span')).className = 'cms-editor-toolbar-divider';
                }
            });

            overflowButton = document.createElement('button');
            overflowButton.type = 'button';
            overflowButton.className = 'cms-editor-toolbar-overflow';
            overflowButton.textContent = '···';
            overflowPanel = document.createElement('div');
            overflowPanel.className = 'cms-editor-toolbar-overflow-panel';
            groups.slice(2).forEach(function (group) {
                if (!group || !Array.isArray(group.buttons)) {
                    return;
                }

                group.buttons.forEach(function (item) {
                    var button = buildButton(item);
                    if (button) {
                        overflowPanel.appendChild(button);
                    }
                });
            });

            if (overflowPanel.children.length > 0) {
                var handleOverflowDocumentClick;

                overflowButton.setAttribute('aria-label', 'Weitere EditorJS-Blöcke anzeigen');
                overflowButton.setAttribute('aria-expanded', 'false');
                overflowButton.addEventListener('click', function (event) {
                    event.preventDefault();
                    overflowPanel.classList.toggle('is-open');
                    overflowButton.setAttribute('aria-expanded', overflowPanel.classList.contains('is-open') ? 'true' : 'false');
                });
                handleOverflowDocumentClick = function (event) {
                    if (!toolbar.contains(event.target)) {
                        overflowPanel.classList.remove('is-open');
                        overflowButton.setAttribute('aria-expanded', 'false');
                    }
                };
                document.addEventListener('click', handleOverflowDocumentClick);
                registerEditorUiCleanup(definition.holderId, function () {
                    document.removeEventListener('click', handleOverflowDocumentClick);
                    if (toolbar.parentNode) {
                        toolbar.parentNode.removeChild(toolbar);
                    }
                });

                toolbar.appendChild(document.createElement('span')).className = 'cms-editor-toolbar-divider';
                toolbar.appendChild(overflowButton);
                toolbar.appendChild(overflowPanel);
            }
            wrap.insertBefore(toolbar, wrap.querySelector('.editorjs-holder') || wrap.firstChild || null);
            registerEditorUiCleanup(definition.holderId, function () {
                if (toolbar.parentNode) {
                    toolbar.parentNode.removeChild(toolbar);
                }
            });
        }

        function ensureEditorUi(definition, editorEntry) {
            var holder = getElement(definition.holderId);
            var state;
            if (!holder || !editorEntry || !editorEntry.instance) {
                return;
            }

            state = getEditorUiState(definition.holderId);

            ensureEditorCommandbar(definition, editorEntry);
            ensureGroupedToolbar(definition, editorEntry);
            bindClipboardImagePaste(definition, editorEntry);
            bindKeyboardBlockDelete(definition, editorEntry);
            bindKeyboardBlockEnter(definition, editorEntry);
            bindTextSelectionBubble(definition, editorEntry);
            renderEditorBlockUi(definition, editorEntry);

            if (!state.observed) {
                var observer;

                state.observed = true;
                observer = new MutationObserver(function (mutations) {
                    if (state.rendering || isOnlyEditorUiMutation(mutations)) {
                        return;
                    }

                    if (state.scheduled) {
                        return;
                    }

                    state.scheduled = true;
                    window.requestAnimationFrame(function () {
                        state.scheduled = false;
                        renderEditorBlockUi(definition, editorEntry);
                    });
                });
                observer.observe(holder, { childList: true, subtree: true });
                state.observer = observer;
            }
        }

        function trackPendingLazyBinding(key, promise) {
            var trackedPromise;

            if (!key || !promise || typeof promise.then !== 'function') {
                return promise;
            }

            trackedPromise = Promise.resolve(promise).then(function (result) {
                if (pendingLazyBindings[key] === trackedPromise) {
                    delete pendingLazyBindings[key];
                }

                return result;
            }, function (error) {
                if (pendingLazyBindings[key] === trackedPromise) {
                    delete pendingLazyBindings[key];
                }

                throw error;
            });

            pendingLazyBindings[key] = trackedPromise;
            return trackedPromise;
        }

        function waitForPendingLazyBinding(key) {
            return key && pendingLazyBindings[key]
                ? pendingLazyBindings[key]
                : Promise.resolve();
        }

        function markEditorMutation(key) {
            var state;

            if (!key) {
                return;
            }

            state = editorMutationState[key] || {
                lastMutationAt: 0,
                settlePromise: null,
                settleTimerId: null
            };

            state.lastMutationAt = Date.now();
            editorMutationState[key] = state;
        }

        function waitForEditorMutationSettle(key) {
            var state = key ? editorMutationState[key] : null;
            var settleDelay = 260;

            if (!state || !state.lastMutationAt) {
                return Promise.resolve();
            }

            if (state.settleTimerId !== null) {
                window.clearTimeout(state.settleTimerId);
                state.settleTimerId = null;
            }

            state.settlePromise = new Promise(function (resolve) {
                var complete = function () {
                    state.settleTimerId = null;
                    state.settlePromise = null;
                    resolve();
                };

                var schedule = function () {
                    var remaining = settleDelay - (Date.now() - state.lastMutationAt);

                    if (remaining <= 0) {
                        complete();
                        return;
                    }

                    state.settleTimerId = window.setTimeout(function () {
                        schedule();
                    }, remaining);
                };

                schedule();
            });

            return state.settlePromise;
        }

        function registerEditorMutationTracking(key, holder) {
            var cleanupHandlers = [];

            if (!key || !holder || holder.dataset.cmsEditorMutationTracked === '1') {
                return;
            }

            ['beforeinput', 'input', 'change', 'paste', 'drop', 'cut', 'keyup', 'compositionend', 'cms-editorjs-tool-change'].forEach(function (eventName) {
                var handler = function () {
                    markEditorMutation(key);
                };

                holder.addEventListener(eventName, handler, true);
                cleanupHandlers.push(function () {
                    holder.removeEventListener(eventName, handler, true);
                });
            });

            holder.dataset.cmsEditorMutationTracked = '1';
            registerEditorUiCleanup(holder.id, function () {
                cleanupHandlers.forEach(function (cleanup) {
                    cleanup();
                });
                delete holder.dataset.cmsEditorMutationTracked;
            });
        }

        function safeParseEditorInput(input) {
            if (!input || !input.value) {
                return { blocks: [] };
            }

            try {
                return JSON.parse(input.value);
            } catch (_error) {
                return normalizeEditorData(String(input.value || ''));
            }
        }

        function normalizeEditorData(data) {
            if (typeof window.cmsNormalizeEditorJsData === 'function') {
                try {
                    return window.cmsNormalizeEditorJsData(data);
                } catch (_error) {
                    return data || { blocks: [] };
                }
            }

            return data || { blocks: [] };
        }

        function getDefinition(key) {
            return key && editorDefinitions[key] ? editorDefinitions[key] : null;
        }

        function getNamedFormField(name) {
            var namedItem;

            if (!name || !form || !form.elements || typeof form.elements.namedItem !== 'function') {
                return null;
            }

            namedItem = form.elements.namedItem(name);

            if (!namedItem) {
                return null;
            }

            if (typeof namedItem.length === 'number' && !namedItem.tagName) {
                return namedItem[0] || null;
            }

            return namedItem;
        }

        function resolveFormField(fieldId, fieldName) {
            return getElement(fieldId) || getNamedFormField(fieldName);
        }

        function getLegacyEditorRegistry() {
            return window.cmsLegacyEditors && typeof window.cmsLegacyEditors === 'object'
                ? window.cmsLegacyEditors
                : null;
        }

        function getLegacyEditorInstance(fieldId, fieldName) {
            var field = resolveFormField(fieldId, fieldName);
            var registry = getLegacyEditorRegistry();

            if (!registry || !field) {
                return null;
            }

            if (field.id && registry.byId && registry.byId[field.id]) {
                return registry.byId[field.id];
            }

            if (field.name && registry.byName && registry.byName[field.name]) {
                return registry.byName[field.name];
            }

            return null;
        }

        function getFieldValue(id) {
            var element = getElement(id);
            return element ? String(element.value || '') : '';
        }

        function setFieldValue(id, value) {
            var element = getElement(id);
            if (!element) {
                return;
            }

            element.value = String(value || '');
            emitChangeEvents(element);
        }

        function readContentFieldValue(fieldId, fieldName) {
            var field = resolveFormField(fieldId, fieldName);
            var legacyEditor = getLegacyEditorInstance(fieldId, fieldName);
            var value = '';

            if (legacyEditor && typeof legacyEditor.getContents === 'function') {
                try {
                    value = String(legacyEditor.getContents() || '');
                } catch (_error) {
                    value = '';
                }
            }

            if (value === '' && field) {
                value = String(field.value || '');
            }

            if (field) {
                field.value = value;
                emitChangeEvents(field);
            }

            return value;
        }

        function writeContentFieldValue(fieldId, fieldName, value) {
            var field = resolveFormField(fieldId, fieldName);
            var legacyEditor = getLegacyEditorInstance(fieldId, fieldName);
            var normalizedValue = String(value || '');

            if (legacyEditor && typeof legacyEditor.setContents === 'function') {
                try {
                    legacyEditor.setContents(normalizedValue);
                } catch (_error) {
                    // Fall back to the raw field update below.
                }
            }

            if (!field) {
                return;
            }

            field.value = normalizedValue;
            emitChangeEvents(field);
        }

        function readEditorJsSourceData(fieldId, fieldName) {
            var field = resolveFormField(fieldId, fieldName);

            return normalizeEditorData(safeParseEditorInput(field));
        }

        function readUploadContextField(fieldId) {
            var element = getElement(fieldId);

            return element ? String(element.value || '').trim() : '';
        }

        function buildUploadContext() {
            var uploadContext = config.uploadContext && typeof config.uploadContext === 'object'
                ? config.uploadContext
                : {};

            return {
                contentType: String(uploadContext.contentType || ''),
                isNew: !!uploadContext.isNew,
                draftKey: String(uploadContext.draftKey || ''),
                contentSlug: readUploadContextField(uploadContext.slugInputId),
                contentSlugFallback: readUploadContextField(uploadContext.slugFallbackInputId),
                contentTitle: readUploadContextField(uploadContext.titleInputId),
                contentTitleFallback: readUploadContextField(uploadContext.titleFallbackInputId)
            };
        }

        function buildEditorMediaUrl(action) {
            var baseUrl = String(config.mediaUploadUrl || '/api/media');
            var separator = baseUrl.indexOf('?') === -1 ? '?' : '&';

            return baseUrl + separator + 'action=' + encodeURIComponent(action);
        }

        function isValidClipboardImageFile(file) {
            var maxSize = 25 * 1024 * 1024;
            var mimeType = file ? String(file.type || '') : '';
            var fileName = file ? String(file.name || '') : '';
            var hasAllowedMime = /^image\/(?:jpeg|jpg|png|gif|webp|bmp|x-icon|vnd\.microsoft\.icon)$/i.test(mimeType);
            var hasAllowedExtension = /\.(?:jpe?g|png|gif|webp|bmp|ico)$/i.test(fileName);

            return !!(file
                && (hasAllowedMime || hasAllowedExtension)
                && Number(file.size || 0) <= maxSize);
        }

        function collectClipboardImageFiles(event) {
            var clipboardData = event && event.clipboardData ? event.clipboardData : null;
            var files = [];

            if (!clipboardData) {
                return files;
            }

            Array.prototype.slice.call(clipboardData.items || []).forEach(function (item) {
                var file;

                if (!item || String(item.kind || '') !== 'file' || !/^image\//i.test(String(item.type || ''))) {
                    return;
                }

                file = typeof item.getAsFile === 'function' ? item.getAsFile() : null;
                if (isValidClipboardImageFile(file)) {
                    files.push(file);
                }
            });

            if (files.length === 0) {
                Array.prototype.slice.call(clipboardData.files || []).forEach(function (file) {
                    if (isValidClipboardImageFile(file)) {
                        files.push(file);
                    }
                });
            }

            return files;
        }

        function appendClipboardUploadContext(formData) {
            var uploadContext = buildUploadContext();

            if (uploadContext.contentType) {
                formData.append('content_type', uploadContext.contentType);
            }
            if (uploadContext.draftKey) {
                formData.append('draft_key', uploadContext.draftKey);
            }
            if (uploadContext.contentSlug) {
                formData.append('content_slug', uploadContext.contentSlug);
            }
            if (uploadContext.contentSlugFallback) {
                formData.append('content_slug_fallback', uploadContext.contentSlugFallback);
            }
            if (uploadContext.contentTitle) {
                formData.append('content_title', uploadContext.contentTitle);
            }
            if (uploadContext.contentTitleFallback) {
                formData.append('content_title_fallback', uploadContext.contentTitleFallback);
            }
        }

        function uploadClipboardImage(file, index) {
            var body = new FormData();
            var fallbackName = 'clipboard-image-' + String(index + 1) + '.png';

            body.append('image', file, file && file.name ? file.name : fallbackName);
            body.append('csrf_token', String(config.csrfToken || ''));
            appendClipboardUploadContext(body);

            return fetch(buildEditorMediaUrl('upload_image'), {
                method: 'POST',
                body: body,
                credentials: 'same-origin',
                headers: config.csrfToken ? { 'X-CSRF-Token': String(config.csrfToken) } : {}
            }).then(function (response) {
                return response.text().then(function (text) {
                    var payload = {};

                    if (String(text || '').trim() !== '') {
                        try {
                            payload = JSON.parse(text);
                        } catch (_error) {
                            throw new Error('Upload-Antwort war kein gültiges JSON.');
                        }
                    }

                    if (!response.ok) {
                        throw new Error(String(payload && payload.message ? payload.message : 'Bild aus der Zwischenablage konnte nicht hochgeladen werden.'));
                    }

                    return payload;
                });
            }).then(function (payload) {
                var filePayload = payload && payload.file && typeof payload.file === 'object' ? payload.file : {};
                var url = String(filePayload.url || payload && payload.url || '');

                if (!payload || !payload.success || url === '') {
                    throw new Error(String(payload && payload.message ? payload.message : 'Bild aus der Zwischenablage konnte nicht hochgeladen werden.'));
                }

                return Object.assign({}, filePayload, { url: url });
            });
        }

        function getClipboardInsertIndex(holder, event, editorInstance) {
            var target = getEventBlock(holder, event);
            var blockIndex = target ? getBlockIndex(holder, target) : -1;
            var currentIndex;

            if (blockIndex >= 0) {
                return blockIndex + 1;
            }

            if (editorInstance && editorInstance.blocks && typeof editorInstance.blocks.getCurrentBlockIndex === 'function') {
                try {
                    currentIndex = editorInstance.blocks.getCurrentBlockIndex();
                    if (typeof currentIndex === 'number' && currentIndex >= 0) {
                        return currentIndex + 1;
                    }
                } catch (_error) {
                    // Fall back to append below.
                }
            }

            return editorInstance && editorInstance.blocks && typeof editorInstance.blocks.getBlocksCount === 'function'
                ? editorInstance.blocks.getBlocksCount()
                : 0;
        }

        function bindClipboardImagePaste(definition, editorEntry) {
            var holder = getElement(definition.holderId);
            var handlePaste;

            if (!holder || !editorEntry || !editorEntry.instance || holder.dataset.cmsClipboardImagePasteBound === '1') {
                return;
            }

            holder.dataset.cmsClipboardImagePasteBound = '1';
            handlePaste = function (event) {
                var files = collectClipboardImageFiles(event);
                var insertIndex;
                var queue;

                if (files.length === 0 || !isEditorToolAvailable(editorEntry, 'image')) {
                    return;
                }

                event.preventDefault();
                event.stopPropagation();

                insertIndex = getClipboardInsertIndex(holder, event, editorEntry.instance);
                showNotice('info', files.length === 1 ? 'Bild aus der Zwischenablage wird hochgeladen …' : String(files.length) + ' Bilder aus der Zwischenablage werden hochgeladen …');

                queue = Promise.resolve();
                files.forEach(function (file, index) {
                    queue = queue.then(function () {
                        return uploadClipboardImage(file, index).then(function (filePayload) {
                            if (!insertBlockAt(editorEntry.instance, insertIndex + index, 'image', {
                                file: filePayload,
                                caption: '',
                                withBorder: false,
                                withBackground: false,
                                stretched: false
                            })) {
                                throw new Error('Bild konnte nicht an der aktuellen Blockposition eingefügt werden.');
                            }
                        });
                    });
                });

                queue.then(function () {
                    markEditorMutation(definition.key);
                    clearNotice();
                    if (editorEntry.instance.caret && typeof editorEntry.instance.caret.setToBlock === 'function') {
                        editorEntry.instance.caret.setToBlock(insertIndex + files.length - 1, 'end');
                    }
                }).catch(function (error) {
                    showNotice('danger', error && error.message ? error.message : 'Bild aus der Zwischenablage konnte nicht eingefügt werden.');
                    logEditor('warn', 'Clipboard image paste failed.', error);
                });
            };
            holder.addEventListener('paste', handlePaste, true);
            registerEditorUiCleanup(definition.holderId, function () {
                holder.removeEventListener('paste', handlePaste, true);
                delete holder.dataset.cmsClipboardImagePasteBound;
            });
        }

        function isEditorUiTarget(target) {
            return !!(target && target.closest && target.closest('[data-cms-editor-ui="true"]'));
        }

        function isNativeInputTarget(target) {
            return !!(target && target.closest && target.closest('input, textarea, select, button'));
        }

        function isEditableTextTarget(target) {
            return !!(target && target.closest && target.closest('[contenteditable="true"]'));
        }

        function getCurrentEditorBlock(holder, editorInstance) {
            var currentIndex;
            var blocks;

            if (!holder || !editorInstance || !editorInstance.blocks || typeof editorInstance.blocks.getCurrentBlockIndex !== 'function') {
                return null;
            }

            try {
                currentIndex = editorInstance.blocks.getCurrentBlockIndex();
            } catch (_error) {
                return null;
            }

            if (typeof currentIndex !== 'number' || currentIndex < 0) {
                return null;
            }

            blocks = holder.querySelectorAll('.ce-block');
            return blocks[currentIndex] || null;
        }

        function getSelectedEditorBlock(holder) {
            if (!holder || !holder.querySelector) {
                return null;
            }

            return holder.querySelector('.ce-block.ce-block--selected, .ce-block.is-selected, .ce-block[aria-selected="true"], .ce-block[data-selected="true"]');
        }

        function getEditableBlockText(block) {
            var values = [];

            if (!block || !block.querySelectorAll) {
                return '';
            }

            block.querySelectorAll('.ce-paragraph, .ce-header, [contenteditable="true"]').forEach(function (element) {
                if (isEditorUiTarget(element)) {
                    return;
                }

                values.push(String(element.textContent || ''));
            });

            if (values.length === 0) {
                values.push(String(block.textContent || ''));
            }

            return values.join(' ').replace(/[\u200B\uFEFF]/g, '').replace(/\s+/g, ' ').trim();
        }

        function isKeyboardDeletableEmptyBlock(block) {
            if (!block) {
                return false;
            }

            if (block.querySelector('img, iframe, video, audio, table, .tc-wrap, .image-tool, .cms-editorjs-tool--image, .link-tool, .cdx-attaches, .gg-box, .editorjs-spacer-tool, .ce-delimiter')) {
                return false;
            }

            return getEditableBlockText(block) === '';
        }

        function removeEditorBlock(definition, editorEntry, holder, block) {
            var editorInstance = editorEntry && editorEntry.instance ? editorEntry.instance : null;
            var blocksApi = editorInstance && editorInstance.blocks ? editorInstance.blocks : null;
            var index = getBlockIndex(holder, block);

            if (!blocksApi || typeof blocksApi.delete !== 'function' || index < 0) {
                return false;
            }

            closeInlineInserters(holder);
            try {
                blocksApi.delete(index);
            } catch (error) {
                logEditor('warn', 'EditorJS block delete failed.', error);
                return false;
            }
            markEditorMutation(definition.key);

            window.requestAnimationFrame(function () {
                var nextCount = 0;
                var nextIndex;

                if (blocksApi && typeof blocksApi.getBlocksCount === 'function') {
                    try {
                        nextCount = blocksApi.getBlocksCount();
                    } catch (_error) {
                        nextCount = 0;
                    }
                }

                nextIndex = Math.max(0, Math.min(index, nextCount - 1));

                if (nextCount > 0 && editorInstance.caret && typeof editorInstance.caret.setToBlock === 'function') {
                    try {
                        editorInstance.caret.setToBlock(nextIndex, 'start');
                    } catch (_error) {
                        // EditorJS may reject caret placement for non-text tools; deletion still succeeded.
                    }
                }

                renderEditorBlockUi(definition, editorEntry);
            });

            return true;
        }

        function insertEmptyParagraphAfterBlock(definition, editorEntry, holder, block) {
            var editorInstance = editorEntry && editorEntry.instance ? editorEntry.instance : null;
            var blocksApi = editorInstance && editorInstance.blocks ? editorInstance.blocks : null;
            var index = getBlockIndex(holder, block);
            var insertIndex = index + 1;

            if (!blocksApi || typeof blocksApi.insert !== 'function' || index < 0) {
                return false;
            }

            closeInlineInserters(holder);
            if (!insertBlockAt(editorInstance, insertIndex, 'paragraph', { text: '' })) {
                return false;
            }
            markEditorMutation(definition.key);

            window.requestAnimationFrame(function () {
                if (editorInstance.caret && typeof editorInstance.caret.setToBlock === 'function') {
                    try {
                        editorInstance.caret.setToBlock(insertIndex, 'start');
                    } catch (_error) {
                        // EditorJS may reject caret placement briefly while rendering; insertion still succeeded.
                    }
                }

                renderEditorBlockUi(definition, editorEntry);
            });

            return true;
        }

        function bindKeyboardBlockEnter(definition, editorEntry) {
            var holder = getElement(definition.holderId);
            var handleKeydown;

            if (!holder || !editorEntry || !editorEntry.instance || holder.dataset.cmsKeyboardEnterBound === '1') {
                return;
            }

            holder.dataset.cmsKeyboardEnterBound = '1';
            handleKeydown = function (event) {
                var target = event.target;
                var selectedBlock;
                var targetBlock;
                var blockToInsertAfter = null;

                if (event.key !== 'Enter' || event.altKey || event.ctrlKey || event.metaKey || event.shiftKey || event.isComposing) {
                    return;
                }

                if (isEditorUiTarget(target) || isNativeInputTarget(target) || isEditableTextTarget(target)) {
                    return;
                }

                selectedBlock = getSelectedEditorBlock(holder);
                targetBlock = getEventBlock(holder, event) || getCurrentEditorBlock(holder, editorEntry.instance);

                if (selectedBlock) {
                    blockToInsertAfter = selectedBlock;
                } else if (targetBlock) {
                    blockToInsertAfter = targetBlock;
                }

                if (!blockToInsertAfter) {
                    return;
                }

                event.preventDefault();
                event.stopPropagation();

                insertEmptyParagraphAfterBlock(definition, editorEntry, holder, blockToInsertAfter);
            };
            holder.addEventListener('keydown', handleKeydown, true);
            registerEditorUiCleanup(definition.holderId, function () {
                holder.removeEventListener('keydown', handleKeydown, true);
                delete holder.dataset.cmsKeyboardEnterBound;
            });
        }

        function bindKeyboardBlockDelete(definition, editorEntry) {
            var holder = getElement(definition.holderId);
            var handleKeydown;

            if (!holder || !editorEntry || !editorEntry.instance || holder.dataset.cmsKeyboardDeleteBound === '1') {
                return;
            }

            holder.dataset.cmsKeyboardDeleteBound = '1';
            handleKeydown = function (event) {
                var target = event.target;
                var isDeleteKey = event.key === 'Delete' || event.key === 'Backspace';
                var selectedBlock;
                var targetBlock;
                var currentBlock;
                var blockToDelete = null;

                if (!isDeleteKey || event.altKey || event.ctrlKey || event.metaKey || event.isComposing) {
                    return;
                }

                if (isEditorUiTarget(target) || isNativeInputTarget(target)) {
                    return;
                }

                selectedBlock = getSelectedEditorBlock(holder);
                targetBlock = target && target.closest ? target.closest('.ce-block') : null;
                currentBlock = targetBlock || getCurrentEditorBlock(holder, editorEntry.instance);

                if (selectedBlock && (!targetBlock || targetBlock === selectedBlock)) {
                    blockToDelete = selectedBlock;
                } else if (currentBlock && isKeyboardDeletableEmptyBlock(currentBlock)) {
                    blockToDelete = currentBlock;
                }

                if (!blockToDelete) {
                    return;
                }

                event.preventDefault();
                event.stopPropagation();

                if (!removeEditorBlock(definition, editorEntry, holder, blockToDelete)) {
                    return;
                }
            };
            holder.addEventListener('keydown', handleKeydown, true);
            registerEditorUiCleanup(definition.holderId, function () {
                holder.removeEventListener('keydown', handleKeydown, true);
                delete holder.dataset.cmsKeyboardDeleteBound;
            });
        }

        function getSelectionElement(selection) {
            var node = selection && selection.anchorNode ? selection.anchorNode : null;

            if (!node) {
                return null;
            }

            return node.nodeType === 1 ? node : node.parentElement;
        }

        function normalizeEditorLinkUrl(value) {
            var url = String(value || '').trim();
            var parsed;

            if (url === '') {
                return '';
            }
            if (/[\u0000\r\n<>"']/.test(url)) {
                return '';
            }
            if (/^(?:\/|#)/.test(url)) {
                return url;
            }

            if (!/^[a-z][a-z0-9+.-]*:/i.test(url)) {
                url = 'https://' + url.replace(/^\/\//, '');
            }

            try {
                parsed = new URL(url, window.location.origin);
            } catch (_error) {
                return '';
            }

            return /^(https?:|mailto:|tel:)$/i.test(parsed.protocol) ? url : '';
        }

        function escapeEditorSelectionText(value) {
            return String(value || '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function applyCurrentTextBlockData(definition, editorEntry, holder, key, value) {
            var selection = window.getSelection ? window.getSelection() : null;
            var element = getSelectionElement(selection);
            var block = element && element.closest ? element.closest('.ce-block') : null;
            var tool = block ? block.querySelector('.cms-editorjs-tool--paragraph, .cms-editorjs-tool--header') : null;
            var editable = tool ? tool.querySelector('.cms-editorjs-editable') : null;

            if (!holder || !block || !holder.contains(block)) {
                block = getSelectedEditorBlock(holder) || getCurrentEditorBlock(holder, editorEntry ? editorEntry.instance : null);
                tool = block ? block.querySelector('.cms-editorjs-tool--paragraph, .cms-editorjs-tool--header') : null;
                editable = tool ? tool.querySelector('.cms-editorjs-editable') : null;
            }

            if (!holder || !block || !holder.contains(block) || !tool) {
                return;
            }

            tool.dataset[key] = value;

            if (key === 'alignment' && editable) {
                editable.style.textAlign = value === 'justify' ? 'justify' : value;
            }

            if (key === 'spacing' && editable) {
                editable.style.marginBottom = value === 'compact' ? '0.25rem' : (value === 'relaxed' ? '1rem' : (value === 'loose' ? '1.6rem' : '0.65rem'));
            }

            if (editable) {
                editable.dispatchEvent(new Event('input', { bubbles: true }));
                editable.dispatchEvent(new Event('change', { bubbles: true }));
            }

            markEditorMutation(definition ? definition.key : '');
        }

        function notifySelectionEditableChanged(holder, definition) {
            var selection = window.getSelection ? window.getSelection() : null;
            var element = getSelectionElement(selection);
            var editable = element && element.closest ? element.closest('[contenteditable="true"]') : null;

            if (!holder || !editable || !holder.contains(editable)) {
                return;
            }

            editable.dispatchEvent(new Event('input', { bubbles: true }));
            editable.dispatchEvent(new Event('change', { bubbles: true }));
            markEditorMutation(definition ? definition.key : '');
        }

        function applySafeSelectionLinkAttributes(holder) {
            var selection = window.getSelection ? window.getSelection() : null;
            var element = getSelectionElement(selection);
            var link = element && element.closest ? element.closest('a') : null;
            var href;

            if (!holder || !link || !holder.contains(link)) {
                return;
            }

            href = String(link.getAttribute('href') || '');
            if (/^https?:\/\//i.test(href)) {
                link.setAttribute('target', '_blank');
                link.setAttribute('rel', 'noopener noreferrer');
            }
        }

        function runTextSelectionCommand(definition, editorEntry, holder, command, value) {
            var normalizedUrl;

            if (!document.execCommand) {
                return;
            }

            if (command === 'link') {
                normalizedUrl = normalizeEditorLinkUrl(window.prompt('Link-Adresse eingeben', 'https://') || '');
                if (normalizedUrl === '') {
                    return;
                }
                document.execCommand('createLink', false, normalizedUrl);
                applySafeSelectionLinkAttributes(holder);
                notifySelectionEditableChanged(holder, definition);
                return;
            }

            if (command === 'unlink') {
                document.execCommand('unlink', false, null);
                notifySelectionEditableChanged(holder, definition);
                return;
            }

            if (command === 'strikeThrough') {
                document.execCommand('strikeThrough', false, null);
                notifySelectionEditableChanged(holder, definition);
                return;
            }

            if (command === 'inlineCode') {
                document.execCommand('insertHTML', false, '<code>' + escapeEditorSelectionText(String(window.getSelection ? window.getSelection() : '')) + '</code>');
                notifySelectionEditableChanged(holder, definition);
                return;
            }

            if (command === 'marker') {
                document.execCommand('insertHTML', false, '<mark>' + escapeEditorSelectionText(String(window.getSelection ? window.getSelection() : '')) + '</mark>');
                notifySelectionEditableChanged(holder, definition);
                return;
            }

            if (command === 'spoiler') {
                document.execCommand('insertHTML', false, '<span class="tg-spoiler">' + escapeEditorSelectionText(String(window.getSelection ? window.getSelection() : '')) + '</span>');
                notifySelectionEditableChanged(holder, definition);
                return;
            }

            if (command === 'textColor') {
                document.execCommand('foreColor', false, value || '#2563eb');
                notifySelectionEditableChanged(holder, definition);
                return;
            }

            if (command === 'alignment') {
                document.execCommand(value === 'center' ? 'justifyCenter' : (value === 'right' ? 'justifyRight' : (value === 'justify' ? 'justifyFull' : 'justifyLeft')), false, null);
                applyCurrentTextBlockData(definition, editorEntry, holder, 'alignment', value);
                return;
            }

            if (command === 'spacing') {
                applyCurrentTextBlockData(definition, editorEntry, holder, 'spacing', value);
                return;
            }

            document.execCommand(command, false, value || null);
            notifySelectionEditableChanged(holder, definition);
        }

        function createTextSelectionBubble(definition, editorEntry, holder) {
            var bubble = document.createElement('div');
            var groups = [
                [
                    { label: 'B', title: 'Fett', command: 'bold' },
                    { label: 'I', title: 'Kursiv', command: 'italic' },
                    { label: 'U', title: 'Unterstrichen', command: 'underline' },
                    { label: 'S', title: 'Durchgestrichen', command: 'strikeThrough' },
                    { label: 'Mark', title: 'Markieren', command: 'marker' },
                    { label: 'Spoiler', title: 'Spoiler', command: 'spoiler' },
                    { label: 'Blau', title: 'Textfarbe Blau', command: 'textColor', value: '#2563eb' },
                    { label: '</>', title: 'Inline-Code', command: 'inlineCode' }
                ],
                [
                    { label: 'Link', title: 'Link setzen', command: 'link' },
                    { label: '×Link', title: 'Link entfernen', command: 'unlink' }
                ],
                [
                    { label: '←', title: 'Links ausrichten', command: 'alignment', value: 'left' },
                    { label: '↔', title: 'Mittig ausrichten', command: 'alignment', value: 'center' },
                    { label: '→', title: 'Rechts ausrichten', command: 'alignment', value: 'right' },
                    { label: '☰', title: 'Blocksatz', command: 'alignment', value: 'justify' }
                ],
                [
                    { label: 'A−', title: 'Kompakter Abstand', command: 'spacing', value: 'compact' },
                    { label: 'A', title: 'Normaler Abstand', command: 'spacing', value: 'normal' },
                    { label: 'A+', title: 'Mehr Abstand', command: 'spacing', value: 'relaxed' },
                    { label: 'A++', title: 'Großer Abstand', command: 'spacing', value: 'loose' }
                ]
            ];

            bubble.className = 'cms-editor-selection-bubble';
            bubble.dataset.cmsEditorUi = 'true';
            bubble.dataset.mutationFree = 'true';
            bubble.setAttribute('role', 'toolbar');
            bubble.setAttribute('aria-label', 'Textformatierung');
            bubble.setAttribute('hidden', 'hidden');

            groups.forEach(function (items) {
                var group = document.createElement('div');
                group.className = 'cms-editor-selection-bubble__group';

                items.forEach(function (item) {
                    var button = document.createElement('button');
                    button.type = 'button';
                    button.textContent = item.label;
                    button.title = item.title;
                    button.setAttribute('aria-label', item.title);
                    button.addEventListener('mousedown', function (event) {
                        event.preventDefault();
                    });
                    button.addEventListener('click', function (event) {
                        event.preventDefault();
                        runTextSelectionCommand(definition, editorEntry, holder, item.command, item.value);
                    });
                    group.appendChild(button);
                });

                bubble.appendChild(group);
            });

            document.body.appendChild(bubble);
            return bubble;
        }

        function bindTextSelectionBubble(definition, editorEntry) {
            var holder = getElement(definition.holderId);
            var bubble;
            var handleMouseup;
            var handleKeyup;
            var handleBlur;

            if (!holder || !editorEntry || !editorEntry.instance || holder.dataset.cmsSelectionBubbleBound === '1') {
                return;
            }

            holder.dataset.cmsSelectionBubbleBound = '1';
            bubble = createTextSelectionBubble(definition, editorEntry, holder);

            function updateBubble() {
                var selection = window.getSelection ? window.getSelection() : null;
                var element = getSelectionElement(selection);
                var range;
                var rect;

                if (!selection || selection.rangeCount === 0 || selection.isCollapsed || !element || !holder.contains(element) || isEditorUiTarget(element) || isNativeInputTarget(element)) {
                    bubble.setAttribute('hidden', 'hidden');
                    return;
                }

                range = selection.getRangeAt(0);
                rect = range.getBoundingClientRect();
                if (!rect || (rect.width === 0 && rect.height === 0)) {
                    bubble.setAttribute('hidden', 'hidden');
                    return;
                }

                bubble.style.left = Math.max(8, rect.left + window.scrollX + (rect.width / 2)) + 'px';
                bubble.style.top = Math.max(8, rect.bottom + window.scrollY + 10) + 'px';
                bubble.removeAttribute('hidden');
            }

            document.addEventListener('selectionchange', updateBubble);
            handleMouseup = function () { window.setTimeout(updateBubble, 0); };
            handleKeyup = function () { window.setTimeout(updateBubble, 0); };
            handleBlur = function () { bubble.setAttribute('hidden', 'hidden'); };
            holder.addEventListener('mouseup', handleMouseup);
            holder.addEventListener('keyup', handleKeyup);
            holder.addEventListener('blur', handleBlur, true);
            registerEditorUiCleanup(definition.holderId, function () {
                document.removeEventListener('selectionchange', updateBubble);
                holder.removeEventListener('mouseup', handleMouseup);
                holder.removeEventListener('keyup', handleKeyup);
                holder.removeEventListener('blur', handleBlur, true);
                if (bubble.parentNode) {
                    bubble.parentNode.removeChild(bubble);
                }
                delete holder.dataset.cmsSelectionBubbleBound;
            });
        }

        function isEditorInputEmpty(input) {
            var data = normalizeEditorData(safeParseEditorInput(input));
            return !data || !Array.isArray(data.blocks) || data.blocks.length === 0;
        }

        function ensureNoticeBox() {
            var notice = getElement('cmsContentEditorNotice');

            if (notice) {
                return notice;
            }

            notice = document.createElement('div');
            notice.id = 'cmsContentEditorNotice';
            notice.className = 'alert d-none mb-3';
            form.insertBefore(notice, form.firstChild);

            return notice;
        }

        function showNotice(type, message) {
            var notice = ensureNoticeBox();
            var normalizedType = type === 'danger' ? 'danger' : (type || 'info');

            notice.className = 'alert alert-' + normalizedType + ' mb-3';
            notice.textContent = String(message || '');
            notice.classList.remove('d-none');
        }

        function clearNotice() {
            var notice = getElement('cmsContentEditorNotice');
            if (!notice) {
                return;
            }

            notice.textContent = '';
            notice.className = 'alert d-none mb-3';
        }

        function ensurePreviewPanel() {
            var panel = getElement('cmsContentEditorTranslationPreview');
            var notice = ensureNoticeBox();

            if (panel) {
                return panel;
            }

            panel = document.createElement('div');
            panel.id = 'cmsContentEditorTranslationPreview';
            panel.className = 'card border-primary mb-3 d-none';

            if (notice && notice.parentNode === form) {
                if (notice.nextSibling) {
                    form.insertBefore(panel, notice.nextSibling);
                } else {
                    form.appendChild(panel);
                }

                return panel;
            }

            form.insertBefore(panel, form.firstChild);
            return panel;
        }

        function clearPreviewPanel() {
            var panel = getElement('cmsContentEditorTranslationPreview');

            translationPreviewActive = false;
            if (!panel) {
                return;
            }

            clearElement(panel);
            panel.className = 'card border-primary mb-3 d-none';
        }

        function withSuppressedPreviewClear(callback) {
            translationPreviewSuppressClear = true;

            try {
                return callback();
            } finally {
                translationPreviewSuppressClear = false;
            }
        }

        function invalidateTranslationPreview(message) {
            if (!translationPreviewActive || translationPreviewSuppressClear) {
                return;
            }

            clearPreviewPanel();
            showNotice('info', message || 'Der AI-Vorschlag wurde verworfen, weil sich die EN-Bearbeitung seit der Vorschau geändert hat.');
        }

        function stripHtmlPreview(value) {
            return extractTextFromHtml(value);
        }

        function normalizePreviewText(value) {
            return stripHtmlPreview(value).replace(/\s+/g, ' ').trim();
        }

        function truncatePreviewText(value, maxLength) {
            var normalized = normalizePreviewText(value);

            if (normalized.length <= maxLength) {
                return normalized;
            }

            return normalized.slice(0, Math.max(1, maxLength - 1)).trim() + '…';
        }

        function collectListPreviewText(items, collector) {
            (Array.isArray(items) ? items : []).forEach(function (item) {
                if (typeof item === 'string') {
                    if (normalizePreviewText(item) !== '') {
                        collector.push(normalizePreviewText(item));
                    }
                    return;
                }

                if (!item || typeof item !== 'object') {
                    return;
                }

                ['content', 'text'].forEach(function (key) {
                    if (typeof item[key] === 'string' && normalizePreviewText(item[key]) !== '') {
                        collector.push(normalizePreviewText(item[key]));
                    }
                });

                if (Array.isArray(item.items)) {
                    collectListPreviewText(item.items, collector);
                }
            });
        }

        function extractBlockPreviewText(block) {
            var data = block && block.data && typeof block.data === 'object' ? block.data : {};
            var parts = [];

            switch (String(block && block.type || '')) {
                case 'paragraph':
                case 'header':
                case 'mediaText':
                    if (typeof data.text === 'string') {
                        parts.push(normalizePreviewText(data.text));
                    }
                    break;
                case 'quote':
                    ['text', 'caption'].forEach(function (key) {
                        if (typeof data[key] === 'string' && normalizePreviewText(data[key]) !== '') {
                            parts.push(normalizePreviewText(data[key]));
                        }
                    });
                    break;
                case 'warning':
                case 'callout':
                    ['title', 'message'].forEach(function (key) {
                        if (typeof data[key] === 'string' && normalizePreviewText(data[key]) !== '') {
                            parts.push(normalizePreviewText(data[key]));
                        }
                    });
                    break;
                case 'checklist':
                    (Array.isArray(data.items) ? data.items : []).forEach(function (item) {
                        if (item && typeof item === 'object' && typeof item.text === 'string' && normalizePreviewText(item.text) !== '') {
                            parts.push(normalizePreviewText(item.text));
                        }
                    });
                    break;
                case 'list':
                    collectListPreviewText(data.items, parts);
                    break;
                case 'raw':
                    if (typeof data.html === 'string' && normalizePreviewText(data.html) !== '') {
                        parts.push(normalizePreviewText(data.html));
                    }
                    break;
                default:
                    ['text', 'title', 'message', 'caption', 'html'].forEach(function (key) {
                        if (typeof data[key] === 'string' && normalizePreviewText(data[key]) !== '') {
                            parts.push(normalizePreviewText(data[key]));
                        }
                    });
                    break;
            }

            return parts.join(' · ');
        }

        function buildBlockPreviewEntries(data) {
            var normalizedData = normalizeEditorData(data);
            var blocks = Array.isArray(normalizedData && normalizedData.blocks) ? normalizedData.blocks : [];

            return blocks.map(function (block, index) {
                var type = String(block && block.type || 'unbekannt');

                return {
                    index: index + 1,
                    type: type,
                    text: extractBlockPreviewText(block)
                };
            });
        }

        function resolveTranslationOutput(aiTranslation, translation) {
            var translatedData = translation.content_data ? translation.content_data : null;

            if (!translatedData && typeof translation.content_json === 'string') {
                try {
                    translatedData = JSON.parse(translation.content_json);
                } catch (_error) {
                    translatedData = { blocks: [] };
                }
            }

            return {
                title: aiTranslation.targetTitleId
                    ? ((typeof translation.title === 'string' && translation.title !== '')
                        ? translation.title
                        : getFieldValue(aiTranslation.sourceTitleId))
                    : '',
                slug: aiTranslation.targetSlugId
                    ? ((typeof translation.slug === 'string' && translation.slug !== '')
                        ? translation.slug
                        : getFieldValue(aiTranslation.sourceSlugId))
                    : '',
                excerpt: aiTranslation.targetExcerptId
                    ? ((typeof translation.excerpt === 'string' && translation.excerpt !== '')
                        ? translation.excerpt
                        : getFieldValue(aiTranslation.sourceExcerptId))
                    : '',
                contentData: normalizeEditorData(translatedData || { blocks: [] })
            };
        }

        function getTargetDraftState(aiTranslation) {
            var targetDefinition = getDefinition(aiTranslation.targetEditorKey);
            var targetInput = targetDefinition ? getElement(targetDefinition.inputId) : null;

            return {
                title: aiTranslation.targetTitleId ? getFieldValue(aiTranslation.targetTitleId) : '',
                slug: aiTranslation.targetSlugId ? getFieldValue(aiTranslation.targetSlugId) : '',
                excerpt: aiTranslation.targetExcerptId ? getFieldValue(aiTranslation.targetExcerptId) : '',
                contentData: normalizeEditorData(safeParseEditorInput(targetInput))
            };
        }

        function buildBlockDiffSummary(currentData, nextData) {
            var currentBlocks = buildBlockPreviewEntries(currentData);
            var nextBlocks = buildBlockPreviewEntries(nextData);
            var totalBlocks = Math.max(currentBlocks.length, nextBlocks.length);
            var diffs = [];

            for (var index = 0; index < totalBlocks; index += 1) {
                var currentBlock = currentBlocks[index] || { type: '—', text: '' };
                var nextBlock = nextBlocks[index] || { type: '—', text: '' };

                if (currentBlock.type === nextBlock.type
                    && normalizePreviewText(currentBlock.text) === normalizePreviewText(nextBlock.text)) {
                    continue;
                }

                diffs.push({
                    label: 'Block ' + (index + 1),
                    currentType: currentBlock.type,
                    nextType: nextBlock.type,
                    currentText: truncatePreviewText(currentBlock.text || '— leer —', 220) || '— leer —',
                    nextText: truncatePreviewText(nextBlock.text || '— leer —', 220) || '— leer —'
                });
            }

            return {
                currentBlockCount: currentBlocks.length,
                nextBlockCount: nextBlocks.length,
                totalDiffs: diffs.length,
                items: diffs.slice(0, 8),
                omittedDiffs: Math.max(0, diffs.length - 8)
            };
        }

        function createPreviewTextBlock(label, value, className) {
            var wrapper = document.createElement('div');
            var labelElement = document.createElement('div');
            var valueElement = document.createElement('div');

            wrapper.className = 'mb-2';
            labelElement.className = 'text-secondary small mb-1';
            labelElement.textContent = label;
            valueElement.className = className || 'border rounded p-2 bg-body-secondary text-break small';
            valueElement.textContent = value;

            wrapper.appendChild(labelElement);
            wrapper.appendChild(valueElement);

            return wrapper;
        }

        function createFieldPreviewColumn(fieldLabel, currentValue, nextValue) {
            var column = document.createElement('div');
            var card = document.createElement('div');
            var body = document.createElement('div');
            var title = document.createElement('h4');

            column.className = 'col-lg-6';
            card.className = 'card card-sm h-100';
            body.className = 'card-body';
            title.className = 'card-title mb-3';
            title.textContent = fieldLabel;

            body.appendChild(title);
            body.appendChild(createPreviewTextBlock('Aktuell EN', currentValue !== '' ? currentValue : '— leer —'));
            body.appendChild(createPreviewTextBlock('AI-Vorschlag', nextValue !== '' ? nextValue : '— leer —', 'border rounded p-2 bg-primary-subtle text-break small'));
            card.appendChild(body);
            column.appendChild(card);

            return column;
        }

        function renderTranslationPreview(aiTranslation, result, resolvedOutput, onApply, onDiscard) {
            var panel = ensurePreviewPanel();
            var provider = result && result.provider && typeof result.provider === 'object' ? result.provider : {};
            var warnings = Array.isArray(result && result.warnings) ? result.warnings : [];
            var stats = result && result.stats && typeof result.stats === 'object' ? result.stats : {};
            var targetDraft = getTargetDraftState(aiTranslation);
            var fieldDiffs = [];
            var blockSummary = buildBlockDiffSummary(targetDraft.contentData, resolvedOutput.contentData);
            var header = document.createElement('div');
            var headerTitle = document.createElement('div');
            var headerBadges = document.createElement('div');
            var body = document.createElement('div');
            var intro = document.createElement('div');
            var fieldsRow = document.createElement('div');
            var statsCard = document.createElement('div');
            var statsBody = document.createElement('div');
            var statsTitle = document.createElement('h4');
            var statsList = document.createElement('div');
            var blockList = document.createElement('div');
            var footer = document.createElement('div');
            var hint = document.createElement('div');
            var buttonRow = document.createElement('div');
            var discardButton = document.createElement('button');
            var applyButton = document.createElement('button');

            clearElement(panel);
            panel.className = 'card border-primary mb-3';
            translationPreviewActive = true;

            if (aiTranslation.targetTitleId
                && normalizePreviewText(targetDraft.title) !== normalizePreviewText(resolvedOutput.title)) {
                fieldDiffs.push(createFieldPreviewColumn('Titel', targetDraft.title, resolvedOutput.title));
            }

            if (aiTranslation.targetSlugId
                && normalizePreviewText(targetDraft.slug) !== normalizePreviewText(resolvedOutput.slug)) {
                fieldDiffs.push(createFieldPreviewColumn('Slug', targetDraft.slug, resolvedOutput.slug));
            }

            if (aiTranslation.targetExcerptId
                && normalizePreviewText(targetDraft.excerpt) !== normalizePreviewText(resolvedOutput.excerpt)) {
                fieldDiffs.push(createFieldPreviewColumn('Kurzfassung', targetDraft.excerpt, resolvedOutput.excerpt));
            }

            header.className = 'card-header d-flex justify-content-between align-items-center gap-3 flex-wrap';
            headerTitle.className = 'fw-semibold';
            headerTitle.textContent = 'AI-Übersetzung prüfen';
            headerBadges.className = 'd-flex flex-wrap gap-2';

            [
                provider.label ? 'Provider: ' + provider.label : '',
                provider.model ? 'Modell: ' + provider.model : '',
                typeof stats.translated_blocks === 'number' ? 'Blöcke: ' + String(stats.translated_blocks) : '',
                typeof stats.translated_segments === 'number' ? 'Segmente: ' + String(stats.translated_segments) : ''
            ].filter(function (value) {
                return value !== '';
            }).forEach(function (value) {
                var badge = document.createElement('span');
                badge.className = 'badge bg-primary-lt text-primary';
                badge.textContent = value;
                headerBadges.appendChild(badge);
            });

            header.appendChild(headerTitle);
            header.appendChild(headerBadges);

            body.className = 'card-body';
            intro.className = 'alert alert-info';
            intro.textContent = 'Die AI-Übersetzung wurde erzeugt, aber noch nicht in die EN-Bearbeitung übernommen. Bitte prüfe die Änderungen bewusst, bevor du sie anwendest.';
            body.appendChild(intro);

            if (warnings.length > 0) {
                var warningBox = document.createElement('div');
                var warningTitle = document.createElement('div');
                var warningList = document.createElement('ul');

                warningBox.className = 'alert alert-warning';
                warningTitle.className = 'fw-semibold mb-2';
                warningTitle.textContent = 'Hinweise';
                warningList.className = 'mb-0 ps-3';

                warnings.forEach(function (warning) {
                    var item = document.createElement('li');
                    item.textContent = String(warning || '');
                    warningList.appendChild(item);
                });

                warningBox.appendChild(warningTitle);
                warningBox.appendChild(warningList);
                body.appendChild(warningBox);
            }

            fieldsRow.className = 'row g-3';
            if (fieldDiffs.length > 0) {
                fieldDiffs.forEach(function (fieldColumn) {
                    fieldsRow.appendChild(fieldColumn);
                });
                body.appendChild(fieldsRow);
            }

            statsCard.className = 'card card-sm mt-3';
            statsBody.className = 'card-body';
            statsTitle.className = 'card-title mb-3';
            statsTitle.textContent = 'Editor.js-Diff';
            statsList.className = 'row g-2 mb-3';

            [
                { label: 'Aktuelle EN-Blöcke', value: String(blockSummary.currentBlockCount) },
                { label: 'Neue EN-Blöcke', value: String(blockSummary.nextBlockCount) },
                { label: 'Geänderte Blöcke', value: String(blockSummary.totalDiffs) },
                { label: 'Übersetzte Segmente', value: String(typeof stats.translated_segments === 'number' ? stats.translated_segments : 0) }
            ].forEach(function (entry) {
                var col = document.createElement('div');
                var box = document.createElement('div');
                var value = document.createElement('div');
                var label = document.createElement('div');

                col.className = 'col-sm-6 col-lg-3';
                box.className = 'border rounded p-2 h-100';
                value.className = 'fw-semibold';
                label.className = 'text-secondary small';
                value.textContent = entry.value;
                label.textContent = entry.label;
                box.appendChild(value);
                box.appendChild(label);
                col.appendChild(box);
                statsList.appendChild(col);
            });

            statsBody.appendChild(statsTitle);
            statsBody.appendChild(statsList);

            blockList.className = 'd-flex flex-column gap-2';
            if (blockSummary.items.length === 0) {
                var noChanges = document.createElement('div');
                noChanges.className = 'alert alert-secondary mb-0';
                noChanges.textContent = 'Im Editor.js-Inhalt wurden gegenüber der aktuellen EN-Fassung keine textuellen Änderungen erkannt.';
                blockList.appendChild(noChanges);
            } else {
                blockSummary.items.forEach(function (item) {
                    var diffCard = document.createElement('div');
                    var diffHeader = document.createElement('div');
                    var diffGrid = document.createElement('div');
                    var currentColumn = document.createElement('div');
                    var nextColumn = document.createElement('div');

                    diffCard.className = 'border rounded p-3';
                    diffHeader.className = 'fw-semibold mb-2';
                    diffHeader.textContent = item.label + ' (' + item.currentType + ' → ' + item.nextType + ')';
                    diffGrid.className = 'row g-2';
                    currentColumn.className = 'col-lg-6';
                    nextColumn.className = 'col-lg-6';

                    currentColumn.appendChild(createPreviewTextBlock('Aktuell EN', item.currentText));
                    nextColumn.appendChild(createPreviewTextBlock('AI-Vorschlag', item.nextText, 'border rounded p-2 bg-primary-subtle text-break small'));
                    diffGrid.appendChild(currentColumn);
                    diffGrid.appendChild(nextColumn);
                    diffCard.appendChild(diffHeader);
                    diffCard.appendChild(diffGrid);
                    blockList.appendChild(diffCard);
                });

                if (blockSummary.omittedDiffs > 0) {
                    var omitted = document.createElement('div');
                    omitted.className = 'text-secondary small';
                    omitted.textContent = '+' + String(blockSummary.omittedDiffs) + ' weitere Block-Änderungen sind vorhanden.';
                    blockList.appendChild(omitted);
                }
            }

            statsBody.appendChild(blockList);
            statsCard.appendChild(statsBody);
            body.appendChild(statsCard);

            footer.className = 'card-footer d-flex justify-content-between align-items-center gap-3 flex-wrap';
            hint.className = 'text-secondary small';
            hint.textContent = 'Hinweis: „Übernehmen“ schreibt den Vorschlag nur in den Editor. Dauerhaft gespeichert wird wie gewohnt erst beim regulären Speichern des Formulars.';
            buttonRow.className = 'btn-list';
            discardButton.type = 'button';
            discardButton.className = 'btn btn-outline-secondary';
            discardButton.textContent = 'Vorschlag verwerfen';
            applyButton.type = 'button';
            applyButton.className = 'btn btn-primary';
            applyButton.textContent = 'Übersetzung übernehmen';

            discardButton.addEventListener('click', function () {
                if (typeof onDiscard === 'function') {
                    onDiscard();
                }
            });

            applyButton.addEventListener('click', function () {
                if (typeof onApply === 'function') {
                    onApply();
                }
            });

            buttonRow.appendChild(discardButton);
            buttonRow.appendChild(applyButton);
            footer.appendChild(hint);
            footer.appendChild(buttonRow);

            panel.appendChild(header);
            panel.appendChild(body);
            panel.appendChild(footer);
        }

        function setButtonBusy(button, busy, busyLabel) {
            if (!button) {
                return;
            }

            if (busy) {
                if (!button.dataset.originalLabel) {
                    button.dataset.originalLabel = button.textContent || '';
                }

                button.disabled = true;
                button.setAttribute('aria-busy', 'true');
                button.textContent = busyLabel || 'Wird verarbeitet …';
                return;
            }

            button.disabled = false;
            button.removeAttribute('aria-busy');
            if (button.dataset.originalLabel) {
                button.textContent = button.dataset.originalLabel;
            }
        }

        function isFormSubmitter(element) {
            var tagName;
            var type;

            if (!element || element.form !== form) {
                return false;
            }

            tagName = String(element.tagName || '').toLowerCase();
            type = String(element.type || '').toLowerCase();

            if (tagName === 'button') {
                return type === '' || type === 'submit';
            }

            if (tagName === 'input') {
                return type === 'submit' || type === 'image';
            }

            return false;
        }

        function createFallbackSubmitter(submitter) {
            var fallbackButton = document.createElement('button');

            fallbackButton.type = 'submit';
            fallbackButton.hidden = true;
            fallbackButton.tabIndex = -1;
            fallbackButton.setAttribute('aria-hidden', 'true');

            if (!submitter) {
                return fallbackButton;
            }

            if (submitter.name) {
                fallbackButton.name = submitter.name;
            }

            if (typeof submitter.value === 'string') {
                fallbackButton.value = submitter.value;
            }

            ['formaction', 'formmethod', 'formenctype', 'formtarget'].forEach(function (attributeName) {
                if (submitter.hasAttribute && submitter.hasAttribute(attributeName)) {
                    fallbackButton.setAttribute(attributeName, submitter.getAttribute(attributeName));
                }
            });

            if (submitter.hasAttribute && submitter.hasAttribute('formnovalidate')) {
                fallbackButton.setAttribute('formnovalidate', 'formnovalidate');
            }

            return fallbackButton;
        }

        function activateTargetPane(buttonId, targetEditorKey, options) {
            var button = getElement(buttonId);
            var isActive = button && button.getAttribute('aria-pressed') === 'true';
            var activateOptions = options && typeof options === 'object' ? options : {};

            function cleanup() {
                if (activateOptions.suppressInitialCopy && targetEditorKey) {
                    delete suppressInitialCopyForKeys[targetEditorKey];
                }
            }

            if (activateOptions.suppressInitialCopy && targetEditorKey) {
                suppressInitialCopyForKeys[targetEditorKey] = true;
            }

            if (button && !isActive) {
                button.click();
            }

            return waitForNextPaint().then(function () {
                return waitForPendingLazyBinding(targetEditorKey);
            }).then(function () {
                return waitForNextPaint();
            }).then(function (result) {
                cleanup();
                return result;
            }, function (error) {
                cleanup();
                throw error;
            });
        }

        function destroyEditor(key) {
            var current = editors[key];
            var definition = getDefinition(key);
            var holder;

            if (!current) {
                return;
            }

            if (definition) {
                cleanupEditorUi(definition.holderId);
            }

            try {
                if (current.instance && typeof current.instance.destroy === 'function') {
                    current.instance.destroy();
                }
            } catch (_error) {
                // noop
            }

            if (definition) {
                holder = getElement(definition.holderId);
                if (holder) {
                    clearElement(holder);
                }
            }

            if (editorInitWatchdogs[key]) {
                window.clearTimeout(editorInitWatchdogs[key]);
                delete editorInitWatchdogs[key];
            }

            delete editors[key];
        }

        function bindEditor(definition, forceRecreate) {
            var holder = getElement(definition.holderId);
            var input = getElement(definition.inputId);
            var plainState = getPlainEditorState(definition, input);
            var createdInstance;
            var entry;
            var warning;

            if (!definition || !holder || !input) {
                return null;
            }

            if (plainState && plainState.wrap) {
                warning = plainState.wrap.querySelector('.cms-editor-plain-warning');
                if (warning) {
                    warning.remove();
                }
            }

            setEditorStateMarker(definition, 'loading', 'bind-start');
            recordEditorBindingMilestone(definition, 'bindStart', {
                forceRecreate: !!forceRecreate,
                hasFactory: hasEditorFactory(),
                hasCore: hasEditorCore()
            });
            clearHolderFallbackUi(definition);
            ensureHolderVisible(holder);
            setPlainEditorEnhancedState(definition, true);

            if (!hasEditorRuntime()) {
                setEditorStateMarker(definition, 'loading', 'runtime-missing-waiting');
                recordEditorBindingMilestone(definition, 'runtimeWaitStart', {
                    hasFactory: hasEditorFactory(),
                    hasCore: hasEditorCore()
                });
                logEditor('error', '[EJS-CHAIN-BIND-RUNTIME-MISSING] EditorJS bind skipped for holder "' + definition.holderId + '".', {
                    hasFactory: hasEditorFactory(),
                    hasCore: hasEditorCore()
                });

                if (!editorRuntimeRetryQueue[definition.key]) {
                    editorRuntimeRetryQueue[definition.key] = true;
                    waitForEditorRuntimeAvailability(8000).then(function (runtimeReady) {
                        var retryInput = getElement(definition.inputId);

                        delete editorRuntimeRetryQueue[definition.key];

                        if (!retryInput || !getElement(definition.holderId)) {
                            return;
                        }

                        if (runtimeReady) {
                            recordEditorBindingMilestone(definition, 'runtimeWaitEnd', { runtimeReady: true });
                            bindEditor(definition, true);
                            return;
                        }

                        recordEditorBindingMilestone(definition, 'runtimeWaitEnd', {
                            runtimeReady: false,
                            fallbackReason: hasEditorFactory() ? 'core-missing' : 'factory-missing'
                        });
                        renderEditorUnavailableFallback(definition, retryInput, hasEditorFactory() ? 'core-missing' : 'factory-missing');
                    });
                }

                return null;
            }

            if (editors[definition.key] && !forceRecreate) {
                setEditorStateMarker(definition, 'editor', 'reuse-existing-instance');
                recordEditorBindingMilestone(definition, 'bindEnd', { finalState: 'editor', reason: 'reuse-existing-instance' });
                clearHolderFallbackUi(definition);
                setPlainEditorEnhancedState(definition, true);
                ensureEditorUi(definition, editors[definition.key]);
                return editors[definition.key];
            }

            if (forceRecreate) {
                destroyEditor(definition.key);
            }

            registerEditorMutationTracking(definition.key, holder);

            try {
                logEditor('info', '[EJS-CHAIN-BIND-CREATE] Calling createCmsEditor for "' + definition.holderId + '".');
                createdInstance = window.createCmsEditor(definition.holderId, input.value || '', config.mediaUploadUrl, config.csrfToken, {
                    getUploadContext: buildUploadContext,
                    readOnly: !!(definition.readOnly || config.readOnly),
                    themeTypography: config.themeTypography || {},
                    onReady: function () {
                        logEditor('info', '[EJS-CHAIN-BIND-READY-CALLBACK] EditorJS ready callback for "' + definition.holderId + '".');
                    },
                    onError: function (error, context) {
                        logEditor('error', 'EditorJS runtime error for "' + definition.holderId + '".', {
                            context: context || {},
                            error: error || null
                        });
                    },
                    onChange: function (output) {
                        var currentEntry = editors[definition.key];

                        if (!currentEntry || currentEntry.input !== input) {
                            return;
                        }

                        input.value = JSON.stringify(normalizeEditorData(output));
                        emitChangeEvents(input);
                    }
                });
            } catch (error) {
                logEditor('error', '[EJS-CHAIN-BIND-CREATE-FAILED] EditorJS init failed for holder "' + definition.holderId + '".', error);

                renderEditorUnavailableFallback(definition, input, 'init-failed');
                return null;
            }

            entry = {
                input: input,
                instance: createdInstance,
                ready: false
            };
            editors[definition.key] = entry;

            if (editorInitWatchdogs[definition.key]) {
                window.clearTimeout(editorInitWatchdogs[definition.key]);
            }

            editorInitWatchdogs[definition.key] = window.setTimeout(function () {
                var activeEntry = editors[definition.key];

                if (!activeEntry || activeEntry !== entry || activeEntry.ready) {
                    return;
                }

                delete editorInitWatchdogs[definition.key];
                setEditorStateMarker(definition, 'loading', 'init-timeout');
                recordEditorBindingMilestone(definition, 'initTimeout', { fallbackActivated: false });
                logEditor('warn', 'EditorJS init watchdog timed out for "' + definition.holderId + '"; keeping live editor mounted instead of showing fallback.');
            }, EDITOR_INIT_WATCHDOG_MS);

            if (createdInstance && createdInstance.isReady && typeof createdInstance.isReady.then === 'function') {
                createdInstance.isReady.then(function () {
                    if (editorInitWatchdogs[definition.key]) {
                        window.clearTimeout(editorInitWatchdogs[definition.key]);
                        delete editorInitWatchdogs[definition.key];
                    }
                    if (editors[definition.key] === entry) {
                        entry.ready = true;
                        clearHolderFallbackUi(definition);
                        ensureHolderVisible(holder);
                        setEditorStateMarker(definition, 'editor', 'ready-resolved');
                        recordEditorBindingMilestone(definition, 'bindEnd', { finalState: 'editor', reason: 'ready-resolved' });
                        setPlainEditorEnhancedState(definition, true);
                    }
                }).catch(function (error) {
                    var activeEntry = editors[definition.key];
                    if (editorInitWatchdogs[definition.key]) {
                        window.clearTimeout(editorInitWatchdogs[definition.key]);
                        delete editorInitWatchdogs[definition.key];
                    }
                    if (activeEntry !== entry) {
                        return;
                    }
                    logEditor('error', 'EditorJS reported readiness error for "' + definition.holderId + '".', error);
                    delete editors[definition.key];
                    renderEditorUnavailableFallback(definition, input, 'ready-rejected');
                });
            } else {
                entry.ready = true;
                if (editorInitWatchdogs[definition.key]) {
                    window.clearTimeout(editorInitWatchdogs[definition.key]);
                    delete editorInitWatchdogs[definition.key];
                }
                clearHolderFallbackUi(definition);
                setEditorStateMarker(definition, 'editor', 'ready-sync');
                recordEditorBindingMilestone(definition, 'bindEnd', { finalState: 'editor', reason: 'ready-sync' });
                setPlainEditorEnhancedState(definition, true);
            }

            ensureEditorUi(definition, editors[definition.key]);

            return editors[definition.key];
        }

        function getEditorSaveLabel(definition) {
            if (!definition || !definition.key) {
                return 'Editor-Inhalt';
            }

            if (definition.key === 'de') {
                return 'DE-Inhalt';
            }

            if (definition.key === 'en') {
                return 'EN-Inhalt';
            }

            return String(definition.key).toUpperCase() + '-Inhalt';
        }

        function saveEditorContent(key, allowFallback) {
            var entry = editors[key];
            var definition = getDefinition(key);
            var input = definition ? getElement(definition.inputId) : null;
            var activeEntry = null;

            return waitForPendingLazyBinding(key).then(function () {
                entry = editors[key];

                if (!entry) {
                    return normalizeEditorData(safeParseEditorInput(input));
                }

                return ensureEditorReady(key, false);
            }).then(function (readyEntry) {
                activeEntry = readyEntry || editors[key] || entry;

                if (!activeEntry || !activeEntry.instance) {
                    return normalizeEditorData(safeParseEditorInput(input));
                }

                return waitForEditorInstanceReady(activeEntry).then(function () {
                    return waitForNextPaint();
                }).then(function () {
                    return waitForEditorMutationSettle(key);
                }).then(function () {
                    return activeEntry.instance.save();
                });
            }).then(function (output) {
                var normalizedOutput = normalizeEditorData(output);

                if (activeEntry && activeEntry.input) {
                    activeEntry.input.value = JSON.stringify(normalizedOutput);
                    emitChangeEvents(activeEntry.input);
                }

                return normalizedOutput;
            }).catch(function (error) {
                if (allowFallback) {
                    return normalizeEditorData(safeParseEditorInput(input));
                }

                var saveError = new Error(getEditorSaveLabel(definition) + ' konnte vor dem Speichern nicht zuverlässig aus Editor.js serialisiert werden. Bitte problematische Blöcke prüfen und erneut speichern.');

                saveError.editorDefinition = definition || null;
                saveError.originalError = error || null;

                throw saveError;
            });
        }

        function ensureEditorSaved(key) {
            return saveEditorContent(key, true);
        }

        function ensureEditorReady(key, forceRecreate) {
            var definition = getDefinition(key);
            var entry;

            if (!definition) {
                return Promise.resolve(null);
            }

            entry = bindEditor(definition, forceRecreate);
            if (!entry || !entry.instance) {
                return Promise.resolve(null);
            }

            if (entry.instance.isReady && typeof entry.instance.isReady.then === 'function') {
                return entry.instance.isReady.then(function () {
                    return entry;
                }).catch(function () {
                    return entry;
                });
            }

            return Promise.resolve(entry);
        }

        function waitForEditorInstanceReady(entry) {
            if (!entry || !entry.instance || !entry.instance.isReady || typeof entry.instance.isReady.then !== 'function') {
                return Promise.resolve(entry);
            }

            return entry.instance.isReady.then(function () {
                return entry;
            }).catch(function () {
                return entry;
            });
        }

        function applyEditorData(key, data, options) {
            var definition = getDefinition(key);
            var input = definition ? getElement(definition.inputId) : null;
            var applyOptions = options && typeof options === 'object' ? options : {};
            var normalizedData = normalizeEditorData(data);
            var renderResult;

            if (!input) {
                return Promise.resolve();
            }

            input.value = JSON.stringify(normalizedData);
            emitChangeEvents(input);

            if (!definition) {
                return Promise.resolve();
            }

            if (applyOptions.recreateEditor) {
                return ensureEditorReady(key, true).then(function (entry) {
                    return waitForNextPaint().then(function () {
                        return entry;
                    });
                }).catch(function () {
                    return ensureEditorReady(key, true).then(function (entry) {
                        return waitForNextPaint().then(function () {
                            return entry;
                        });
                    });
                });
            }

            return ensureEditorReady(key).then(function (entry) {
                var instance = entry && entry.instance ? entry.instance : null;

                if (!instance) {
                    return null;
                }

                if (instance.blocks && typeof instance.blocks.render === 'function') {
                    renderResult = instance.blocks.render(normalizedData);
                    return Promise.resolve(renderResult).then(function () {
                        return entry;
                    });
                }

                if (typeof instance.render === 'function') {
                    renderResult = instance.render(normalizedData);
                    return Promise.resolve(renderResult).then(function () {
                        return entry;
                    });
                }

                bindEditor(definition, true);
                return ensureEditorReady(key, false);
            }).catch(function () {
                bindEditor(definition, true);
                return ensureEditorReady(key, false);
            });
        }

        function copyFieldValue(sourceId, targetId) {
            if (!sourceId || !targetId) {
                return;
            }

            setFieldValue(targetId, getFieldValue(sourceId));
        }

        function targetHasDraftContent(targetEditorKey, targetTitleId, targetSlugId, targetExcerptId) {
            var targetDefinition = getDefinition(targetEditorKey);
            var targetInput = targetDefinition ? getElement(targetDefinition.inputId) : null;

            return (targetTitleId ? getFieldValue(targetTitleId) !== '' : false)
                || (targetSlugId ? getFieldValue(targetSlugId) !== '' : false)
                || (targetExcerptId ? getFieldValue(targetExcerptId) !== '' : false)
                || (targetInput && !isEditorInputEmpty(targetInput));
        }

        function handleCopyAction(copyAction) {
            var button = copyAction && copyAction.buttonId ? getElement(copyAction.buttonId) : null;

            if (!copyAction) {
                return;
            }

            if (button) {
                button.addEventListener('click', function (event) {
                    if (event) {
                        event.preventDefault();
                    }

                    clearNotice();
                    clearPreviewPanel();
                    setButtonBusy(button, true, 'Kopiere …');

                    if (copyAction.contentMode === 'legacy-html') {
                        Promise.resolve().then(function () {
                            copyFieldValue(copyAction.sourceTitleId, copyAction.targetTitleId);
                            copyFieldValue(copyAction.sourceSlugId, copyAction.targetSlugId);
                            copyFieldValue(copyAction.sourceExcerptId, copyAction.targetExcerptId);
                            writeContentFieldValue(
                                copyAction.targetContentFieldId,
                                copyAction.targetContentFieldName,
                                readContentFieldValue(copyAction.sourceContentFieldId, copyAction.sourceContentFieldName)
                            );
                        }).then(function () {
                            showNotice('success', 'DE-Inhalt wurde in die EN-Bearbeitung übernommen und bestehende EN-Inhalte überschrieben.');
                        }).catch(function (error) {
                            if (typeof console !== 'undefined' && typeof console.error === 'function') {
                                console.error('DE→EN Copy fehlgeschlagen.', error);
                            }

                            showNotice('danger', (error && error.message) ? error.message : 'DE-Inhalt konnte nicht in die EN-Bearbeitung kopiert werden.');
                        }).finally(function () {
                            setButtonBusy(button, false);
                        });

                        return;
                    }

                    Promise.resolve().then(function () {
                        if (copyAction.sourceEditorKey && editors[copyAction.sourceEditorKey]) {
                            return ensureEditorSaved(copyAction.sourceEditorKey);
                        }

                        return readEditorJsSourceData(copyAction.sourceContentFieldId, copyAction.sourceContentFieldName);
                    }).then(function (sourceData) {
                        var recreateTargetEditor = !(editors[copyAction.targetEditorKey] && editors[copyAction.targetEditorKey].instance);

                        return withSuppressedPreviewClear(function () {
                            copyFieldValue(copyAction.sourceTitleId, copyAction.targetTitleId);
                            copyFieldValue(copyAction.sourceSlugId, copyAction.targetSlugId);
                            copyFieldValue(copyAction.sourceExcerptId, copyAction.targetExcerptId);

                            return activateTargetPane(copyAction.targetPaneButtonId, copyAction.targetEditorKey, {
                                suppressInitialCopy: true
                            }).then(function () {
                                return applyEditorData(copyAction.targetEditorKey, sourceData, {
                                    recreateEditor: recreateTargetEditor
                                });
                            });
                        });
                    }).then(function () {
                        showNotice('success', 'DE-Inhalt wurde in die EN-Bearbeitung übernommen und bestehende EN-Inhalte überschrieben.');
                    }).catch(function (error) {
                        if (typeof console !== 'undefined' && typeof console.error === 'function') {
                            console.error('DE→EN Copy fehlgeschlagen.', error);
                        }

                        showNotice('danger', (error && error.message) ? error.message : 'DE-Inhalt konnte nicht in die EN-Bearbeitung kopiert werden.');
                    }).finally(function () {
                        setButtonBusy(button, false);
                    });
                });
            }
        }

        function buildTranslationChunkPayload(aiTranslation, state, chunk) {
            var params = new URLSearchParams();
            var editorData = { blocks: chunk.blocks.slice() };

            if (state.sourceVersion) {
                editorData.version = state.sourceVersion;
            }

            params.append('csrf_token', String(aiTranslation.csrfToken || ''));
            params.append('content_type', String(aiTranslation.contentType || 'editorjs'));
            params.append('source_locale', String(aiTranslation.sourceLocale || 'de'));
            params.append('target_locale', String(aiTranslation.targetLocale || 'en'));
            params.append('title', chunk.includeMeta ? state.source.title : '');
            params.append('slug', chunk.includeMeta ? state.source.slug : '');
            params.append('excerpt', chunk.includeMeta ? state.source.excerpt : '');
            params.append('editor_data', JSON.stringify(editorData));

            if (state.sessionId) {
                params.append('chunk_session', state.sessionId);
            } else {
                params.append('chunk_total', String(Math.max(1, state.chunks.length - state.nextIndex)));
            }

            return params;
        }

        function buildSeoMetadataPayload(aiSeoMetadata, sourceData) {
            var params = new URLSearchParams();

            params.append('csrf_token', String(aiSeoMetadata.csrfToken || ''));
            params.append('content_type', String(aiSeoMetadata.contentType || ''));
            params.append('locale', String(aiSeoMetadata.locale || 'de'));
            params.append('editor_data', JSON.stringify(normalizeEditorData(sourceData)));

            return params;
        }

        function getSeoMetadataTextFields(aiSeoMetadata) {
            return [
                ['excerpt', aiSeoMetadata.excerptId],
                ['focus_keyphrase', aiSeoMetadata.focusKeyphraseId],
                ['keywords', aiSeoMetadata.keywordsId],
                ['meta_title', aiSeoMetadata.metaTitleId],
                ['meta_description', aiSeoMetadata.metaDescriptionId],
                ['og_title', aiSeoMetadata.ogTitleId],
                ['og_description', aiSeoMetadata.ogDescriptionId],
                ['twitter_title', aiSeoMetadata.twitterTitleId],
                ['twitter_description', aiSeoMetadata.twitterDescriptionId]
            ].filter(function (entry) {
                return !!entry[1];
            });
        }

        function setSelectFieldValue(id, value) {
            var field = getElement(id);
            var options;

            if (!field || typeof value !== 'string' || value === '') {
                return false;
            }

            options = Array.prototype.slice.call(field.options || []);
            if (!options.some(function (option) { return option.value === value; })) {
                return false;
            }

            field.value = value;
            emitChangeEvents(field);
            return true;
        }

        function setCheckboxFieldValue(id, value) {
            var field = getElement(id);

            if (!field || typeof value !== 'boolean') {
                return false;
            }

            field.checked = value;
            emitChangeEvents(field);
            return true;
        }

        function seoMetadataHasExistingValues(aiSeoMetadata) {
            return getSeoMetadataTextFields(aiSeoMetadata).some(function (entry) {
                return getFieldValue(entry[1]) !== '';
            });
        }

        function applySeoMetadata(aiSeoMetadata, metadata) {
            var appliedFieldCount = 0;

            getSeoMetadataTextFields(aiSeoMetadata).forEach(function (entry) {
                var metadataKey = entry[0];
                var fieldId = entry[1];

                if (typeof metadata[metadataKey] !== 'string' || metadata[metadataKey] === '') {
                    return;
                }

                setFieldValue(fieldId, metadata[metadataKey]);
                appliedFieldCount += 1;
            });

            [
                ['twitter_card', aiSeoMetadata.twitterCardId],
                ['schema_type', aiSeoMetadata.schemaTypeId],
                ['sitemap_changefreq', aiSeoMetadata.sitemapChangefreqId]
            ].forEach(function (entry) {
                if (setSelectFieldValue(entry[1], metadata[entry[0]])) {
                    appliedFieldCount += 1;
                }
            });

            if (typeof metadata.sitemap_priority === 'string' && metadata.sitemap_priority !== '' && aiSeoMetadata.sitemapPriorityId) {
                setFieldValue(aiSeoMetadata.sitemapPriorityId, metadata.sitemap_priority);
                appliedFieldCount += 1;
            }

            if (setCheckboxFieldValue(aiSeoMetadata.robotsIndexId, metadata.robots_index)) {
                appliedFieldCount += 1;
            }
            if (setCheckboxFieldValue(aiSeoMetadata.robotsFollowId, metadata.robots_follow)) {
                appliedFieldCount += 1;
            }

            return appliedFieldCount;
        }

        function resolveSameOriginUrl(url) {
            var resolved;

            try {
                resolved = new URL(String(url || ''), window.location.origin);
            } catch (_error) {
                return '';
            }

            if (resolved.origin !== window.location.origin) {
                return '';
            }

            return resolved.href;
        }

        function resolveTranslationRequestTimeout(timeoutMs) {
            var configuredTimeout = Number(timeoutMs);

            if (!Number.isFinite(configuredTimeout)) {
                return 300000;
            }

            return Math.min(Math.max(configuredTimeout, 30000), 900000);
        }

        function requestJson(url, body, timeoutMs) {
            var endpointUrl = resolveSameOriginUrl(url);
            var controller = typeof AbortController === 'function' ? new AbortController() : null;
            var timeoutId = null;
            var maxResponseLength = 1048576;

            if (endpointUrl === '') {
                return Promise.reject(new Error('AI-Endpunkt ist ungültig.'));
            }

            if (controller) {
                timeoutId = window.setTimeout(function () {
                    controller.abort();
                }, resolveTranslationRequestTimeout(timeoutMs));
            }

            return fetch(endpointUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: body.toString(),
                signal: controller ? controller.signal : undefined
            }).then(function (response) {
                var declaredLength = Number(response.headers && response.headers.get('Content-Length'));

                if (Number.isFinite(declaredLength) && declaredLength > maxResponseLength) {
                    throw new Error('AI-Antwort ist zu groß.');
                }

                return response.text().then(function (text) {
                    var data = null;

                    if (String(text || '').length > maxResponseLength) {
                        throw new Error('AI-Antwort ist zu groß.');
                    }

                    try {
                        data = JSON.parse(text);
                    } catch (_error) {
                        data = null;
                    }

                    return {
                        ok: response.ok,
                        status: response.status,
                        data: data,
                        rawText: ''
                    };
                });
            }).catch(function (error) {
                if (error && error.name === 'AbortError') {
                    throw new Error('Die AI-Anfrage hat das Zeitlimit überschritten.');
                }

                throw error;
            }).finally(function () {
                if (timeoutId !== null) {
                    window.clearTimeout(timeoutId);
                }
            });
        }

        function targetAlreadyHasContent(aiTranslation) {
            return targetHasDraftContent(
                aiTranslation.targetEditorKey,
                aiTranslation.targetTitleId,
                aiTranslation.targetSlugId,
                aiTranslation.targetExcerptId
            );
        }

        function applyResolvedTranslation(aiTranslation, resolvedOutput) {
            return withSuppressedPreviewClear(function () {
                return activateTargetPane(aiTranslation.targetPaneButtonId, aiTranslation.targetEditorKey, {
                    suppressInitialCopy: true
                }).then(function () {
                    if (aiTranslation.targetTitleId) {
                        setFieldValue(aiTranslation.targetTitleId, resolvedOutput.title);
                    }

                    if (aiTranslation.targetSlugId) {
                        setFieldValue(aiTranslation.targetSlugId, resolvedOutput.slug);
                    }

                    if (aiTranslation.targetExcerptId) {
                        setFieldValue(aiTranslation.targetExcerptId, resolvedOutput.excerpt);
                    }

                    return applyEditorData(aiTranslation.targetEditorKey, resolvedOutput.contentData || { blocks: [] }, {
                        recreateEditor: true
                    }).then(function () {
                        clearPreviewPanel();
                    });
                });
            });
        }

        function dispatchValidatedSubmit(submitter) {
            var resolvedSubmitter = isFormSubmitter(submitter) ? submitter : null;
            var fallbackButton;
            var restoreSuppressedPlainEditors = suppressPlainEditorSubmitNames();

            if (typeof form.requestSubmit === 'function') {
                nativeSubmitPending = true;

                try {
                    if (resolvedSubmitter) {
                        form.requestSubmit(resolvedSubmitter);
                    } else {
                        form.requestSubmit();
                    }
                } finally {
                    nativeSubmitPending = false;
                    pendingSubmitter = null;
                    restoreSuppressedPlainEditors();
                }

                return;
            }

            if (typeof form.reportValidity === 'function' && !form.reportValidity()) {
                restoreSuppressedPlainEditors();
                return;
            }

            fallbackButton = createFallbackSubmitter(resolvedSubmitter);

            nativeSubmitPending = true;
            form.appendChild(fallbackButton);

            try {
                fallbackButton.click();
            } finally {
                fallbackButton.remove();
                nativeSubmitPending = false;
                pendingSubmitter = null;
                restoreSuppressedPlainEditors();
            }
        }

        function registerPreviewInvalidation(aiTranslation) {
            var targetEditorDefinition = getDefinition(aiTranslation.targetEditorKey);
            var targetFieldIds = [aiTranslation.targetTitleId, aiTranslation.targetSlugId, aiTranslation.targetExcerptId]
                .filter(function (fieldId) {
                    return !!fieldId;
                });

            targetFieldIds.forEach(function (fieldId) {
                var field = getElement(fieldId);

                if (!field || field.dataset.translationPreviewInvalidateBound === '1') {
                    return;
                }

                field.dataset.translationPreviewInvalidateBound = '1';
                field.addEventListener('input', function () {
                    invalidateTranslationPreview('Der AI-Vorschlag wurde verworfen, weil sich Titel, Slug oder Kurzfassung der EN-Bearbeitung seit der Vorschau geändert haben.');
                });
            });

            if (targetEditorDefinition) {
                var targetHolder = getElement(targetEditorDefinition.holderId);

                if (targetHolder && targetHolder.dataset.translationPreviewInvalidateBound !== '1') {
                    targetHolder.dataset.translationPreviewInvalidateBound = '1';
                    targetHolder.addEventListener('input', function () {
                        invalidateTranslationPreview('Der AI-Vorschlag wurde verworfen, weil sich der EN-Editorinhalt seit der Vorschau geändert hat.');
                    });
                }
            }
        }

        var translationChunkResumeState = null;

        function waitMs(delayMs) {
            return new Promise(function (resolve) {
                window.setTimeout(resolve, delayMs);
            });
        }

        function createTranslationError(message, code) {
            var error = new Error(message);
            error.code = code || '';

            return error;
        }

        function buildTranslationSourceSnapshot(aiTranslation, sourceData) {
            var normalizedData = normalizeEditorData(sourceData) || { blocks: [] };
            var blocks = Array.isArray(normalizedData.blocks) ? normalizedData.blocks : [];
            var source = {
                title: getFieldValue(aiTranslation.sourceTitleId),
                slug: getFieldValue(aiTranslation.sourceSlugId),
                excerpt: getFieldValue(aiTranslation.sourceExcerptId)
            };

            return {
                source: source,
                blocks: blocks,
                version: typeof normalizedData.version === 'string' ? normalizedData.version : '',
                signature: JSON.stringify([source.title, source.slug, source.excerpt, blocks])
            };
        }

        function createTranslationChunkState(snapshot, targetHadContent) {
            var chunks = [];

            if (snapshot.source.title !== '' || snapshot.source.excerpt !== '') {
                chunks.push({ includeMeta: true, blocks: [], label: 'Titel & Kurzfassung' });
            }

            snapshot.blocks.forEach(function (block, index) {
                chunks.push({
                    includeMeta: false,
                    blocks: [block],
                    label: 'Block ' + String(index + 1) + '/' + String(snapshot.blocks.length)
                });
            });

            return {
                signature: snapshot.signature,
                source: snapshot.source,
                sourceVersion: snapshot.version,
                chunks: chunks,
                nextIndex: 0,
                sessionId: '',
                mode: '',
                targetHadContent: targetHadContent,
                targetPaneActivated: false,
                editorRecreated: false,
                appliedBlockCount: 0,
                metaApplied: false,
                meta: null,
                translatedBlocks: [],
                provider: null,
                message: '',
                warnings: [],
                stats: {
                    total_blocks: snapshot.blocks.length,
                    translated_blocks: 0,
                    translated_segments: 0,
                    translation_batches: 0,
                    preserved_blocks: 0,
                    dropped_blocks: 0,
                    skipped_block_types: []
                },
                cancelled: false
            };
        }

        function extractTranslatedBlocks(translation) {
            var translatedData = translation && translation.content_data ? translation.content_data : null;

            if (!translatedData && translation && typeof translation.content_json === 'string') {
                try {
                    translatedData = JSON.parse(translation.content_json);
                } catch (_error) {
                    translatedData = null;
                }
            }

            translatedData = normalizeEditorData(translatedData || { blocks: [] });

            return translatedData && Array.isArray(translatedData.blocks) ? translatedData.blocks : [];
        }

        function mergeTranslationChunkResult(state, chunk, result) {
            var translation = result.translation && typeof result.translation === 'object' ? result.translation : {};
            var stats = result.stats && typeof result.stats === 'object' ? result.stats : {};

            if (!state.provider && result.provider && typeof result.provider === 'object') {
                state.provider = result.provider;
            }

            if (state.mode === '' && (result.preview_required || result.result_mode === 'preview')) {
                state.mode = 'preview';
            }

            if (!state.message && typeof result.message === 'string') {
                state.message = result.message;
            }

            if (result.chunk && typeof result.chunk.session === 'string' && result.chunk.session !== '') {
                state.sessionId = result.chunk.session;
            }

            if (chunk.includeMeta) {
                state.meta = {
                    title: typeof translation.title === 'string' ? translation.title : '',
                    slug: typeof translation.slug === 'string' ? translation.slug : '',
                    excerpt: typeof translation.excerpt === 'string' ? translation.excerpt : ''
                };
            } else {
                extractTranslatedBlocks(translation).forEach(function (block) {
                    state.translatedBlocks.push(block);
                });
            }

            (Array.isArray(result.warnings) ? result.warnings : []).forEach(function (warning) {
                var text = String(warning || '');

                if (text !== '' && state.warnings.indexOf(text) === -1) {
                    state.warnings.push(text);
                }
            });

            ['translated_blocks', 'translated_segments', 'translation_batches', 'preserved_blocks', 'dropped_blocks'].forEach(function (key) {
                if (typeof stats[key] === 'number') {
                    state.stats[key] += stats[key];
                }
            });

            (Array.isArray(stats.skipped_block_types) ? stats.skipped_block_types : []).forEach(function (type) {
                if (state.stats.skipped_block_types.indexOf(type) === -1) {
                    state.stats.skipped_block_types.push(type);
                }
            });
        }

        function buildChunkedTranslationOutput(aiTranslation, state) {
            var contentData = { blocks: state.translatedBlocks.slice() };
            var meta = state.meta || { title: '', slug: '', excerpt: '' };

            if (state.sourceVersion) {
                contentData.version = state.sourceVersion;
            }

            return resolveTranslationOutput(aiTranslation, {
                title: meta.title,
                slug: meta.slug,
                excerpt: meta.excerpt,
                content_data: contentData
            });
        }

        function buildChunkedTranslationResult(state) {
            return {
                provider: state.provider || {},
                warnings: state.warnings.slice(),
                stats: state.stats,
                message: state.message
            };
        }

        function requestTranslationChunk(aiTranslation, state, chunk, attempt) {
            return requestJson(
                aiTranslation.endpointUrl,
                buildTranslationChunkPayload(aiTranslation, state, chunk),
                aiTranslation.requestTimeoutMs
            ).then(function (response) {
                var result = response.data || {};

                if (!response.ok || !result.success) {
                    throw createTranslationError(
                        (result && result.error) ? result.error : 'AI-Übersetzung konnte nicht verarbeitet werden.',
                        (result && result.error_code)
                            ? String(result.error_code)
                            : ([401, 403, 405].indexOf(Number(response.status)) !== -1 ? 'not_retryable' : '')
                    );
                }

                return result;
            }).catch(function (error) {
                if (state.cancelled || attempt >= 2 || (error && error.code === 'not_retryable')) {
                    throw error;
                }

                if (error && error.code === 'chunk_session_invalid') {
                    state.sessionId = '';
                }

                return waitMs(1500).then(function () {
                    return requestTranslationChunk(aiTranslation, state, chunk, attempt + 1);
                });
            });
        }

        function applyTranslationMetaFields(aiTranslation, output) {
            if (aiTranslation.targetTitleId) {
                setFieldValue(aiTranslation.targetTitleId, output.title);
            }

            if (aiTranslation.targetSlugId) {
                setFieldValue(aiTranslation.targetSlugId, output.slug);
            }

            if (aiTranslation.targetExcerptId) {
                setFieldValue(aiTranslation.targetExcerptId, output.excerpt);
            }
        }

        function applyTranslationChunkProgress(aiTranslation, state, chunk) {
            var output = buildChunkedTranslationOutput(aiTranslation, state);

            return withSuppressedPreviewClear(function () {
                var ready = state.targetPaneActivated
                    ? Promise.resolve()
                    : activateTargetPane(aiTranslation.targetPaneButtonId, aiTranslation.targetEditorKey, {
                        suppressInitialCopy: true
                    }).then(function () {
                        state.targetPaneActivated = true;
                    });

                return ready.then(function () {
                    var recreateEditor = !state.editorRecreated;

                    if (chunk.includeMeta) {
                        applyTranslationMetaFields(aiTranslation, output);
                        state.metaApplied = true;
                        return null;
                    }

                    if (!recreateEditor && output.contentData.blocks.length === state.appliedBlockCount) {
                        return null;
                    }

                    return applyEditorData(aiTranslation.targetEditorKey, output.contentData, {
                        recreateEditor: recreateEditor
                    }).then(function () {
                        state.editorRecreated = true;
                        state.appliedBlockCount = output.contentData.blocks.length;
                    });
                });
            });
        }

        function resolveChunkedTranslationMode(state) {
            if (state.mode !== '') {
                return state.mode;
            }

            if (state.targetHadContent && !window.confirm('Die EN-Bearbeitung enthält bereits Inhalte. Die AI-Übersetzung ersetzt Titel, Slug, Kurzfassung und Editor-Inhalt jetzt Block für Block.\n\nOK = blockweise direkt übernehmen\nAbbrechen = erst alles übersetzen und dann als Vorschau prüfen')) {
                state.mode = 'preview';
            } else {
                state.mode = 'progressive';
            }

            return state.mode;
        }

        function runChunkedTranslation(aiTranslation, state, onProgress) {
            function next() {
                var chunk;

                if (state.nextIndex >= state.chunks.length) {
                    return Promise.resolve(state);
                }

                if (state.cancelled) {
                    return Promise.reject(createTranslationError('Die AI-Übersetzung wurde abgebrochen.', 'cancelled'));
                }

                chunk = state.chunks[state.nextIndex];
                onProgress(state, chunk);

                return requestTranslationChunk(aiTranslation, state, chunk, 1).then(function (result) {
                    mergeTranslationChunkResult(state, chunk, result);
                    state.nextIndex += 1;

                    if (resolveChunkedTranslationMode(state) === 'progressive') {
                        return applyTranslationChunkProgress(aiTranslation, state, chunk);
                    }

                    return null;
                }).then(next);
            }

            return next();
        }

        function createTranslationCancelButton(button, state) {
            var cancelButton = document.createElement('button');

            cancelButton.type = 'button';
            cancelButton.className = 'btn btn-outline-danger btn-sm';
            cancelButton.textContent = 'Übersetzung abbrechen';
            cancelButton.addEventListener('click', function () {
                state.cancelled = true;
                cancelButton.disabled = true;
                cancelButton.textContent = 'Wird nach aktuellem Block abgebrochen …';
            });
            button.insertAdjacentElement('afterend', cancelButton);

            return cancelButton;
        }

        function finishChunkedTranslation(aiTranslation, state) {
            var output = buildChunkedTranslationOutput(aiTranslation, state);
            var result = buildChunkedTranslationResult(state);
            var warningText = state.warnings.length > 0 ? ' Hinweise: ' + state.warnings.join(' · ') : '';

            translationChunkResumeState = null;

            if (state.mode === 'preview') {
                showNotice('info', 'AI-Übersetzung wurde blockweise erzeugt. Bitte Vorschau prüfen und Änderungen nur bei Bedarf übernehmen.' + warningText);

                renderTranslationPreview(aiTranslation, result, output, function () {
                    applyResolvedTranslation(aiTranslation, output).then(function () {
                        showNotice('success', 'AI-Übersetzung wurde in die EN-Bearbeitung übernommen.' + warningText);
                    }).catch(function (error) {
                        showNotice('danger', (error && error.message) ? error.message : 'AI-Übersetzung konnte nicht in die EN-Bearbeitung übernommen werden.');
                    });
                }, function () {
                    clearPreviewPanel();
                    showNotice('info', 'AI-Vorschlag wurde verworfen. Die aktuelle EN-Bearbeitung blieb unverändert.' + warningText);
                });

                return Promise.resolve();
            }

            return Promise.resolve(withSuppressedPreviewClear(function () {
                if (!state.metaApplied) {
                    applyTranslationMetaFields(aiTranslation, output);
                    state.metaApplied = true;
                }

                if (!state.editorRecreated) {
                    state.editorRecreated = true;
                    return applyEditorData(aiTranslation.targetEditorKey, output.contentData, { recreateEditor: true });
                }

                return null;
            })).then(function () {
                showNotice('success', 'AI-Übersetzung wurde Block für Block in die EN-Bearbeitung übernommen (' + String(state.translatedBlocks.length) + ' Blöcke). Dauerhaft gespeichert wird erst beim regulären Speichern.' + warningText);
            });
        }

        function describeInterruptedTranslation(state, error) {
            var chunk = state.chunks[state.nextIndex] || null;
            var position = chunk ? chunk.label : 'Abschluss';
            var keptText = state.mode === 'progressive'
                ? ' Bereits übersetzte Inhalte (' + String(state.appliedBlockCount) + ' Blöcke) wurden übernommen.'
                : ' Bereits übersetzte Inhalte bleiben für die Fortsetzung zwischengespeichert.';
            var resumeText = ' Erneut auf den Übersetzen-Button klicken, um ab „' + position + '“ fortzusetzen.';

            if (error && error.code === 'cancelled') {
                return 'AI-Übersetzung wurde vor „' + position + '“ abgebrochen.' + keptText + resumeText;
            }

            return 'AI-Übersetzung bei „' + position + '“ fehlgeschlagen: ' + ((error && error.message) ? error.message : 'Unbekannter Fehler.') + keptText + resumeText;
        }

        function resolveResumableTranslationState(snapshot, targetData) {
            var state = translationChunkResumeState;
            var targetBlocks = targetData && Array.isArray(targetData.blocks) ? targetData.blocks : [];

            if (!state || state.signature !== snapshot.signature || state.nextIndex >= state.chunks.length) {
                return null;
            }

            if (state.mode === 'progressive' && state.editorRecreated && targetBlocks.length !== state.appliedBlockCount) {
                return null;
            }

            if (!window.confirm('Die letzte AI-Übersetzung wurde bei „' + state.chunks[state.nextIndex].label + '“ unterbrochen.\n\nOK = dort fortsetzen\nAbbrechen = komplett neu übersetzen')) {
                return null;
            }

            state.cancelled = false;

            return state;
        }

        function handleAiTranslation(aiTranslation) {
            var button = aiTranslation && aiTranslation.buttonId ? getElement(aiTranslation.buttonId) : null;

            if (!aiTranslation || !button) {
                return;
            }

            button.addEventListener('click', function () {
                var state = null;
                var cancelButton = null;

                clearNotice();
                clearPreviewPanel();
                setButtonBusy(button, true, 'Übersetze …');

                Promise.all([
                    ensureEditorSaved(aiTranslation.sourceEditorKey),
                    ensureEditorSaved(aiTranslation.targetEditorKey)
                ]).then(function (savedStates) {
                    var snapshot = buildTranslationSourceSnapshot(aiTranslation, savedStates[0]);

                    state = resolveResumableTranslationState(snapshot, normalizeEditorData(savedStates[1]));
                    if (!state) {
                        state = createTranslationChunkState(snapshot, targetAlreadyHasContent(aiTranslation));
                    }

                    if (state.chunks.length === 0) {
                        throw createTranslationError('Es gibt keinen deutschen Inhalt, der übersetzt werden könnte.', 'empty');
                    }

                    translationChunkResumeState = state;
                    cancelButton = createTranslationCancelButton(button, state);

                    return runChunkedTranslation(aiTranslation, state, function (currentState, chunk) {
                        setButtonBusy(button, true, 'Übersetze ' + chunk.label + ' …');
                        showNotice('info', 'AI-Übersetzung läuft Block für Block (' + String(currentState.nextIndex + 1) + ' von ' + String(currentState.chunks.length) + ': ' + chunk.label + '). Bitte währenddessen nicht im EN-Editor arbeiten.');
                    }).then(function () {
                        return finishChunkedTranslation(aiTranslation, state);
                    });
                }).catch(function (error) {
                    if (state && state === translationChunkResumeState && state.chunks.length > 0) {
                        showNotice(error && error.code === 'cancelled' ? 'warning' : 'danger', describeInterruptedTranslation(state, error));
                        return;
                    }

                    showNotice('danger', (error && error.message) ? error.message : 'AI-Übersetzung konnte nicht verarbeitet werden.');
                }).finally(function () {
                    if (cancelButton) {
                        cancelButton.remove();
                    }

                    setButtonBusy(button, false);
                });
            });
        }

        function handleAiSeoMetadata(aiSeoMetadata) {
            var button = aiSeoMetadata && aiSeoMetadata.buttonId ? getElement(aiSeoMetadata.buttonId) : null;

            if (!aiSeoMetadata || !button) {
                return;
            }

            button.addEventListener('click', function () {
                clearNotice();
                clearPreviewPanel();
                setButtonBusy(button, true, 'SEO wird erstellt …');

                ensureEditorSaved(aiSeoMetadata.sourceEditorKey).then(function (sourceData) {
                    return requestJson(
                        aiSeoMetadata.endpointUrl,
                        buildSeoMetadataPayload(aiSeoMetadata, sourceData),
                        aiSeoMetadata.requestTimeoutMs
                    );
                }).then(function (response) {
                    var result = response.data || {};
                    var metadata = result.metadata;
                    var stats = result.stats && typeof result.stats === 'object' ? result.stats : {};
                    var hasExistingValues;
                    var appliedFieldCount;
                    var truncatedHint = stats.source_truncated
                        ? ' Der Haupttext wurde gemäß dem eingestellten Zeichenlimit gekürzt.'
                        : '';

                    if (!response.ok || !result.success) {
                        throw new Error((result && result.error) ? result.error : 'SEO-Felder konnten nicht per AI erzeugt werden.');
                    }

                    if (!metadata || typeof metadata !== 'object' || Array.isArray(metadata)) {
                        throw new Error('Die AI-Antwort enthält keinen gültigen SEO-Feldentwurf.');
                    }

                    hasExistingValues = seoMetadataHasExistingValues(aiSeoMetadata);
                    if (hasExistingValues && !window.confirm('Bestehende inhaltsbasierte SEO-Felder werden durch den AI-Entwurf ersetzt. Haupttitel, Slug und URL-Felder bleiben unverändert. Fortfahren?')) {
                        showNotice('info', 'SEO-AI-Entwurf wurde verworfen. Die vorhandenen Felder blieben unverändert.');
                        return;
                    }

                    appliedFieldCount = applySeoMetadata(aiSeoMetadata, metadata);
                    if (appliedFieldCount === 0) {
                        throw new Error('Der AI-Entwurf enthält keine übernehmbaren SEO-Felder.');
                    }

                    showNotice(
                        'success',
                        (result.message || 'SEO-Felder wurden aus dem Haupttext erzeugt.')
                            + ' ' + String(appliedFieldCount) + ' Felder wurden in den ungespeicherten Entwurf übernommen.'
                            + ' Dauerhaft gespeichert wird erst mit dem normalen Formular-Submit.'
                            + truncatedHint
                    );
                }).catch(function (error) {
                    showNotice('danger', (error && error.message) ? error.message : 'SEO-Felder konnten nicht per AI erzeugt werden.');
                }).finally(function () {
                    setButtonBusy(button, false);
                });
            });
        }

        (Array.isArray(config.editors) ? config.editors : []).forEach(function (definition) {
            editorDefinitions[definition.key] = definition;
            getPlainEditorState(definition, getElement(definition.inputId));
            setEditorStateMarker(definition, 'loading');

            if (!definition.lazy) {
                bindEditor(definition);
            }

            if (definition.lazy && definition.activateButtonId) {
                var trigger = getElement(definition.activateButtonId);
                if (trigger) {
                    trigger.addEventListener('click', function () {
                        var targetInput = getElement(definition.inputId);
                        var initialCopy = config.initialCopyOnFirstActivate || null;
                        var lazyBindingPromise;

                        if (initialCopy
                            && !suppressInitialCopyForKeys[definition.key]
                            && definition.key === initialCopy.targetKey
                            && targetInput
                            && isEditorInputEmpty(targetInput)) {
                            lazyBindingPromise = ensureEditorSaved(initialCopy.sourceKey).then(function (sourceData) {
                                targetInput.value = JSON.stringify(normalizeEditorData(sourceData));
                                emitChangeEvents(targetInput);
                                bindEditor(definition);
                                return ensureEditorReady(definition.key);
                            }).catch(function () {
                                bindEditor(definition);
                                return ensureEditorReady(definition.key);
                            });

                            trackPendingLazyBinding(definition.key, lazyBindingPromise);
                            return;
                        }

                        lazyBindingPromise = Promise.resolve().then(function () {
                            bindEditor(definition);
                            return ensureEditorReady(definition.key);
                        });

                        trackPendingLazyBinding(definition.key, lazyBindingPromise);
                    });
                }
            }
        });

        if (config.copyAction) {
            handleCopyAction(config.copyAction);
        }

        if (config.aiTranslation) {
            registerPreviewInvalidation(config.aiTranslation);
            handleAiTranslation(config.aiTranslation);
        }

        if (config.aiSeoMetadata) {
            handleAiSeoMetadata(config.aiSeoMetadata);
        }

        form.addEventListener('click', function (event) {
            var target = event && event.target && typeof event.target.closest === 'function'
                ? event.target.closest('button, input[type="submit"], input[type="image"]')
                : null;

            if (isFormSubmitter(target)) {
                pendingSubmitter = target;
            }
        }, true);

        form.addEventListener('submit', function (event) {
            var keys = Object.keys(editorDefinitions);
            var submitter = event && event.submitter ? event.submitter : pendingSubmitter;

            if (nativeSubmitPending) {
                return;
            }

            if (keys.length === 0) {
                pendingSubmitter = null;
                return;
            }

            event.preventDefault();

            if (submitLocked) {
                return;
            }

            clearNotice();

            submitLocked = true;
            syncAllPlainEditors();

            Promise.all(keys.map(function (key) {
                return saveEditorContent(key, false);
            })).then(function () {
                submitLocked = false;
                dispatchValidatedSubmit(submitter);
            }).catch(function (error) {
                var failedDefinition = error && error.editorDefinition ? error.editorDefinition : null;
                var message = error && error.message
                    ? error.message
                    : 'Der Editor-Inhalt konnte nicht gespeichert werden. Bitte Eingaben prüfen und erneut versuchen.';

                if (failedDefinition && failedDefinition.activateButtonId) {
                    activateTargetPane(failedDefinition.activateButtonId, failedDefinition.key);
                }

                if (typeof console !== 'undefined' && typeof console.error === 'function') {
                    console.error('Editor.js-Speichern vor Formular-Submit fehlgeschlagen.', error);
                }

                submitLocked = false;
                pendingSubmitter = null;
                showNotice('danger', message);
            });
        });
    }

    function waitForEditorJsCore(editorJsConfig) {
        var hasEditorDefinitions = !!(editorJsConfig && Array.isArray(editorJsConfig.editors) && editorJsConfig.editors.length > 0);
        var MAX_WAIT_MS = 6000;
        var POLL_MS = 80;
        var readyPromise = window.cmsEditorJsCoreReady;
        var startedAt = Date.now();

        function hasEditorRuntime() {
            return typeof window.EditorJS === 'function' && typeof window.createCmsEditor === 'function';
        }

        function waitForRuntimePolling() {
            if (hasEditorRuntime()) {
                return Promise.resolve(true);
            }

            if (Date.now() - startedAt >= MAX_WAIT_MS) {
                return Promise.resolve(false);
            }

            return new Promise(function (resolve) {
                window.setTimeout(resolve, POLL_MS);
            }).then(waitForRuntimePolling);
        }

        if (!hasEditorDefinitions) {
                logEditor('info', '[EJS-CHAIN-NO-EDITORS] No EditorJS definitions found in config.');
            return Promise.resolve(true);
        }

        if (hasEditorRuntime()) {
                logEditor('info', '[EJS-CHAIN-RUNTIME-READY] Runtime already available before wait.');
            return Promise.resolve(true);
        }

        if (!readyPromise || typeof readyPromise.then !== 'function') {
                logEditor('warn', '[EJS-CHAIN-PROMISE-MISSING] cmsEditorJsCoreReady missing, using polling fallback.');
            return waitForRuntimePolling();
        }

            logEditor('info', '[EJS-CHAIN-WAIT-START] Waiting for cmsEditorJsCoreReady promise.');
        return readyPromise.then(function () {
                logEditor('info', '[EJS-CHAIN-WAIT-PROMISE-RESOLVED] Core promise resolved.');
            return waitForRuntimePolling();
        }).catch(function (error) {
            if (typeof console !== 'undefined' && typeof console.error === 'function') {
                    console.error('[EditorJS][EJS-CHAIN-WAIT-PROMISE-FAILED] Core readiness failed.', error);
            }

            return waitForRuntimePolling();
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        var uiConfig = parseJsonInput('contentEditorUiConfig', null);
        var seoConfig = parseJsonInput('contentEditorSeoConfig', null);
        var editorJsConfig = parseJsonInput('contentEditorEditorJsConfig', null);

        if (!uiConfig) {
            return;
        }

        initUnsavedChangesGuard(uiConfig);
        enforceAccordionDefaults();
        initUi(uiConfig);
        initSeo(seoConfig);
        waitForEditorJsCore(editorJsConfig).then(function () {
            logEditor('info', '[EJS-CHAIN-INIT-START] Starting EditorJS init after wait.');
            initEditorJs(editorJsConfig);
            initLanguageTabCompleteness(editorJsConfig);
        }, function () {
            logEditor('warn', '[EJS-CHAIN-INIT-FALLBACK] Wait rejected, trying init anyway.');
            initEditorJs(editorJsConfig);
            initLanguageTabCompleteness(editorJsConfig);
        });
    });
})();
