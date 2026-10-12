/**
 * Admin Media Integrations
 *
 * Aktiviert die native 365CMS-Medienbibliothek, Upload-Queues
 * und den internen Bild-/Logo-Picker im Admin.
 */
(function () {
    'use strict';

    function normalizeMediaValue(value) {
        if (!value) {
            return '';
        }

        if (/^https?:\/\//i.test(value)) {
            try {
                var url = new URL(value, window.location.origin);
                if (url.origin === window.location.origin) {
                    return url.pathname + url.search + url.hash;
                }
            } catch (error) {
                return value;
            }
        }

        return value;
    }

    function createPreviewMarkup(previewElement, value) {
        var variant = previewElement.dataset.previewVariant || 'logo';
        clearElement(previewElement);

        if (!value) {
            previewElement.hidden = true;
            return;
        }

        var image = document.createElement('img');
        image.src = value;
        image.alt = variant === 'favicon' ? 'Favicon Vorschau' : 'Website-Logo Vorschau';
        image.style.border = '1px solid var(--tblr-border-color)';
        image.style.background = '#fff';

        if (variant === 'favicon') {
            previewElement.className = 'mt-2 d-flex align-items-center gap-2';
            image.width = 32;
            image.height = 32;
            image.style.borderRadius = '8px';
            image.style.padding = '4px';
            image.style.objectFit = 'contain';

            var code = document.createElement('code');
            code.textContent = value;
            previewElement.appendChild(image);
            previewElement.appendChild(code);
        } else {
            previewElement.className = 'mt-2';
            image.style.maxHeight = '48px';
            image.style.maxWidth = '220px';
            image.style.borderRadius = '6px';
            image.style.padding = '6px';
            previewElement.appendChild(image);
        }

        previewElement.hidden = false;
    }

    function updateLinkedPreview(input, previewId) {
        var previewElement = null;

        if (previewId) {
            previewElement = document.getElementById(previewId);
        }

        if (!previewElement && input && input.id) {
            previewElement = document.querySelector('[data-media-preview][data-input-id="' + input.id + '"]');
        }

        if (!previewElement) {
            return;
        }

        createPreviewMarkup(previewElement, input.value.trim());
    }

    function showMessage(type, message) {
        if (typeof window.cmsAlert === 'function') {
            window.cmsAlert(type, message);
            return;
        }
        console[type === 'danger' ? 'error' : 'log'](message);
    }

    function setElementText(element, text) {
        if (element) {
            element.textContent = text;
        }
    }

    function setElementHidden(element, hidden) {
        if (element) {
            element.hidden = hidden;
        }
    }

    function setSubmittingState(form, isSubmitting) {
        if (!form) {
            return;
        }

        form.dataset.submitting = isSubmitting ? '1' : '0';
        form.querySelectorAll('button, input[type="submit"]').forEach(function (element) {
            element.disabled = isSubmitting;
            element.setAttribute('aria-disabled', isSubmitting ? 'true' : 'false');
        });
    }

    function submitWithTemporarySubmitter(form) {
        var submitter;

        if (!form) {
            return false;
        }

        if (typeof form.requestSubmit === 'function') {
            form.requestSubmit();
            return true;
        }

        submitter = document.createElement('button');
        submitter.type = 'submit';
        submitter.hidden = true;
        submitter.tabIndex = -1;
        submitter.setAttribute('aria-hidden', 'true');
        form.appendChild(submitter);
        submitter.click();
        form.removeChild(submitter);

        return true;
    }

    function copyInputValue(input) {
        var value = '';
        var copied = false;

        if (!(input instanceof HTMLInputElement) && !(input instanceof HTMLTextAreaElement)) {
            return Promise.reject(new Error('Kein kopierbares Eingabefeld gefunden.'));
        }

        value = String(input.value || '').trim();
        if (!value) {
            return Promise.reject(new Error('Es gibt keinen Link zum Kopieren.'));
        }

        if (window.isSecureContext && navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
            return navigator.clipboard.writeText(value);
        }

        input.focus();
        input.select();
        if (typeof input.setSelectionRange === 'function') {
            input.setSelectionRange(0, value.length);
        }

        try {
            copied = document.execCommand('copy');
        } catch (error) {
            copied = false;
        }

        return copied
            ? Promise.resolve()
            : Promise.reject(new Error('Link konnte nicht automatisch kopiert werden.'));
    }

    function clearElement(element) {
        if (!element) {
            return;
        }

        while (element.firstChild) {
            element.removeChild(element.firstChild);
        }
    }

    function appendStatusAlert(container, alertClass, message) {
        var column;
        var alert;

        if (!container) {
            return;
        }

        clearElement(container);

        column = document.createElement('div');
        column.className = 'col-12';

        alert = document.createElement('div');
        alert.className = alertClass;
        alert.textContent = String(message || '');

        column.appendChild(alert);
        container.appendChild(column);
    }

    function fetchJson(url, options) {
        return fetch(url, options).then(function (response) {
            return response.json().catch(function () {
                return {};
            }).then(function (payload) {
                if (!response.ok) {
                    var error = new Error(payload && payload.error ? payload.error : 'Anfrage fehlgeschlagen.');
                    error.payload = payload;
                    throw error;
                }

                return payload;
            });
        });
    }

    function setFormSubmitting(form, isSubmitting) {
        if (!form) {
            return;
        }

        form.querySelectorAll('button, input[type="submit"], input[type="file"]').forEach(function (element) {
            element.disabled = isSubmitting;
            element.setAttribute('aria-disabled', isSubmitting ? 'true' : 'false');
        });
    }

    function renderUploadResult(container, type, message) {
        if (!container) {
            return;
        }

        var item = document.createElement('div');
        item.className = 'list-group-item d-flex justify-content-between align-items-start gap-3';

        var content = document.createElement('div');
        var title = document.createElement('div');
        title.className = 'fw-semibold text-' + (type === 'success' ? 'green' : 'danger');
        title.textContent = String(message.title || '');

        var detail = document.createElement('div');
        detail.className = 'text-secondary small';
        detail.textContent = String(message.detail || '');

        content.appendChild(title);
        content.appendChild(detail);

        var badge = document.createElement('span');
        badge.className = 'badge ' + (type === 'success' ? 'bg-green-lt' : 'bg-red-lt');
        badge.textContent = type === 'success' ? 'OK' : 'Fehler';

        item.appendChild(content);
        item.appendChild(badge);
        container.appendChild(item);
    }

    function resolveParentPath(path) {
        var normalized = String(path || '').replace(/\\/g, '/').replace(/^\/+|\/+$/g, '');
        if (!normalized) {
            return '';
        }

        var segments = normalized.split('/');
        segments.pop();

        return segments.join('/');
    }

    function redirectToCurrentMediaPath(targetPath) {
        var url = new URL(window.location.href);
        if (targetPath) {
            url.searchParams.set('path', targetPath);
        } else {
            url.searchParams.delete('path');
        }

        window.location.assign(url.toString());
    }

    function initNativeUploader() {
        var form = document.getElementById('uploadForm');
        var input = document.getElementById('uploadFiles');
        if (!form || !input || typeof window.fetch !== 'function') {
            return;
        }

        var statusElement = form.querySelector('[data-upload-status]');
        var resultsElement = form.querySelector('[data-upload-results]');

        form.addEventListener('submit', function (event) {
            var files = Array.prototype.slice.call(input.files || []);
            var uploadUrl = input.dataset.uploadUrl || '';
            var uploadPath = input.dataset.uploadPath || '';
            var csrfToken = input.dataset.csrfToken || '';

            if (!files.length || !uploadUrl || !csrfToken) {
                return;
            }

            event.preventDefault();

            if (resultsElement) {
                clearElement(resultsElement);
                setElementHidden(resultsElement, false);
            }

            setElementHidden(statusElement, false);
            setElementText(statusElement, 'Upload läuft …');
            setFormSubmitting(form, true);

            var successCount = 0;
            var errorCount = 0;
            var effectiveTargetPath = uploadPath;
            var sequence = Promise.resolve();

            files.forEach(function (file) {
                sequence = sequence.then(function () {
                    var formData = new FormData();
                    formData.append('file', file, file.name);
                    formData.append('path', uploadPath);
                    formData.append('csrf_token', csrfToken);

                    return fetchJson(uploadUrl, {
                        method: 'POST',
                        body: formData,
                        credentials: 'same-origin'
                    }).then(function (payload) {
                        if (payload && payload.new_token) {
                            csrfToken = String(payload.new_token);
                            input.dataset.csrfToken = csrfToken;
                        }

                        successCount += 1;
                        if (payload && payload.path) {
                            effectiveTargetPath = resolveParentPath(payload.path);
                        }
                        renderUploadResult(resultsElement, 'success', {
                            title: file.name,
                            detail: payload && payload.path ? payload.path : 'Datei erfolgreich hochgeladen.'
                        });
                    }).catch(function (error) {
                        if (error && error.payload && error.payload.new_token) {
                            csrfToken = String(error.payload.new_token);
                            input.dataset.csrfToken = csrfToken;
                        }

                        errorCount += 1;
                        renderUploadResult(resultsElement, 'danger', {
                            title: file.name,
                            detail: error && error.message ? error.message : 'Upload fehlgeschlagen.'
                        });
                    });
                });
            });

            sequence.finally(function () {
                setFormSubmitting(form, false);

                if (successCount > 0 && errorCount === 0) {
                    setElementText(statusElement, successCount + ' Datei(en) erfolgreich hochgeladen. Bibliothek wird aktualisiert …');
                    window.setTimeout(function () {
                        redirectToCurrentMediaPath(effectiveTargetPath);
                    }, 700);
                    return;
                }

                setElementText(statusElement, successCount + ' erfolgreich, ' + errorCount + ' fehlgeschlagen.');
                showMessage(errorCount > 0 ? 'danger' : 'success', successCount > 0
                    ? 'Upload abgeschlossen.'
                    : 'Upload fehlgeschlagen.');
            });
        });
    }

    function initMediaPickers() {
        var modalElement = document.getElementById('settingsMediaPickerModal');
        var openButtons = document.querySelectorAll('[data-open-media-picker]');
        var clearButtons = document.querySelectorAll('[data-clear-media-input]');
        var activeContext = null;
        var pickerState = {
            items: [],
            loaded: false,
            query: ''
        };

        document.querySelectorAll('[data-media-preview]').forEach(function (previewElement) {
            var inputId = previewElement.dataset.inputId || '';
            var input = inputId ? document.getElementById(inputId) : null;
            if (input) {
                updateLinkedPreview(input);
            }
        });

        if (!modalElement || !openButtons.length) {
            clearButtons.forEach(function (button) {
                button.addEventListener('click', function () {
                    var input = document.getElementById(button.dataset.targetInput || '');
                    if (!input) {
                        return;
                    }
                    input.value = '';
                    updateLinkedPreview(input, button.dataset.previewId || '');
                });
            });
            return;
        }

        var pickerContainer = modalElement.querySelector('[data-media-picker-modal]');
        var titleElement = modalElement.querySelector('[data-media-picker-title]');
        var gridElement = modalElement.querySelector('[data-media-picker-grid]');
        var statusElement = modalElement.querySelector('[data-media-picker-status]');
        var searchElement = modalElement.querySelector('[data-media-picker-search]');
        var bootstrapModal = window.bootstrap ? window.bootstrap.Modal.getOrCreateInstance(modalElement) : null;
        var apiUrl = pickerContainer ? (pickerContainer.dataset.apiUrl || '') : '';
        var csrfToken = pickerContainer ? (pickerContainer.dataset.csrfToken || '') : '';

        function renderPickerItems(items) {
            if (!gridElement) {
                return;
            }

            clearElement(gridElement);

            if (!items.length) {
                var emptyColumn = document.createElement('div');
                emptyColumn.className = 'col-12';

                var emptyAlert = document.createElement('div');
                emptyAlert.className = 'alert alert-secondary mb-0';
                emptyAlert.textContent = 'Keine passenden Medien gefunden.';

                emptyColumn.appendChild(emptyAlert);
                gridElement.appendChild(emptyColumn);
                return;
            }

            items.forEach(function (item) {
                var itemUrl = String(item && item.url ? item.url : '');
                var itemName = String(item && item.name ? item.name : 'Datei');
                var itemPath = String(item && item.path ? item.path : '');

                var column = document.createElement('div');
                column.className = 'col-sm-6 col-lg-4';

                var button = document.createElement('button');
                button.type = 'button';
                button.className = 'card card-link w-100 text-start';
                button.setAttribute('data-media-picker-select', '1');
                button.setAttribute('data-media-url', itemUrl);

                var cardBody = document.createElement('div');
                cardBody.className = 'card-body';

                var preview = document.createElement('div');
                preview.className = 'ratio ratio-4x3 mb-3 rounded overflow-hidden bg-light d-flex align-items-center justify-content-center';

                var image = document.createElement('img');
                image.src = itemUrl;
                image.alt = itemName;
                image.className = 'w-100 h-100';
                image.style.objectFit = 'cover';

                var name = document.createElement('div');
                name.className = 'fw-semibold text-truncate';
                name.textContent = itemName;

                var path = document.createElement('div');
                path.className = 'text-secondary small text-break';
                path.textContent = itemPath;

                preview.appendChild(image);
                cardBody.appendChild(preview);
                cardBody.appendChild(name);
                cardBody.appendChild(path);
                button.appendChild(cardBody);
                column.appendChild(button);
                gridElement.appendChild(column);
            });
        }

        function filterPickerItems() {
            var query = pickerState.query;
            var items = pickerState.items;

            if (!query) {
                renderPickerItems(items);
                setElementText(statusElement, items.length + ' Medien verfügbar');
                return;
            }

            var filteredItems = items.filter(function (item) {
                var haystack = String(item.name || '').toLowerCase() + ' ' + String(item.path || '').toLowerCase();
                return haystack.indexOf(query) !== -1;
            });

            renderPickerItems(filteredItems);
            setElementText(statusElement, filteredItems.length + ' Treffer');
        }

        function loadPickerItems() {
            if (!pickerContainer || !apiUrl || !csrfToken) {
                appendStatusAlert(gridElement, 'alert alert-warning mb-0', 'Medienpicker konnte nicht geladen werden.');
                setElementText(statusElement, 'Konfiguration unvollständig');
                return Promise.resolve();
            }

            setElementText(statusElement, 'Lade Medien …');

            return fetchJson(apiUrl + '?action=list_images', {
                method: 'GET',
                headers: {
                    'X-CSRF-Token': csrfToken
                },
                credentials: 'same-origin'
            }).then(function (payload) {
                pickerState.items = Array.isArray(payload.items) ? payload.items : [];
                pickerState.loaded = true;
                filterPickerItems();
            }).catch(function (error) {
                if (gridElement) {
                    clearElement(gridElement);
                    var errorColumn = document.createElement('div');
                    errorColumn.className = 'col-12';

                    var errorAlert = document.createElement('div');
                    errorAlert.className = 'alert alert-warning mb-0';
                    errorAlert.textContent = String(error && error.message ? error.message : 'Medien konnten nicht geladen werden.');

                    errorColumn.appendChild(errorAlert);
                    gridElement.appendChild(errorColumn);
                }
                setElementText(statusElement, 'Laden fehlgeschlagen');
            });
        }

        openButtons.forEach(function (button) {
            button.addEventListener('click', function () {
                var input = document.getElementById(button.dataset.targetInput || '');
                if (!input) {
                    return;
                }

                activeContext = {
                    input: input,
                    previewId: button.dataset.previewId || ''
                };

                if (titleElement) {
                    titleElement.textContent = button.dataset.pickerTitle || 'Datei auswählen';
                }

                if (!pickerState.loaded) {
                    loadPickerItems();
                } else {
                    filterPickerItems();
                }

                if (bootstrapModal) {
                    bootstrapModal.show();
                }
            });
        });

        if (searchElement) {
            searchElement.addEventListener('input', function () {
                pickerState.query = String(searchElement.value || '').trim().toLowerCase();
                filterPickerItems();
            });
        }

        if (gridElement) {
            gridElement.addEventListener('click', function (event) {
                var button = event.target.closest('[data-media-picker-select="1"]');
                if (!button || !activeContext || !activeContext.input) {
                    return;
                }

                var selectedValue = normalizeMediaValue(button.getAttribute('data-media-url') || '');
                if (!selectedValue) {
                    return;
                }

                activeContext.input.value = selectedValue;
                updateLinkedPreview(activeContext.input, activeContext.previewId || '');

                if (bootstrapModal) {
                    bootstrapModal.hide();
                }
            });
        }

        clearButtons.forEach(function (button) {
            button.addEventListener('click', function () {
                var input = document.getElementById(button.dataset.targetInput || '');
                if (!input) {
                    return;
                }

                input.value = '';
                updateLinkedPreview(input, button.dataset.previewId || '');
            });
        });

        modalElement.addEventListener('hidden.bs.modal', function () {
            activeContext = null;
        });
    }

    function initCopyInputButtons() {
        document.querySelectorAll('[data-copy-input-target]').forEach(function (button) {
            var defaultLabel = String(button.textContent || '').trim() || 'Kopieren';
            var successLabel = String(button.dataset.copySuccessLabel || 'Kopiert').trim() || 'Kopiert';

            button.addEventListener('click', function () {
                var selector = button.dataset.copyInputTarget || '';
                var input = selector ? document.querySelector(selector) : null;

                copyInputValue(input).then(function () {
                    button.textContent = successLabel;

                    if (button._copyResetTimer) {
                        window.clearTimeout(button._copyResetTimer);
                    }

                    button._copyResetTimer = window.setTimeout(function () {
                        button.textContent = defaultLabel;
                    }, 1600);
                }).catch(function (error) {
                    showMessage('danger', error && error.message ? error.message : 'Link konnte nicht kopiert werden.');
                    if (input && (input instanceof HTMLInputElement || input instanceof HTMLTextAreaElement)) {
                        input.focus();
                        input.select();
                    }
                });
            });
        });
    }

    function parseConfig(id) {
        var element = document.getElementById(id);
        if (!element) {
            return null;
        }

        try {
            return JSON.parse(element.textContent || '{}');
        } catch (error) {
            console.error('Media-Konfiguration konnte nicht gelesen werden.', error);
            return null;
        }
    }

    function initMediaLibraryActions() {
        var config = parseConfig('media-library-config') || {};
        var currentPath = config.currentPath || '';
        var deleteFormId = config.deleteFormId || 'deleteMediaForm';
        var deletePathFieldId = config.deletePathFieldId || 'deleteMediaPath';
        var memberFolderConfirmMessage = config.memberFolderConfirmMessage || 'Diesen Ordner wirklich öffnen?';
        var renameModalId = config.renameModalId || 'mediaRenameModal';
        var renamePathFieldId = config.renamePathFieldId || 'mediaRenamePath';
        var renameNameFieldId = config.renameNameFieldId || 'mediaRenameName';
        var renameLabelId = config.renameLabelId || 'mediaRenameItemLabel';
        var moveModalId = config.moveModalId || 'mediaMoveModal';
        var movePathFieldId = config.movePathFieldId || 'mediaMovePath';
        var moveTargetFieldId = config.moveTargetFieldId || 'mediaMoveTarget';
        var moveLabelId = config.moveLabelId || 'mediaMoveItemLabel';
        var bulkRootSelector = config.bulkRootSelector || '[data-media-library-root]';
        var bulkFormId = config.bulkFormId || 'mediaBulkForm';
        var bulkCountId = config.bulkCountId || 'mediaBulkSelectedCount';
        var bulkActionFieldId = config.bulkActionFieldId || 'mediaBulkAction';
        var bulkMoveWrapId = config.bulkMoveWrapId || 'mediaBulkMoveWrap';
        var bulkMoveTargetFieldId = config.bulkMoveTargetFieldId || 'mediaBulkTarget';
        var bulkCategoryWrapId = config.bulkCategoryWrapId || 'mediaBulkCategoryWrap';
        var bulkCategoryFieldId = config.bulkCategoryFieldId || 'mediaBulkCategory';
        var bulkTagsWrapId = config.bulkTagsWrapId || 'mediaBulkTagsWrap';
        var bulkTagsFieldId = config.bulkTagsFieldId || 'mediaBulkTags';
        var deleteForm = document.getElementById(deleteFormId);
        var deletePathField = document.getElementById(deletePathFieldId);
        var renameModalElement = document.getElementById(renameModalId);
        var renamePathField = document.getElementById(renamePathFieldId);
        var renameNameField = document.getElementById(renameNameFieldId);
        var renameLabel = document.getElementById(renameLabelId);
        var moveModalElement = document.getElementById(moveModalId);
        var movePathField = document.getElementById(movePathFieldId);
        var moveTargetField = document.getElementById(moveTargetFieldId);
        var moveLabel = document.getElementById(moveLabelId);
        var bulkRoot = document.querySelector(bulkRootSelector);
        var bulkForm = document.getElementById(bulkFormId);
        var bulkFormWrap = document.getElementById('mediaBulkFormWrap');
        var bulkCount = document.getElementById(bulkCountId);
        var bulkActionField = document.getElementById(bulkActionFieldId);
        var bulkMoveWrap = document.getElementById(bulkMoveWrapId);
        var bulkMoveTargetField = document.getElementById(bulkMoveTargetFieldId);
        var bulkCategoryWrap = document.getElementById(bulkCategoryWrapId);
        var bulkCategoryField = document.getElementById(bulkCategoryFieldId);
        var bulkTagsWrap = document.getElementById(bulkTagsWrapId);
        var bulkTagsField = document.getElementById(bulkTagsFieldId);
        var bulkSubmitButton = bulkForm ? bulkForm.querySelector('button[type="submit"]') : null;
        var mediaSizeColumnLabel = document.getElementById('mediaSizeColumnLabel');
        var mediaFilterForm = document.querySelector('[data-media-filter-form]');
        var advancedFilterToggle = mediaFilterForm ? mediaFilterForm.querySelector('[data-media-advanced-toggle="1"]') : null;
        var advancedFilterPanel = mediaFilterForm ? mediaFilterForm.querySelector('[data-media-advanced-panel="1"]') : null;
        var selectedPaths = new Set();

        function updateBulkSubmitButton() {
            if (!bulkSubmitButton) {
                return;
            }

            var selectedCount = selectedPaths.size;
            var action = bulkActionField ? String(bulkActionField.value || '') : '';
            var requiresMoveTarget = action === 'move';
            var requiresTags = action === 'tag_add' || action === 'tag_replace' || action === 'tag_remove';
            var hasValidAction = ['delete', 'move', 'assign_category', 'tag_add', 'tag_replace', 'tag_remove', 'tag_clear', 'alt_text_update'].indexOf(action) !== -1;
            var hasValidTarget = !requiresMoveTarget || !bulkMoveTargetField || !!bulkMoveTargetField.value;
            var hasValidTags = !requiresTags || !bulkTagsField || String(bulkTagsField.value || '').trim() !== '';
            var enabled = selectedCount > 0 && hasValidAction && hasValidTarget && hasValidTags;

            bulkSubmitButton.textContent = 'Ausführen';
            bulkSubmitButton.disabled = !enabled;
            bulkSubmitButton.setAttribute('aria-disabled', enabled ? 'false' : 'true');
        }

        function updateFolderOnlyView() {
            var mediaTable = bulkRoot ? bulkRoot.querySelector('.media-table') : null;
            var tableContainer = mediaTable ? mediaTable.closest('.table-responsive') : null;
            var rows;
            var hasRows;
            var onlyFolders;

            if (!mediaTable || !tableContainer) {
                return;
            }

            rows = Array.prototype.slice.call(mediaTable.querySelectorAll('tbody tr')).filter(function (row) {
                return row.offsetParent !== null;
            });
            hasRows = rows.length > 0;
            onlyFolders = hasRows && rows.every(function (row) {
                return row.getAttribute('data-media-row-type') === 'folder';
            });

            tableContainer.classList.toggle('folder-only-view', onlyFolders);
            mediaTable.classList.toggle('folder-only-view', onlyFolders);

            if (mediaSizeColumnLabel) {
                mediaSizeColumnLabel.textContent = onlyFolders ? 'Dateien' : 'Größe';
            }
        }

        function updateAdvancedFilterToggleLabel() {
            if (!advancedFilterToggle || !advancedFilterPanel) {
                return;
            }

            var isOpen = advancedFilterPanel.classList.contains('is-open');
            advancedFilterToggle.textContent = 'Erweiterte Filter ' + (isOpen ? '▴' : '▾');
            advancedFilterToggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        }

        function bindAdvancedFilterToggle() {
            if (!advancedFilterToggle || !advancedFilterPanel) {
                return;
            }

            advancedFilterToggle.addEventListener('click', function () {
                advancedFilterPanel.classList.toggle('is-open');
                updateAdvancedFilterToggleLabel();
            });

            updateAdvancedFilterToggleLabel();
        }

        function collectSelectedPaths() {
            if (!bulkRoot) {
                return new Set();
            }

            var values = new Set();
            bulkRoot.querySelectorAll('.bulk-row-check').forEach(function (checkbox) {
                if (checkbox.checked) {
                    values.add(String(checkbox.value));
                }
            });

            return values;
        }

        function populateRenameModal(trigger) {
            if (!trigger || !renamePathField || !renameNameField) {
                return;
            }

            var itemPath = trigger.getAttribute('data-media-path') || '';
            var itemName = trigger.getAttribute('data-media-name') || 'Element';
            renamePathField.value = itemPath;
            renameNameField.value = itemName;
            if (renameLabel) {
                renameLabel.textContent = itemName;
            }
        }

        function populateMoveModal(trigger) {
            if (!trigger || !movePathField || !moveTargetField) {
                return;
            }

            var itemPath = trigger.getAttribute('data-media-path') || '';
            var itemName = trigger.getAttribute('data-media-name') || 'Element';
            var targetPath = trigger.getAttribute('data-media-target') || currentPath;
            movePathField.value = itemPath;
            moveTargetField.value = targetPath;
            if (moveTargetField.value !== targetPath && moveTargetField.options.length > 0) {
                moveTargetField.selectedIndex = 0;
            }
            if (moveLabel) {
                moveLabel.textContent = itemName;
            }
        }

        function setDropdownOverflow(open) {
            document.querySelectorAll('.table-responsive').forEach(function (container) {
                container.style.overflow = open ? 'visible' : '';
            });
        }

        document.querySelectorAll('.dropdown').forEach(function (dropdown) {
            dropdown.addEventListener('show.bs.dropdown', function () {
                setDropdownOverflow(true);
            });

            dropdown.addEventListener('hidden.bs.dropdown', function () {
                setDropdownOverflow(false);
            });
        });

        document.querySelectorAll('[data-member-folder-confirm="1"]').forEach(function (link) {
            link.addEventListener('click', function (event) {
                var targetUrl = link.getAttribute('data-confirm-url') || link.getAttribute('href') || '';
                if (!targetUrl) {
                    return;
                }

                event.preventDefault();
                if (window.confirm(memberFolderConfirmMessage)) {
                    window.location.href = targetUrl;
                }
            });
        });

        // Fallback: store the triggering button on click, in case Bootstrap's
        // show.bs.modal event.relatedTarget is null (can happen when trigger is
        // inside a closing dropdown menu).
        var pendingRenameTrigger = null;
        var pendingMoveTrigger = null;

        document.querySelectorAll('.js-media-open-rename').forEach(function (btn) {
            btn.addEventListener('click', function () { pendingRenameTrigger = btn; });
        });

        document.querySelectorAll('.js-media-open-move').forEach(function (btn) {
            btn.addEventListener('click', function () { pendingMoveTrigger = btn; });
        });

        if (renameModalElement) {
            renameModalElement.addEventListener('show.bs.modal', function (event) {
                var trigger = event.relatedTarget || pendingRenameTrigger;
                pendingRenameTrigger = null;
                populateRenameModal(trigger);
            });

            renameModalElement.addEventListener('shown.bs.modal', function () {
                if (!renameNameField) {
                    return;
                }

                window.setTimeout(function () {
                    renameNameField.focus();
                    renameNameField.select();
                }, 50);
            });
        }

        if (moveModalElement) {
            moveModalElement.addEventListener('show.bs.modal', function (event) {
                var trigger = event.relatedTarget || pendingMoveTrigger;
                pendingMoveTrigger = null;
                populateMoveModal(trigger);
            });
        }

        if (deleteForm && deletePathField) {
            function submitDelete(path) {
                deletePathField.value = path;
                submitWithTemporarySubmitter(deleteForm);
            }

            document.querySelectorAll('.js-media-delete').forEach(function (button) {
                button.addEventListener('click', function () {
                    var path = button.dataset.deletePath || '';
                    var name = button.dataset.deleteName || 'Element';
                    var itemType = button.dataset.deleteType || 'Element';
                    var message = name + ' wirklich löschen?';

                    if (!path) {
                        return;
                    }

                    if (typeof cmsConfirm === 'function') {
                        cmsConfirm({
                            title: itemType + ' löschen',
                            message: message,
                            confirmText: 'Löschen',
                            confirmClass: 'btn-danger',
                            onConfirm: function () {
                                submitDelete(path);
                            }
                        });
                        return;
                    }

                    if (window.confirm(message)) {
                        submitDelete(path);
                    }
                });
            });
        }

        function syncBulkCheckboxes() {
            if (!bulkRoot) {
                return;
            }

            bulkRoot.querySelectorAll('.bulk-row-check').forEach(function (checkbox) {
                checkbox.checked = selectedPaths.has(String(checkbox.value));
            });

            var rowCheckboxes = Array.prototype.slice.call(bulkRoot.querySelectorAll('.bulk-row-check'));
            var allSelected = rowCheckboxes.length > 0 && rowCheckboxes.every(function (checkbox) {
                return checkbox.checked;
            });

            bulkRoot.querySelectorAll('.bulk-select-all').forEach(function (checkbox) {
                checkbox.checked = allSelected;
            });
        }

        function updateBulkActionUi() {
            if (!bulkActionField) {
                updateBulkSubmitButton();
                return;
            }

            var requiresMoveTarget = bulkActionField.value === 'move';
            var requiresCategory = bulkActionField.value === 'assign_category';
            var requiresTags = bulkActionField.value === 'tag_add' || bulkActionField.value === 'tag_replace' || bulkActionField.value === 'tag_remove';

            if (bulkMoveWrap) {
                bulkMoveWrap.classList.toggle('d-none', !requiresMoveTarget);
            }
            if (bulkCategoryWrap) {
                bulkCategoryWrap.classList.toggle('d-none', !requiresCategory);
            }
            if (bulkTagsWrap) {
                bulkTagsWrap.classList.toggle('d-none', !requiresTags);
            }

            if (bulkMoveTargetField) {
                bulkMoveTargetField.disabled = !requiresMoveTarget;
                if (requiresMoveTarget && bulkMoveTargetField.value === '' && bulkMoveTargetField.options.length > 0) {
                    bulkMoveTargetField.value = currentPath;
                    if (bulkMoveTargetField.value === '' && bulkMoveTargetField.options.length > 0) {
                        bulkMoveTargetField.selectedIndex = 0;
                    }
                }
            }

            if (bulkCategoryField) {
                bulkCategoryField.disabled = !requiresCategory;
            }

            if (bulkTagsField) {
                bulkTagsField.disabled = !requiresTags;
            }

            updateBulkSubmitButton();
        }

        function updateBulkUi() {
            selectedPaths = collectSelectedPaths();

            if (bulkCount) {
                bulkCount.textContent = String(selectedPaths.size);
            }

            if (bulkFormWrap) {
                bulkFormWrap.classList.toggle('d-none', selectedPaths.size === 0);
            }

            syncBulkCheckboxes();
            updateBulkActionUi();
            updateFolderOnlyView();
        }

        if (bulkRoot) {
            bulkRoot.addEventListener('change', function (event) {
                var target = event.target;
                if (!(target instanceof HTMLInputElement)) {
                    return;
                }

                if (target.classList.contains('bulk-row-check')) {
                    updateBulkUi();
                    return;
                }

                if (target.classList.contains('bulk-select-all')) {
                    bulkRoot.querySelectorAll('.bulk-row-check').forEach(function (checkbox) {
                        checkbox.checked = target.checked;
                    });
                    updateBulkUi();
                }
            });
        }

        if (bulkActionField) {
            bulkActionField.addEventListener('change', updateBulkActionUi);
        }

        if (bulkMoveTargetField) {
            bulkMoveTargetField.addEventListener('change', updateBulkSubmitButton);
        }

        if (bulkCategoryField) {
            bulkCategoryField.addEventListener('change', updateBulkSubmitButton);
        }

        if (bulkTagsField) {
            bulkTagsField.addEventListener('input', updateBulkSubmitButton);
        }

        bindAdvancedFilterToggle();

        document.querySelectorAll('[data-media-auto-submit-select="1"]').forEach(function (select) {
            select.addEventListener('change', function () {
                var form = select.form;

                if (!form || form.dataset.submitting === '1') {
                    return;
                }

                setSubmittingState(form, true);
                if (!submitWithTemporarySubmitter(form)) {
                    setSubmittingState(form, false);
                }
            });
        });

        if (bulkForm) {
            bulkForm.addEventListener('submit', function (event) {
                if (bulkForm.dataset.submitting === '1') {
                    event.preventDefault();
                    return;
                }

                selectedPaths = collectSelectedPaths();

                if (selectedPaths.size === 0) {
                    event.preventDefault();
                    setSubmittingState(bulkForm, false);
                    return;
                }

                if (!bulkActionField || !bulkActionField.value) {
                    event.preventDefault();
                    setSubmittingState(bulkForm, false);
                    if (bulkActionField) {
                        bulkActionField.focus();
                    }
                    return;
                }

                if (bulkActionField.value === 'move' && bulkMoveTargetField && !bulkMoveTargetField.value) {
                    event.preventDefault();
                    setSubmittingState(bulkForm, false);
                    bulkMoveTargetField.focus();
                    return;
                }

                if ((bulkActionField.value === 'tag_add' || bulkActionField.value === 'tag_replace' || bulkActionField.value === 'tag_remove') && bulkTagsField && String(bulkTagsField.value || '').trim() === '') {
                    event.preventDefault();
                    setSubmittingState(bulkForm, false);
                    bulkTagsField.focus();
                    return;
                }

                if (bulkActionField.value === 'delete' && !window.confirm('Die ausgewählten Medien wirklich löschen?')) {
                    event.preventDefault();
                    setSubmittingState(bulkForm, false);
                    return;
                }

                if ((bulkActionField.value === 'tag_replace' || bulkActionField.value === 'tag_clear') && !window.confirm('Die Tags der ausgewählten Dateien wirklich überschreiben bzw. entfernen?')) {
                    event.preventDefault();
                    setSubmittingState(bulkForm, false);
                    return;
                }

                setSubmittingState(bulkForm, true);
            });
        }

        if (window.bootstrap && typeof window.bootstrap.Tooltip === 'function') {
            document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function (element) {
                window.bootstrap.Tooltip.getOrCreateInstance(element);
            });
        }

        updateBulkUi();
    }

    function initMediaCategoryActions() {
        var config = parseConfig('media-categories-config') || {};
        var deleteFormId = config.deleteFormId || 'deleteCatForm';
        var deleteSlugFieldId = config.deleteSlugFieldId || 'deleteCatSlug';
        var deleteTitle = config.deleteTitle || 'Kategorie löschen';
        var deleteConfirmText = config.deleteConfirmText || 'Löschen';
        var deleteConfirmClass = config.deleteConfirmClass || 'btn-danger';
        var messageTemplate = config.deleteMessageTemplate || 'Kategorie {name} wirklich löschen?';
        var deleteForm = document.getElementById(deleteFormId);
        var deleteSlugField = document.getElementById(deleteSlugFieldId);

        if (!deleteForm || !deleteSlugField) {
            return;
        }

        function submitDelete(slug) {
            deleteSlugField.value = slug;
            submitWithTemporarySubmitter(deleteForm);
        }

        document.querySelectorAll('.js-media-category-delete').forEach(function (button) {
            button.addEventListener('click', function () {
                var slug = button.dataset.deleteSlug || '';
                var name = button.dataset.deleteName || 'Kategorie';
                var message = messageTemplate.replace('{name}', name);

                if (!slug) {
                    return;
                }

                if (typeof cmsConfirm === 'function') {
                    cmsConfirm({
                        title: deleteTitle,
                        message: message,
                        confirmText: deleteConfirmText,
                        confirmClass: deleteConfirmClass,
                        onConfirm: function () {
                            submitDelete(slug);
                        }
                    });
                    return;
                }

                if (window.confirm(message)) {
                    submitDelete(slug);
                }
            });
        });
    }

    function initFeaturedMediaReplace() {
        var forms = document.querySelectorAll('[data-featured-replace-form="1"]');
        var toggleButtons = document.querySelectorAll('[data-featured-toggle="1"]');
        var panels = document.querySelectorAll('[data-featured-panel="1"]');
        var activePanelId = '';

        if (!forms.length) {
            return;
        }

        function setToggleExpanded(button, isExpanded) {
            if (!button) {
                return;
            }

            button.setAttribute('aria-expanded', isExpanded ? 'true' : 'false');
            button.textContent = isExpanded ? 'Ersetzen ▴' : 'Ersetzen ▾';
        }

        function closePanel(panel) {
            var transitionDone;

            if (!panel || panel.hidden) {
                return;
            }

            transitionDone = function (event) {
                if (event && event.target !== panel) {
                    return;
                }

                panel.hidden = true;
                panel.style.maxHeight = '0px';
                panel.removeEventListener('transitionend', transitionDone);
            };

            panel.style.maxHeight = panel.scrollHeight + 'px';
            panel.classList.remove('is-open');

            window.requestAnimationFrame(function () {
                panel.style.maxHeight = '0px';
            });

            panel.addEventListener('transitionend', transitionDone);
        }

        function openPanel(panel) {
            var targetHeight;

            if (!panel) {
                return;
            }

            panel.hidden = false;
            panel.style.maxHeight = '0px';
            targetHeight = panel.scrollHeight;

            window.requestAnimationFrame(function () {
                panel.classList.add('is-open');
                panel.style.maxHeight = targetHeight + 'px';
            });
        }

        function closeAllPanels(exceptId) {
            panels.forEach(function (panel) {
                var shouldRemainOpen = exceptId && panel.id === exceptId;
                if (!shouldRemainOpen) {
                    closePanel(panel);
                }
            });

            toggleButtons.forEach(function (button) {
                var targetId = String(button.dataset.featuredToggleTarget || '');
                setToggleExpanded(button, !!exceptId && targetId === exceptId);
            });
        }

        function eventContainsFiles(event) {
            var dataTransfer = event && event.dataTransfer ? event.dataTransfer : null;
            var items;
            var types;

            if (!dataTransfer) {
                return false;
            }

            items = Array.prototype.slice.call(dataTransfer.items || []);
            if (items.length) {
                return items.some(function (item) {
                    return item && item.kind === 'file';
                });
            }

            types = Array.prototype.slice.call(dataTransfer.types || []);
            return types.indexOf('Files') !== -1;
        }

        function hasAllowedReplacementImageExtension(name) {
            return /\.(?:bmp|gif|ico|jpe?g|png|webp)$/i.test(String(name || ''));
        }

        function hasAllowedReplacementImageMime(type) {
            var normalizedType = String(type || '').toLowerCase();

            return normalizedType === '' || /^image\/(?:bmp|x-bmp|gif|jpeg|png|webp|x-icon|vnd\.microsoft\.icon)$/i.test(normalizedType);
        }

        function isImageFile(file) {
            var name;

            if (!file) {
                return false;
            }

            name = String(file.name || '');
            return hasAllowedReplacementImageExtension(name) && hasAllowedReplacementImageMime(file.type || '');
        }

        function formatFileSize(size) {
            var bytes = Number(size || 0);

            if (!Number.isFinite(bytes) || bytes <= 0) {
                return '';
            }

            if (bytes >= 1048576) {
                return (Math.round((bytes / 1048576) * 10) / 10) + ' MB';
            }

            if (bytes >= 1024) {
                return Math.round(bytes / 1024) + ' KB';
            }

            return Math.round(bytes) + ' B';
        }

        function getDefaultMessage(target) {
            if (!target) {
                return '';
            }

            return String(target.dataset.defaultMessage || '').trim();
        }

        function revokeFeaturedPreview(form) {
            var objectUrl = form ? String(form.dataset.featuredPreviewUrl || '') : '';

            if (!objectUrl || typeof window.URL === 'undefined' || typeof window.URL.revokeObjectURL !== 'function') {
                return;
            }

            window.URL.revokeObjectURL(objectUrl);
            delete form.dataset.featuredPreviewUrl;
        }

        function updateLocalPreview(form, file) {
            var previewWrap = form.querySelector('[data-featured-local-preview]');
            var previewImage = form.querySelector('[data-featured-local-preview-image]');
            var previewName = form.querySelector('[data-featured-local-preview-name]');
            var previewMeta = form.querySelector('[data-featured-local-preview-meta]');
            var metaParts;
            var objectUrl;
            var reader;

            if (!previewWrap || !previewImage) {
                return;
            }

            revokeFeaturedPreview(form);

            if (!file || !isImageFile(file)) {
                previewWrap.hidden = true;
                previewImage.removeAttribute('src');
                previewImage.alt = '';
                if (previewName) {
                    previewName.textContent = '';
                }
                if (previewMeta) {
                    previewMeta.textContent = '';
                }
                return;
            }

            previewImage.alt = 'Vorschau für ' + String(file.name || 'Bilddatei');

            if (previewName) {
                previewName.textContent = String(file.name || 'Bilddatei');
            }

            if (previewMeta) {
                metaParts = [];

                if (file.type) {
                    metaParts.push(String(file.type));
                }

                if (file.size) {
                    metaParts.push(formatFileSize(file.size));
                }

                previewMeta.textContent = metaParts.join(' • ');
            }

            previewWrap.hidden = false;

            if (typeof window.URL !== 'undefined' && typeof window.URL.createObjectURL === 'function') {
                objectUrl = window.URL.createObjectURL(file);
                form.dataset.featuredPreviewUrl = objectUrl;
                previewImage.src = objectUrl;
                return;
            }

            if (typeof window.FileReader === 'function') {
                reader = new window.FileReader();
                reader.addEventListener('load', function () {
                    previewImage.src = String(reader.result || '');
                });
                reader.readAsDataURL(file);
            }
        }

        function setDropzoneState(dropzone, isActive) {
            if (!dropzone) {
                return;
            }

            dropzone.classList.toggle('border-primary', isActive);
            dropzone.classList.toggle('bg-primary-lt', isActive);
        }

        function updateSelectedFileHint(form, file) {
            var selectedFileTargets = form.querySelectorAll('[data-featured-selected-file]');
            var statusTargets = form.querySelectorAll('[data-featured-replace-status]');
            var message = file
                ? 'Bereit: ' + String(file.name || 'Bilddatei')
                : '';

            selectedFileTargets.forEach(function (target) {
                var isReady = !!file;
                var hintText = message || getDefaultMessage(target) || 'Noch keine Datei ausgewählt.';

                clearElement(target);
                target.classList.toggle('is-ready', isReady);
                target.classList.toggle('text-secondary', !isReady);

                if (isReady) {
                    var icon = document.createElement('i');
                    icon.className = 'ti ti-check me-1';
                    icon.setAttribute('aria-hidden', 'true');
                    target.appendChild(icon);
                }

                target.appendChild(document.createTextNode(hintText));
            });

            statusTargets.forEach(function (target) {
                var defaultMessage;

                if (target.classList.contains('alert')) {
                    return;
                }

                defaultMessage = getDefaultMessage(target);
                target.textContent = file
                    ? 'Ausgewählt: ' + String(file.name || 'Bilddatei') + '. Mit „Bild ersetzen“ übernehmen Sie alle aktuell vorbereiteten Bild-Ersetzungen gemeinsam.'
                    : defaultMessage;
            });

            updateLocalPreview(form, file);
        }

        function getFeaturedReplacementFile(form) {
            var fileInput = form ? form.querySelector('input[type="file"][name="replacement_file"]') : null;

            return fileInput && fileInput.files && fileInput.files[0] ? fileInput.files[0] : null;
        }

        function getFeaturedReplacementPath(form) {
            var pathInput = form ? form.querySelector('input[name="item_path"]') : null;

            return pathInput ? String(pathInput.value || '').trim() : '';
        }

        function collectPreparedReplacementForms() {
            return Array.prototype.slice.call(forms).filter(function (form) {
                return getFeaturedReplacementPath(form) !== '' && getFeaturedReplacementFile(form) !== null;
            });
        }

        function clearFeaturedBatchPayload(form) {
            if (!form) {
                return;
            }

            form.querySelectorAll('[data-featured-batch-temp="1"]').forEach(function (element) {
                element.remove();
            });

            forms.forEach(function (replaceForm) {
                var fileInput = replaceForm.querySelector('input[type="file"][name="replacement_file"]');
                if (fileInput) {
                    fileInput.disabled = false;
                    fileInput.removeAttribute('aria-disabled');
                }
            });

            delete form.dataset.featuredBatchPrepared;
        }

        function createFeaturedBatchFileInput(file) {
            var dataTransfer;
            var input;

            if (typeof window.DataTransfer !== 'function') {
                return null;
            }

            try {
                dataTransfer = new window.DataTransfer();
                dataTransfer.items.add(file);
                input = document.createElement('input');
                input.type = 'file';
                input.name = 'replacement_files[]';
                input.files = dataTransfer.files;
                input.setAttribute('data-featured-batch-temp', '1');
                input.tabIndex = -1;
                input.setAttribute('aria-hidden', 'true');
                input.style.position = 'absolute';
                input.style.left = '-9999px';
                input.style.width = '1px';
                input.style.height = '1px';
                input.style.opacity = '0';
            } catch (error) {
                return null;
            }

            return input;
        }

        function appendFeaturedBatchPathInput(form, path) {
            var input = document.createElement('input');

            input.type = 'hidden';
            input.name = 'item_paths[]';
            input.value = path;
            input.setAttribute('data-featured-batch-temp', '1');
            form.appendChild(input);
        }

        function prepareFeaturedBatchSubmit(targetForm, selectedForms) {
            var actionInput = targetForm ? targetForm.querySelector('input[name="action"]') : null;

            if (!targetForm || !actionInput || !selectedForms.length) {
                return false;
            }

            clearFeaturedBatchPayload(targetForm);
            actionInput.value = 'replace_items';

            for (var index = 0; index < selectedForms.length; index++) {
                var selectedForm = selectedForms[index];
                var selectedPath = getFeaturedReplacementPath(selectedForm);
                var selectedFile = getFeaturedReplacementFile(selectedForm);
                var batchFileInput = selectedFile ? createFeaturedBatchFileInput(selectedFile) : null;

                if (!selectedPath || !selectedFile || !batchFileInput) {
                    clearFeaturedBatchPayload(targetForm);
                    actionInput.value = 'replace_item';
                    return false;
                }

                appendFeaturedBatchPathInput(targetForm, selectedPath);
                targetForm.appendChild(batchFileInput);
            }

            forms.forEach(function (replaceForm) {
                var fileInput = replaceForm.querySelector('input[type="file"][name="replacement_file"]');
                if (fileInput) {
                    fileInput.disabled = true;
                    fileInput.setAttribute('aria-disabled', 'true');
                }
            });

            targetForm.dataset.featuredBatchPrepared = '1';
            return true;
        }

        window.addEventListener('pagehide', function () {
            forms.forEach(function (form) {
                revokeFeaturedPreview(form);
            });
        });

        window.addEventListener('dragover', function (event) {
            if (!eventContainsFiles(event)) {
                return;
            }

            if (!event.target || !event.target.closest || event.target.closest('[data-featured-dropzone="1"]')) {
                return;
            }

            event.preventDefault();
            if (event.dataTransfer) {
                event.dataTransfer.dropEffect = 'none';
            }
        });

        window.addEventListener('drop', function (event) {
            if (!eventContainsFiles(event)) {
                return;
            }

            if (event.target && event.target.closest && event.target.closest('[data-featured-dropzone="1"]')) {
                return;
            }

            event.preventDefault();
        });

        toggleButtons.forEach(function (button) {
            button.addEventListener('click', function () {
                var targetId = String(button.dataset.featuredToggleTarget || '');
                var targetPanel = targetId ? document.getElementById(targetId) : null;
                var shouldOpen = !!targetPanel && targetPanel.hidden;

                closeAllPanels(shouldOpen ? targetId : '');
                activePanelId = shouldOpen ? targetId : '';

                if (shouldOpen) {
                    openPanel(targetPanel);
                }
            });
        });

        forms.forEach(function (form) {
            var fileInput = form.querySelector('input[type="file"][name="replacement_file"]');
            var dropzone = form.querySelector('[data-featured-dropzone="1"]');
            var submitButton = form.querySelector('[data-featured-submit="1"]');
            var panel = form.closest('[data-featured-panel="1"]');
            var dragDepth = 0;

            if (!fileInput || !dropzone) {
                return;
            }

            updateSelectedFileHint(form, fileInput.files && fileInput.files[0] ? fileInput.files[0] : null);

            fileInput.addEventListener('change', function () {
                var selectedFile = fileInput.files && fileInput.files[0] ? fileInput.files[0] : null;

                if (selectedFile && !isImageFile(selectedFile)) {
                    fileInput.value = '';
                    updateSelectedFileHint(form, null);
                    showMessage('danger', 'Bitte eine gültige Bilddatei im Format JPG/JPEG, PNG, GIF, WebP, BMP oder ICO auswählen.');
                    return;
                }

                updateSelectedFileHint(form, selectedFile);
            });

            dropzone.addEventListener('click', function () {
                fileInput.click();
            });

            dropzone.addEventListener('keydown', function (event) {
                if (event.key !== 'Enter' && event.key !== ' ') {
                    return;
                }

                event.preventDefault();
                fileInput.click();
            });

            ['dragenter', 'dragover'].forEach(function (eventName) {
                dropzone.addEventListener(eventName, function (event) {
                    var fileItems;

                    if (!eventContainsFiles(event)) {
                        return;
                    }

                    event.preventDefault();
                    event.stopPropagation();

                    if (eventName === 'dragenter') {
                        dragDepth += 1;
                    }

                    fileItems = Array.prototype.slice.call((event.dataTransfer && event.dataTransfer.items) || []).filter(function (item) {
                        return item && item.kind === 'file';
                    });

                    if (event.dataTransfer) {
                        event.dataTransfer.dropEffect = fileItems.length === 1 && fileItems.every(function (item) {
                            return hasAllowedReplacementImageMime(item.type || '');
                        }) ? 'copy' : 'none';
                    }

                    setDropzoneState(dropzone, true);
                });
            });

            ['dragleave', 'dragend'].forEach(function (eventName) {
                dropzone.addEventListener(eventName, function (event) {
                    event.preventDefault();
                    event.stopPropagation();

                    if (eventName === 'dragleave') {
                        dragDepth = Math.max(0, dragDepth - 1);
                    } else {
                        dragDepth = 0;
                    }

                    if (dragDepth === 0) {
                        setDropzoneState(dropzone, false);
                    }
                });
            });

            dropzone.addEventListener('drop', function (event) {
                var files;
                var imageFiles;
                var dataTransfer;

                if (!eventContainsFiles(event)) {
                    return;
                }

                event.preventDefault();
                event.stopPropagation();
                dragDepth = 0;
                setDropzoneState(dropzone, false);

                files = event.dataTransfer && event.dataTransfer.files
                    ? Array.prototype.slice.call(event.dataTransfer.files)
                    : [];

                imageFiles = files.filter(function (file) {
                    return isImageFile(file);
                });

                if (imageFiles.length !== 1) {
                    showMessage('danger', imageFiles.length === 0
                        ? 'Bitte genau eine Bilddatei im Format JPG/JPEG, PNG, GIF, WebP, BMP oder ICO auf die Ersetzen-Zone ziehen.'
                        : 'Bitte genau eine einzelne Bilddatei auf die Ersetzen-Zone ziehen.');
                    updateSelectedFileHint(form, null);
                    return;
                }

                if (typeof window.DataTransfer === 'function') {
                    try {
                        dataTransfer = new window.DataTransfer();
                        dataTransfer.items.add(imageFiles[0]);
                        fileInput.files = dataTransfer.files;
                    } catch (error) {
                        dataTransfer = null;
                    }
                }

                if (!fileInput.files || !fileInput.files.length) {
                    showMessage('danger', 'Drag-&-Drop wird in diesem Browser für dieses Formular nicht vollständig unterstützt. Bitte Bild per Klick auswählen.');
                    updateSelectedFileHint(form, null);
                    return;
                }

                updateSelectedFileHint(form, fileInput.files[0]);

                if (submitButton) {
                    submitButton.focus();
                }
            });

            form.addEventListener('submit', function (event) {
                var selectedForms;

                setDropzoneState(dropzone, false);
                dragDepth = 0;

                if (panel) {
                    closePanel(panel);
                }
                closeAllPanels('');
                activePanelId = '';

                if (form.dataset.featuredBatchPrepared === '1') {
                    return;
                }

                selectedForms = collectPreparedReplacementForms();
                if (selectedForms.length <= 1) {
                    return;
                }

                if (!prepareFeaturedBatchSubmit(form, selectedForms)) {
                    event.preventDefault();
                    showMessage('danger', 'Die Mehrfach-Ersetzung konnte in diesem Browser nicht vorbereitet werden. Bitte die Bilder einzeln ersetzen oder einen aktuellen Browser verwenden.');
                    return;
                }

                selectedForms.forEach(function (replaceForm) {
                    var statusTargets = replaceForm.querySelectorAll('[data-featured-replace-status]');
                    statusTargets.forEach(function (target) {
                        if (!target.classList.contains('alert')) {
                            target.textContent = selectedForms.length + ' vorbereitete Bild-Ersetzungen werden gemeinsam verarbeitet …';
                        }
                    });
                });
            });
        });

        if (activePanelId !== '') {
            closeAllPanels(activePanelId);
        } else {
            closeAllPanels('');
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        initNativeUploader();
        initMediaPickers();
        initCopyInputButtons();
        initMediaLibraryActions();
        initMediaCategoryActions();
        initFeaturedMediaReplace();
    });
})();