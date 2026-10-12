(function () {
    'use strict';

    function parseConfig(id) {
        var element = document.getElementById(id);
        if (!element) {
            return null;
        }

        try {
            return JSON.parse(element.textContent || '{}');
        } catch (error) {
            console.error('admin-menu-editor: Konfiguration konnte nicht gelesen werden.', error);
            return null;
        }
    }

    function clearElement(element) {
        if (!element) {
            return;
        }

        while (element.firstChild) {
            element.removeChild(element.firstChild);
        }
    }

    function normalizeTitle(value) {
        return String(value || '').trim().toLowerCase();
    }

    function getModalInstance(modalElement) {
        if (!modalElement || typeof bootstrap === 'undefined' || !bootstrap.Modal) {
            return null;
        }

        return bootstrap.Modal.getOrCreateInstance(modalElement);
    }

    function triggerNativeSubmit(form) {
        var temporarySubmitter;

        if (!form) {
            return;
        }

        if (typeof form.requestSubmit === 'function') {
            form.requestSubmit();
            return;
        }

        temporarySubmitter = document.createElement('button');
        temporarySubmitter.type = 'submit';
        temporarySubmitter.hidden = true;
        temporarySubmitter.tabIndex = -1;
        temporarySubmitter.setAttribute('aria-hidden', 'true');
        form.appendChild(temporarySubmitter);
        temporarySubmitter.click();
        form.removeChild(temporarySubmitter);
    }

    function requestFormSubmit(form) {
        if (!form) {
            return;
        }

        triggerNativeSubmit(form);
    }

    document.addEventListener('DOMContentLoaded', function () {
        var config = parseConfig('menu-editor-config') || { items: [] };
        var menuItems = Array.isArray(config.items) ? config.items.slice() : [];
        var homepageTitles = ['startseite', 'home', 'homepage'];
        var listElement = document.getElementById('menuItemsList');
        var jsonInput = document.getElementById('menuItemsJson');
        var newItemTitle = document.getElementById('newItemTitle');
        var newItemUrl = document.getElementById('newItemUrl');
        var newItemParent = document.getElementById('newItemParent');
        var newItemTarget = document.getElementById('newItemTarget');
        var addItemButton = document.getElementById('btnAddItem');
        var pagePickerSelect = document.getElementById('pagePickerSelect');
        var addPageItemButton = document.getElementById('btnAddPageItem');
        var deleteMenuButton = document.getElementById('btnDeleteMenu');
        var deleteMenuForm = document.getElementById('deleteMenuForm');
        var saveItemsForm = document.getElementById('saveItemsForm');
        var menuModal = document.getElementById('menuModal');
        var menuModalInstance = getModalInstance(menuModal);
        var menuModalTitle = document.getElementById('menuModalTitle');
        var editMenuId = document.getElementById('editMenuId');
        var editMenuName = document.getElementById('editMenuName');
        var editMenuLocation = document.getElementById('editMenuLocation');
        var menuModalForm = menuModal ? menuModal.querySelector('form') : null;
        var tempIdCounter = 0;

        function nextTempId() {
            tempIdCounter += 1;
            return 'tmp-' + tempIdCounter;
        }

        function normalizeParentId(value) {
            return value && value !== '0' ? String(value) : '0';
        }

        function normalizeUrl(value) {
            return String(value || '').trim();
        }

        function isValidMenuUrl(value) {
            var normalized = normalizeUrl(value);

            if (normalized === '') {
                return false;
            }

            if (/^(?:\/(?!\/)|#|\?|mailto:|tel:|https?:\/\/)/i.test(normalized)) {
                return true;
            }

            if (/^javascript:\s*(?:void\(0\)|;?)\s*;?$/i.test(normalized)) {
                return true;
            }

            if (/^\/\//.test(normalized) || /^[a-z][a-z0-9+\-.]*:/i.test(normalized)) {
                return false;
            }

            return !/\s/.test(normalized);
        }

        function isHomepageTitle(value) {
            return homepageTitles.indexOf(normalizeTitle(value)) !== -1;
        }

        function itemHasChildren(item) {
            var itemId = item && item.id ? String(item.id) : '';

            if (itemId === '') {
                return false;
            }

            return menuItems.some(function (candidate) {
                return normalizeParentId(candidate.parent_id) === itemId;
            });
        }

        function canUsePlaceholderUrl(item, options) {
            if (options && options.allowEmptyUrl) {
                return true;
            }

            return isHomepageTitle(item && item.title)
                || itemHasChildren(item);
        }

        function setFieldError(field, message) {
            if (!field) {
                return;
            }

            field.setCustomValidity(message || '');
            field.reportValidity();
        }

        function clearFieldError(field) {
            if (!field) {
                return;
            }

            field.setCustomValidity('');
        }

        function validateItemData(item, options) {
            var normalizedUrl;

            if (!item || String(item.title || '').trim() === '') {
                return 'Bitte einen Titel angeben.';
            }

            normalizedUrl = normalizeUrl(item.url);

            if (normalizedUrl === '' && canUsePlaceholderUrl(item, options || null)) {
                return '';
            }

            if (!isValidMenuUrl(normalizedUrl)) {
                return 'Bitte eine gültige URL, einen internen Pfad oder mailto:/tel: verwenden.';
            }

            return '';
        }

        function getItemById(id) {
            return menuItems.find(function (item) {
                return String(item.id) === String(id);
            }) || null;
        }

        function getItemDepth(item, visited) {
            visited = visited || [];
            var parentId = normalizeParentId(item.parent_id);
            if (parentId === '0' || visited.indexOf(parentId) !== -1) {
                return 0;
            }

            var parent = getItemById(parentId);
            if (!parent) {
                return 0;
            }

            return 1 + getItemDepth(parent, visited.concat([parentId]));
        }

        function collectDescendantIds(itemId, bucket) {
            bucket = bucket || [];
            menuItems.forEach(function (candidate) {
                if (normalizeParentId(candidate.parent_id) === String(itemId) && bucket.indexOf(String(candidate.id)) === -1) {
                    bucket.push(String(candidate.id));
                    collectDescendantIds(candidate.id, bucket);
                }
            });

            return bucket;
        }

        function appendParentOptions(select, currentId, selectedParentId) {
            var descendants = currentId ? collectDescendantIds(currentId, []) : [];
            var rootOption;

            if (!select) {
                return;
            }

            clearElement(select);

            rootOption = document.createElement('option');
            rootOption.value = '0';
            rootOption.textContent = 'Hauptebene';
            rootOption.selected = normalizeParentId(selectedParentId) === '0';
            select.appendChild(rootOption);

            menuItems.forEach(function (candidate) {
                var candidateId = String(candidate.id);
                var option;
                if (currentId && (candidateId === String(currentId) || descendants.indexOf(candidateId) !== -1)) {
                    return;
                }

                var depth = getItemDepth(candidate);
                var prefix = depth > 0 ? Array(depth + 1).join('↳ ') : '';

                option = document.createElement('option');
                option.value = candidateId;
                option.textContent = prefix + String(candidate.title || '');
                option.selected = normalizeParentId(selectedParentId) === candidateId;
                select.appendChild(option);
            });
        }

        function syncJsonInput() {
            if (jsonInput) {
                jsonInput.value = JSON.stringify(menuItems);
            }
        }

        function setFormSubmitting(form, isSubmitting) {
            if (!form) {
                return;
            }

            form.dataset.submitting = isSubmitting ? '1' : '0';
            form.querySelectorAll('button[type="submit"], input[type="submit"]').forEach(function (element) {
                element.disabled = isSubmitting;
            });
        }

        function installSingleSubmitGuard(form) {
            if (!form) {
                return;
            }

            form.dataset.submitting = '0';
            form.addEventListener('submit', function (event) {
                if (event.defaultPrevented) {
                    setFormSubmitting(form, false);
                    return;
                }

                if (form.dataset.submitting === '1') {
                    event.preventDefault();
                    return;
                }

                setFormSubmitting(form, true);
            });
        }

        function validateMenuItems() {
            var index;

            for (index = 0; index < menuItems.length; index += 1) {
                var validationError = validateItemData(menuItems[index]);
                if (validationError !== '') {
                    return {
                        valid: false,
                        index: index,
                        error: validationError,
                    };
                }
            }

            return {
                valid: true,
                index: -1,
                error: '',
            };
        }

        function refreshParentSelects() {
            if (newItemParent) {
                appendParentOptions(newItemParent, null, '0');
            }

            if (!listElement) {
                return;
            }

            listElement.querySelectorAll('.item-parent').forEach(function (select) {
                var index = parseInt(select.dataset.index || '', 10);
                var item = menuItems[index];
                if (!item) {
                    return;
                }

                appendParentOptions(select, item.id, item.parent_id);
            });
        }

        function sortMenuItemsByTree() {
            var grouped = {};
            var ordered = [];

            menuItems.forEach(function (item) {
                var parentId = normalizeParentId(item.parent_id);
                if (!grouped[parentId]) {
                    grouped[parentId] = [];
                }
                grouped[parentId].push(item);
            });

            function appendBranch(parentId, trail) {
                (grouped[parentId] || []).forEach(function (item) {
                    var itemId = String(item.id);
                    if (trail.indexOf(itemId) !== -1) {
                        return;
                    }

                    ordered.push(item);
                    appendBranch(itemId, trail.concat([itemId]));
                });
            }

            appendBranch('0', []);

            menuItems.forEach(function (item) {
                if (ordered.indexOf(item) === -1) {
                    ordered.push(item);
                }
            });

            menuItems = ordered;
        }

        function getSubtreeEndIndex(startIndex) {
            var startItem = menuItems[startIndex];
            var startDepth;
            var index;

            if (!startItem) {
                return startIndex;
            }

            startDepth = getItemDepth(startItem);

            for (index = startIndex + 1; index < menuItems.length; index += 1) {
                if (getItemDepth(menuItems[index]) <= startDepth) {
                    return index - 1;
                }
            }

            return menuItems.length - 1;
        }

        function moveItem(index, direction) {
            var item = menuItems[index];
            var parentId;
            var itemDepth;
            var siblingStarts = [];
            var siblingPosition;
            var startIndex;
            var endIndex;
            var blockLength;
            var block;
            var prevStart;
            var nextStart;
            var nextEnd;
            var insertAt;
            var scanIndex;

            if (!item) {
                return;
            }

            parentId = normalizeParentId(item.parent_id);
            itemDepth = getItemDepth(item);

            for (scanIndex = 0; scanIndex < menuItems.length; scanIndex += 1) {
                if (normalizeParentId(menuItems[scanIndex].parent_id) === parentId && getItemDepth(menuItems[scanIndex]) === itemDepth) {
                    siblingStarts.push(scanIndex);
                }
            }

            siblingPosition = siblingStarts.indexOf(index);
            if (siblingPosition === -1) {
                return;
            }

            startIndex = index;
            endIndex = getSubtreeEndIndex(startIndex);
            blockLength = endIndex - startIndex + 1;
            block = menuItems.splice(startIndex, blockLength);

            if (direction === 'up') {
                prevStart = siblingStarts[siblingPosition - 1];
                if (typeof prevStart !== 'number') {
                    menuItems.splice.apply(menuItems, [startIndex, 0].concat(block));
                    return;
                }

                menuItems.splice.apply(menuItems, [prevStart, 0].concat(block));
                return;
            }

            nextStart = siblingStarts[siblingPosition + 1];
            if (typeof nextStart !== 'number') {
                menuItems.splice.apply(menuItems, [startIndex, 0].concat(block));
                return;
            }

            nextEnd = getSubtreeEndIndex(nextStart - blockLength);
            insertAt = nextEnd + 1;
            menuItems.splice.apply(menuItems, [insertAt, 0].concat(block));
        }

        function removeItemAndChildren(itemId) {
            var descendants = collectDescendantIds(itemId, []);
            var idsToRemove = [String(itemId)].concat(descendants);
            menuItems = menuItems.filter(function (item) {
                return idsToRemove.indexOf(String(item.id)) === -1;
            });
        }

        function createFieldLabel(text, forId) {
            var label = document.createElement('label');
            label.className = 'form-label small text-muted mb-1';
            label.textContent = text;
            if (forId) {
                label.htmlFor = forId;
            }
            return label;
        }

        function createTextInput(className, index, value, placeholder, id) {
            var input = document.createElement('input');
            input.type = 'text';
            input.className = className;
            input.dataset.index = String(index);
            input.value = String(value || '');
            input.placeholder = placeholder;
            if (id) {
                input.id = id;
            }
            return input;
        }

        function createTargetSelect(index, target, id) {
            var select = document.createElement('select');
            var selfOption = document.createElement('option');
            var blankOption = document.createElement('option');

            select.className = 'form-select form-select-sm item-target';
            select.dataset.index = String(index);
            if (id) {
                select.id = id;
            }

            selfOption.value = '_self';
            selfOption.textContent = 'Gleiches Fenster';
            selfOption.selected = target !== '_blank';

            blankOption.value = '_blank';
            blankOption.textContent = 'Neuer Tab';
            blankOption.selected = target === '_blank';

            select.appendChild(selfOption);
            select.appendChild(blankOption);

            return select;
        }

        function createColumn(className) {
            var column = document.createElement('div');
            column.className = className;
            return column;
        }

        function createActionButton(className, index, label, ariaLabel) {
            var button = document.createElement('button');
            button.type = 'button';
            button.className = className;
            button.dataset.index = String(index);
            button.textContent = label;

            if (ariaLabel) {
                button.setAttribute('aria-label', ariaLabel);
            }

            return button;
        }

        function createMenuItemNode(item, idx) {
            var depth = getItemDepth(item);
            var prefix = depth > 0 ? Array(depth + 1).join('↳ ') : '';
            var itemNode = document.createElement('div');
            var dragHandle = document.createElement('span');
            var content = document.createElement('div');
            var hint = null;
            var row = document.createElement('div');
            var textColumn = createColumn('col-md-4');
            var urlColumn = createColumn('col-md-5');
            var targetColumn = createColumn('col-md-3');
            var parentWrap = document.createElement('div');
            var parentSelect = document.createElement('select');
            var actions = document.createElement('div');
            var fieldIdPrefix = 'menu-item-' + String(item.id || idx);

            itemNode.className = 'list-group-item d-flex align-items-start gap-3';
            itemNode.dataset.index = String(idx);

            dragHandle.className = 'cursor-grab text-muted';
            dragHandle.textContent = '☰';

            content.className = 'flex-fill';

            if (depth > 0) {
                hint = document.createElement('div');
                hint.className = 'small text-muted mb-2';
                hint.textContent = prefix + 'Unterpunkt';
                content.appendChild(hint);
            }

            row.className = 'row g-2';

            textColumn.appendChild(createFieldLabel('Text', fieldIdPrefix + '-title'));
            textColumn.appendChild(createTextInput('form-control form-control-sm item-title', idx, item.title, 'Menütext', fieldIdPrefix + '-title'));

            urlColumn.appendChild(createFieldLabel('URL', fieldIdPrefix + '-url'));
            urlColumn.appendChild(createTextInput('form-control form-control-sm item-url', idx, item.url, '/seite oder https://...', fieldIdPrefix + '-url'));

            targetColumn.appendChild(createFieldLabel('Ziel', fieldIdPrefix + '-target'));
            targetColumn.appendChild(createTargetSelect(idx, item.target, fieldIdPrefix + '-target'));

            row.appendChild(textColumn);
            row.appendChild(urlColumn);
            row.appendChild(targetColumn);

            parentWrap.className = 'mt-2';
            parentWrap.appendChild(createFieldLabel('Unterpunkt von', fieldIdPrefix + '-parent'));

            parentSelect.className = 'form-select form-select-sm item-parent';
            parentSelect.dataset.index = String(idx);
            parentSelect.id = fieldIdPrefix + '-parent';
            appendParentOptions(parentSelect, item.id, item.parent_id);
            parentWrap.appendChild(parentSelect);

            content.appendChild(row);
            content.appendChild(parentWrap);

            actions.className = 'd-flex flex-column gap-2';
            actions.appendChild(createActionButton('btn btn-sm btn-outline-secondary move-item-up', idx, '↑', 'Item nach oben verschieben'));
            actions.appendChild(createActionButton('btn btn-sm btn-outline-secondary move-item-down', idx, '↓', 'Item nach unten verschieben'));
            actions.appendChild(createActionButton('btn btn-sm btn-outline-danger remove-item', idx, '×', 'Item entfernen'));

            itemNode.appendChild(dragHandle);
            itemNode.appendChild(content);
            itemNode.appendChild(actions);

            return itemNode;
        }

        function renderEmptyState() {
            var emptyState = document.createElement('div');
            emptyState.className = 'list-group-item text-center text-muted py-5';
            emptyState.id = 'emptyState';
            emptyState.textContent = 'Noch keine Items. Füge oben ein Item hinzu.';
            listElement.appendChild(emptyState);
        }

        function renderItems() {
            if (!listElement) {
                return;
            }

            sortMenuItemsByTree();
            clearElement(listElement);

            if (menuItems.length === 0) {
                renderEmptyState();
                syncJsonInput();
                refreshParentSelects();
                return;
            }

            menuItems.forEach(function (item, idx) {
                listElement.appendChild(createMenuItemNode(item, idx));
            });

            syncJsonInput();
            refreshParentSelects();
        }

        document.querySelectorAll('.js-menu-modal-trigger').forEach(function (button) {
            button.addEventListener('click', function () {
                if (editMenuId) {
                    editMenuId.value = button.getAttribute('data-menu-id') || '0';
                }
                if (editMenuName) {
                    editMenuName.value = button.getAttribute('data-menu-name') || '';
                }
                if (editMenuLocation) {
                    editMenuLocation.value = button.getAttribute('data-menu-location') || '';
                }
                if (menuModalTitle) {
                    menuModalTitle.textContent = button.getAttribute('data-menu-modal-title') || 'Menü erstellen / bearbeiten';
                }
                if (menuModalInstance) {
                    menuModalInstance.show();
                }
            });
        });

        if (addItemButton) {
            addItemButton.addEventListener('click', function () {
                var title = newItemTitle ? newItemTitle.value.trim() : '';
                var url = newItemUrl ? normalizeUrl(newItemUrl.value) : '';
                var target = newItemTarget ? newItemTarget.value : '_self';
                var parentId = newItemParent ? normalizeParentId(newItemParent.value) : '0';
                var validationError;

                clearFieldError(newItemTitle);
                clearFieldError(newItemUrl);

                validationError = validateItemData({ title: title, url: url }, { allowEmptyUrl: true });
                if (validationError !== '') {
                    if (title === '') {
                        setFieldError(newItemTitle, validationError);
                    } else {
                        setFieldError(newItemUrl, validationError);
                    }
                    return;
                }

                menuItems.push({ id: nextTempId(), title: title, url: url, target: target, icon: '', parent_id: parentId });
                if (newItemTitle) {
                    newItemTitle.value = '';
                }
                if (newItemUrl) {
                    newItemUrl.value = '';
                }
                if (newItemParent) {
                    newItemParent.value = '0';
                }
                renderItems();
            });
        }

        if (addPageItemButton && pagePickerSelect) {
            addPageItemButton.addEventListener('click', function () {
                var selectedOption = pagePickerSelect.options[pagePickerSelect.selectedIndex];

                clearFieldError(pagePickerSelect);
                if (!selectedOption || !selectedOption.value) {
                    setFieldError(pagePickerSelect, 'Bitte zuerst eine Seite auswählen.');
                    return;
                }

                menuItems.push({
                    id: nextTempId(),
                    title: selectedOption.getAttribute('data-title') || selectedOption.text || '',
                    url: selectedOption.value || '',
                    target: '_self',
                    icon: '',
                    parent_id: '0',
                });

                pagePickerSelect.value = '';
                renderItems();
            });
        }

        if (listElement) {
            listElement.addEventListener('click', function (event) {
                var removeButton = event.target.closest('.remove-item');
                if (removeButton) {
                    var removeIndex = parseInt(removeButton.dataset.index || '', 10);
                    if (!Number.isNaN(removeIndex) && menuItems[removeIndex]) {
                        removeItemAndChildren(menuItems[removeIndex].id);
                        renderItems();
                    }
                    return;
                }

                var moveUpButton = event.target.closest('.move-item-up');
                if (moveUpButton) {
                    var moveUpIndex = parseInt(moveUpButton.dataset.index || '', 10);
                    if (!Number.isNaN(moveUpIndex)) {
                        moveItem(moveUpIndex, 'up');
                        renderItems();
                    }
                    return;
                }

                var moveDownButton = event.target.closest('.move-item-down');
                if (moveDownButton) {
                    var moveDownIndex = parseInt(moveDownButton.dataset.index || '', 10);
                    if (!Number.isNaN(moveDownIndex)) {
                        moveItem(moveDownIndex, 'down');
                        renderItems();
                    }
                }
            });

            listElement.addEventListener('input', function (event) {
                var titleInput = event.target.closest('.item-title');
                if (titleInput) {
                    var titleIndex = parseInt(titleInput.dataset.index || '', 10);
                    if (!Number.isNaN(titleIndex) && menuItems[titleIndex]) {
                        menuItems[titleIndex].title = titleInput.value;
                        clearFieldError(titleInput);
                        syncJsonInput();
                    }
                    return;
                }

                var urlInput = event.target.closest('.item-url');
                if (urlInput) {
                    var urlIndex = parseInt(urlInput.dataset.index || '', 10);
                    if (!Number.isNaN(urlIndex) && menuItems[urlIndex]) {
                        menuItems[urlIndex].url = normalizeUrl(urlInput.value);
                        clearFieldError(urlInput);
                        syncJsonInput();
                    }
                }
            });

            listElement.addEventListener('change', function (event) {
                var targetSelect = event.target.closest('.item-target');
                if (targetSelect) {
                    var targetIndex = parseInt(targetSelect.dataset.index || '', 10);
                    if (!Number.isNaN(targetIndex) && menuItems[targetIndex]) {
                        menuItems[targetIndex].target = targetSelect.value === '_blank' ? '_blank' : '_self';
                        syncJsonInput();
                    }
                    return;
                }

                var parentSelect = event.target.closest('.item-parent');
                if (parentSelect) {
                    var parentIndex = parseInt(parentSelect.dataset.index || '', 10);
                    if (!Number.isNaN(parentIndex) && menuItems[parentIndex]) {
                        menuItems[parentIndex].parent_id = normalizeParentId(parentSelect.value);
                        renderItems();
                    }
                }
            });
        }

        if (deleteMenuButton && deleteMenuForm) {
            deleteMenuButton.addEventListener('click', function () {
                var submitDelete = function () {
                    requestFormSubmit(deleteMenuForm);
                };

                if (typeof cmsConfirm === 'function') {
                    cmsConfirm({
                        title: 'Menü löschen',
                        message: 'Soll dieses Menü und alle seine Items gelöscht werden?',
                        confirmText: 'Löschen',
                        confirmClass: 'btn-danger',
                        onConfirm: submitDelete,
                    });
                    return;
                }

                if (window.confirm('Soll dieses Menü und alle seine Items gelöscht werden?')) {
                    submitDelete();
                }
            });
        }

        if (saveItemsForm) {
            saveItemsForm.addEventListener('submit', function (event) {
                var validation = validateMenuItems();
                var itemNode;
                var titleField;
                var urlField;

                if (!validation.valid) {
                    event.preventDefault();
                    itemNode = listElement ? listElement.querySelector('[data-index="' + validation.index + '"]') : null;
                    titleField = itemNode ? itemNode.querySelector('.item-title') : null;
                    urlField = itemNode ? itemNode.querySelector('.item-url') : null;

                    if (menuItems[validation.index] && String(menuItems[validation.index].title || '').trim() === '') {
                        setFieldError(titleField, validation.error);
                        if (titleField) {
                            titleField.focus();
                        }
                        return;
                    }

                    setFieldError(urlField, validation.error);
                    if (urlField) {
                        urlField.focus();
                    }
                    return;
                }

                syncJsonInput();
            });
        }

        installSingleSubmitGuard(saveItemsForm);
        installSingleSubmitGuard(deleteMenuForm);
        installSingleSubmitGuard(menuModalForm);

        renderItems();
    });
})();