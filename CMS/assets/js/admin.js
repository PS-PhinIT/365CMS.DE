/**
 * Admin JavaScript
 * 
 * @package CMSv2\Assets
 */

// ── Modal Utilities (Tabler Bootstrap) ───────────────────────────────────────
function openModal(id) {
    var el = document.getElementById(id);
    if (el && typeof bootstrap !== 'undefined') {
        bootstrap.Modal.getOrCreateInstance(el).show();
    }
}
function closeModal(id) {
    var el = document.getElementById(id);
    if (el && typeof bootstrap !== 'undefined') {
        var inst = bootstrap.Modal.getInstance(el);
        if (inst) inst.hide();
    }
}

function cmsSubmitFormSafely(form, submitter) {
    if (!(form instanceof HTMLFormElement)) {
        return;
    }

    if (submitter && typeof form.requestSubmit === 'function') {
        try {
            form.requestSubmit(submitter);
            return;
        } catch (error) {
            // Fällt auf einen generischen nativen Submitter zurück.
        }
    }

    if (typeof form.requestSubmit === 'function') {
        form.requestSubmit();
        return;
    }

    var fallbackSubmitter = document.createElement('button');
    fallbackSubmitter.type = 'submit';
    fallbackSubmitter.hidden = true;

    if (submitter && submitter.name) {
        fallbackSubmitter.name = submitter.name;
        fallbackSubmitter.value = submitter.value;
    }

    form.appendChild(fallbackSubmitter);
    fallbackSubmitter.click();
    fallbackSubmitter.remove();
}

window.cmsSubmitFormSafely = cmsSubmitFormSafely;

function formatSeconds(value) {
    var numericValue = Number(value);
    if (!Number.isFinite(numericValue)) {
        numericValue = 0;
    }

    var seconds = Math.max(0, Math.floor(numericValue));
    var unit = 'Sekunden';
    var displayValue = seconds;

    if (seconds >= 86400) {
        unit = 'Tage';
        displayValue = seconds / 86400;
    } else if (seconds >= 3600) {
        unit = 'Stunden';
        displayValue = seconds / 3600;
    } else if (seconds >= 60) {
        unit = 'Minuten';
        displayValue = seconds / 60;
    }

    var formattedValue = Number.isInteger(displayValue)
        ? String(displayValue)
        : displayValue.toLocaleString('de-DE', { minimumFractionDigits: 1, maximumFractionDigits: 1 });

    return formattedValue + ' ' + unit;
}

window.formatSeconds = formatSeconds;

function initPostCategoryDeleteFlow() {
    var modalElement = document.getElementById('deleteCategoryModal');
    var deleteCategoryForms = document.querySelectorAll('.js-delete-category-form');
    var categoryIdInput = document.getElementById('deleteCategoryId');
    var categoryNameEl = document.getElementById('deleteCategoryName');
    var reassignWrap = document.getElementById('deleteCategoryReassignWrap');
    var replacementSelect = document.getElementById('replacementCategoryId');
    var hintEl = document.getElementById('deleteCategoryHint');
    var questionEl = document.getElementById('deleteCategoryQuestion');
    var submitButton = document.getElementById('deleteCategorySubmit');

    if (!deleteCategoryForms.length || !modalElement || !categoryIdInput || !categoryNameEl || !reassignWrap || !replacementSelect || !hintEl || !questionEl || !submitButton) {
        return;
    }

    var modal = typeof bootstrap !== 'undefined' && bootstrap.Modal
        ? bootstrap.Modal.getOrCreateInstance(modalElement)
        : null;

    function configureReplacementOptions(categoryId, preferredReplacementId) {
        var availableOptions = 0;
        var firstAvailableValue = '0';
        var preferredValue = String(preferredReplacementId || '0');
        var preferredAvailable = false;
        Array.prototype.forEach.call(replacementSelect.options, function(option) {
            var isPlaceholder = option.value === '0';
            option.disabled = !isPlaceholder && option.value === categoryId;
            option.selected = isPlaceholder;
            if (!option.disabled && !isPlaceholder) {
                availableOptions += 1;
                if (firstAvailableValue === '0') {
                    firstAvailableValue = option.value;
                }
                if (option.value === preferredValue) {
                    preferredAvailable = true;
                }
            }
        });
        replacementSelect.value = preferredAvailable ? preferredValue : firstAvailableValue;
        return {
            availableOptions: availableOptions,
            firstAvailableValue: firstAvailableValue,
        };
    }

    deleteCategoryForms.forEach(function(form) {
        form.addEventListener('submit', function(event) {
            var categoryId = String(form.getAttribute('data-category-id') || '0');
            var categoryName = String(form.getAttribute('data-category-name') || '');
            var assignedPosts = parseInt(form.getAttribute('data-assigned-posts') || '0', 10);
            var preferredReplacementId = parseInt(form.getAttribute('data-default-replacement-category-id') || '0', 10);

            if (!modal) {
                if (assignedPosts > 0 && preferredReplacementId <= 0) {
                    event.preventDefault();
                    window.alert('Für diese Kategorie muss zuerst eine Ersatzkategorie gewählt werden. Bitte Seite neu laden und erneut versuchen.');
                    return;
                }

                var fallbackMessage = 'Kategorie „' + categoryName + '“ wirklich löschen?';
                if (assignedPosts > 0 && preferredReplacementId > 0) {
                    fallbackMessage += ' ' + assignedPosts + ' Beitrag/Beiträge werden automatisch in die hinterlegte Ersatzkategorie verschoben.';
                } else {
                    fallbackMessage += ' Unterkategorien werden zu Hauptkategorien.';
                }

                if (!window.confirm(fallbackMessage)) {
                    event.preventDefault();
                }
                return;
            }

            event.preventDefault();

            categoryIdInput.value = categoryId;
            categoryNameEl.textContent = categoryName;
            var replacementState = configureReplacementOptions(categoryId, String(preferredReplacementId || '0'));
            submitButton.disabled = false;
            submitButton.textContent = 'Kategorie löschen';
            questionEl.classList.add('d-none');

            if (assignedPosts > 0) {
                reassignWrap.classList.remove('d-none');
                replacementSelect.required = true;
                questionEl.classList.remove('d-none');
                if (replacementState.availableOptions <= 0) {
                    replacementSelect.required = false;
                    replacementSelect.disabled = true;
                    submitButton.disabled = true;
                    hintEl.textContent = 'Es gibt aktuell keine andere Kategorie als Ersatz. Bitte lege zuerst eine weitere Kategorie an.';
                } else {
                    replacementSelect.disabled = false;
                    hintEl.textContent = assignedPosts + ' Beitrag/Beiträge sind dieser Kategorie zugeordnet und werden in die ausgewählte Ersatzkategorie verschoben.';
                    submitButton.textContent = 'Verschieben & löschen';
                }
            } else {
                reassignWrap.classList.add('d-none');
                replacementSelect.required = false;
                replacementSelect.disabled = false;
                hintEl.textContent = 'Es sind keine Beiträge direkt oder zusätzlich dieser Kategorie zugeordnet. Unterkategorien werden zu Hauptkategorien.';
            }

            modal.show();
        });
    });

    modalElement.addEventListener('hidden.bs.modal', function() {
        categoryIdInput.value = '0';
        categoryNameEl.textContent = '';
        reassignWrap.classList.add('d-none');
        replacementSelect.required = false;
        replacementSelect.disabled = false;
        submitButton.disabled = false;
        submitButton.textContent = 'Löschen bestätigen';
        questionEl.classList.add('d-none');
        configureReplacementOptions('0');
        hintEl.textContent = 'Unterkategorien werden dabei zu Hauptkategorien.';
    });
}

function initPostTagDeleteFlow() {
    var modalElement = document.getElementById('deleteTagModal');
    var deleteTagForms = document.querySelectorAll('.js-delete-tag-form');
    var tagIdInput = document.getElementById('deleteTagId');
    var tagNameEl = document.getElementById('deleteTagName');
    var reassignWrap = document.getElementById('deleteTagReassignWrap');
    var replacementSelect = document.getElementById('replacementTagId');
    var hintEl = document.getElementById('deleteTagHint');
    var questionEl = document.getElementById('deleteTagQuestion');
    var submitButton = document.getElementById('deleteTagSubmit');

    if (!deleteTagForms.length || !modalElement || !tagIdInput || !tagNameEl || !reassignWrap || !replacementSelect || !hintEl || !questionEl || !submitButton) {
        return;
    }

    var modal = typeof bootstrap !== 'undefined' && bootstrap.Modal
        ? bootstrap.Modal.getOrCreateInstance(modalElement)
        : null;

    function configureReplacementOptions(tagId) {
        var availableOptions = 0;
        var firstAvailableValue = '0';
        Array.prototype.forEach.call(replacementSelect.options, function(option) {
            var isPlaceholder = option.value === '0';
            option.disabled = !isPlaceholder && option.value === tagId;
            option.selected = isPlaceholder;
            if (!option.disabled && !isPlaceholder) {
                availableOptions += 1;
                if (firstAvailableValue === '0') {
                    firstAvailableValue = option.value;
                }
            }
        });
        replacementSelect.value = firstAvailableValue;
        return {
            availableOptions: availableOptions,
            firstAvailableValue: firstAvailableValue,
        };
    }

    deleteTagForms.forEach(function(form) {
        form.addEventListener('submit', function(event) {
            var tagId = String(form.getAttribute('data-tag-id') || '0');
            var tagName = String(form.getAttribute('data-tag-name') || '');
            var assignedPosts = parseInt(form.getAttribute('data-assigned-posts') || '0', 10);

            if (!modal) {
                if (assignedPosts > 0) {
                    event.preventDefault();
                    window.alert('Für diesen Tag muss zuerst ein Ersatztag gewählt werden. Bitte Seite neu laden und erneut versuchen.');
                    return;
                }

                if (!window.confirm('Tag wirklich löschen? Zugeordnete Beziehungen werden entfernt.')) {
                    event.preventDefault();
                }
                return;
            }

            event.preventDefault();

            tagIdInput.value = tagId;
            tagNameEl.textContent = tagName;
            var replacementState = configureReplacementOptions(tagId);
            submitButton.disabled = false;
            submitButton.textContent = 'Tag löschen';
            questionEl.classList.add('d-none');

            if (assignedPosts > 0) {
                reassignWrap.classList.remove('d-none');
                replacementSelect.required = true;
                questionEl.classList.remove('d-none');
                if (replacementState.availableOptions <= 0) {
                    replacementSelect.required = false;
                    replacementSelect.disabled = true;
                    submitButton.disabled = true;
                    hintEl.textContent = 'Es gibt aktuell keinen anderen Tag als Ersatz. Bitte lege zuerst einen weiteren Tag an.';
                } else {
                    replacementSelect.disabled = false;
                    hintEl.textContent = assignedPosts + ' Beitrag/Beiträge nutzen diesen Tag und werden auf den ausgewählten Ersatztag umgestellt.';
                    submitButton.textContent = 'Verschieben & löschen';
                }
            } else {
                reassignWrap.classList.add('d-none');
                replacementSelect.required = false;
                replacementSelect.disabled = false;
                hintEl.textContent = 'Es sind keine Beiträge mit diesem Tag verknüpft. Zugeordnete Beziehungen werden entfernt.';
            }

            modal.show();
        });
    });

    modalElement.addEventListener('hidden.bs.modal', function() {
        tagIdInput.value = '0';
        tagNameEl.textContent = '';
        reassignWrap.classList.add('d-none');
        replacementSelect.required = false;
        replacementSelect.disabled = false;
        submitButton.disabled = false;
        submitButton.textContent = 'Löschen bestätigen';
        questionEl.classList.add('d-none');
        configureReplacementOptions('0');
        hintEl.textContent = 'Zugeordnete Beziehungen werden dabei entfernt.';
    });
}

function initReplacementCategoryBulkDeleteFlow() {
    var bulkDeleteForms = document.querySelectorAll('.js-delete-replacement-categories-form');

    if (!bulkDeleteForms.length) {
        return;
    }

    bulkDeleteForms.forEach(function(form) {
        form.addEventListener('submit', function(event) {
            if (form.dataset.deleteConfirmed === '1') {
                form.dataset.deleteConfirmed = '0';
                return;
            }
            var deleteCount = parseInt(form.getAttribute('data-delete-count') || '0', 10);
            var previewRaw = String(form.getAttribute('data-delete-preview') || '');
            var previewItems = previewRaw === ''
                ? []
                : previewRaw.split('|').map(function(item) {
                    return item.trim();
                }).filter(function(item) {
                    return item !== '';
                });

            var message = 'Sollen wirklich ' + deleteCount + ' Kategorien mit hinterlegter Ersatzkategorie gelöscht werden?';

            if (previewItems.length > 0) {
                message += '\n\nBetroffene Kategorien:\n- ' + previewItems.join('\n- ');
            }

            if (deleteCount > previewItems.length) {
                message += '\n- … und ' + (deleteCount - previewItems.length) + ' weitere';
            }

            message += '\n\nDie zugeordneten Beiträge werden automatisch in die jeweilige Ersatzkategorie verschoben.';

            event.preventDefault();
            if (typeof cmsConfirm === 'function') {
                cmsConfirm({
                    title: 'Löschen bestätigen',
                    message: message,
                    confirmText: 'Löschen',
                    confirmClass: 'btn-danger',
                    onConfirm: function () {
                        form.dataset.deleteConfirmed = '1';
                        cmsSubmitFormSafely(form);
                    }
                });
                return;
            }
            if (window.confirm(message)) {
                form.dataset.deleteConfirmed = '1';
                cmsSubmitFormSafely(form);
            }
        });
    });
}

function initConfirmForms() {
    var confirmForms = document.querySelectorAll('form[data-confirm-message]');

    if (!confirmForms.length) {
        return;
    }

    function submitConfirmedForm(form, submitter) {
        if (!(form instanceof HTMLFormElement)) {
            return;
        }

        if (submitter && typeof form.requestSubmit === 'function') {
            try {
                form.requestSubmit(submitter);
                return;
            } catch (error) {
                // Fällt auf generischen Submit zurück.
            }
        }

        cmsSubmitFormSafely(form, submitter);
    }

    confirmForms.forEach(function(form) {
        form.querySelectorAll('button[type="submit"], input[type="submit"]').forEach(function(submitButton) {
            submitButton.addEventListener('click', function() {
                form.__cmsConfirmSubmitter = submitButton;
            });
        });

        form.addEventListener('submit', function(event) {
            var submitter = event.submitter || form.__cmsConfirmSubmitter || null;

            if (form.dataset.confirmAccepted === '1') {
                form.dataset.confirmAccepted = '0';
                form.__cmsConfirmSubmitter = submitter;
                return;
            }

            event.preventDefault();

            var message = form.dataset.confirmMessage || '';
            var title = form.dataset.confirmTitle || 'Bitte bestätigen';
            var confirmText = form.dataset.confirmText || 'Bestätigen';
            var cancelClass = form.dataset.confirmCancelClass || '';
            var confirmClass = form.dataset.confirmClass || 'btn-danger';
            var statusClass = form.dataset.confirmStatusClass || 'bg-danger';

            try {
                if (typeof cmsConfirm === 'function') {
                    cmsConfirm({
                        title: title,
                        message: message,
                        confirmText: confirmText,
                        cancelClass: cancelClass,
                        confirmClass: confirmClass,
                        statusClass: statusClass,
                        onConfirm: function() {
                            form.dataset.confirmAccepted = '1';
                            submitConfirmedForm(form, submitter);
                        }
                    });
                    return;
                }
            } catch (error) {
                console.warn('Bestätigungsdialog fehlgeschlagen, Fallback auf window.confirm wird verwendet.', error);
            }

            if (window.confirm(message)) {
                form.dataset.confirmAccepted = '1';
                submitConfirmedForm(form, submitter);
            }
        });
    });
}

function initResponsiveTableDropdowns() {
    var dropdowns = document.querySelectorAll('.table-responsive .dropdown');

    if (!dropdowns.length) {
        return;
    }

    function setTableOverflow(dropdownElement, open) {
        if (!(dropdownElement instanceof HTMLElement)) {
            return;
        }

        var container = dropdownElement.closest('.table-responsive');
        if (!(container instanceof HTMLElement)) {
            return;
        }

        container.style.overflow = open ? 'visible' : '';
    }

    dropdowns.forEach(function(dropdown) {
        dropdown.addEventListener('show.bs.dropdown', function() {
            setTableOverflow(dropdown, true);
        });

        dropdown.addEventListener('hidden.bs.dropdown', function() {
            setTableOverflow(dropdown, false);
        });
    });
}

function initGlobalEmptyTablePattern() {
    var emptyRowCandidates = document.querySelectorAll('table tbody tr td[colspan]');
    if (!emptyRowCandidates.length) {
        return;
    }

    function toEntityLabel(rawText) {
        var normalized = String(rawText || '').replace(/\s+/g, ' ').trim();
        var match = normalized.match(/^(?:Noch\s+)?Keine\s+(.+?)\s+(?:vorhanden|angelegt|registriert|konfiguriert|protokolliert|gefunden|verfügbar|vorliegend)\.?$/i);
        if (!match || !match[1]) {
            return 'Einträge';
        }
        return match[1].trim();
    }

    emptyRowCandidates.forEach(function(cell) {
        if (!(cell instanceof HTMLElement)) {
            return;
        }
        if (cell.querySelector('.admin-empty-table-state, .empty')) {
            return;
        }

        var text = (cell.textContent || '').replace(/\s+/g, ' ').trim();
        if (!/^(noch\s+)?keine\b/i.test(text)) {
            return;
        }

        var entity = toEntityLabel(text);
        cell.classList.remove('text-center', 'text-secondary', 'py-3', 'py-4');
        cell.classList.add('py-4');
        cell.innerHTML = '' +
            '<div class="admin-empty-table-state text-center">' +
                '<svg xmlns="http://www.w3.org/2000/svg" class="icon text-secondary mb-2" width="32" height="32" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round">' +
                    '<path stroke="none" d="M0 0h24v24H0z" fill="none"></path>' +
                    '<path d="M9 6l11 0"></path>' +
                    '<path d="M9 12l11 0"></path>' +
                    '<path d="M9 18l11 0"></path>' +
                    '<path d="M5 6l0 .01"></path>' +
                    '<path d="M5 12l0 .01"></path>' +
                    '<path d="M5 18l0 .01"></path>' +
                '</svg>' +
                '<div class="fw-bold" data-empty-title></div>' +
                '<div class="text-secondary small">Sobald neue Daten vorliegen, erscheinen sie hier automatisch.</div>' +
            '</div>';
        // Audit 4.2.0 (AP-04): `entity` stammt aus dem Text der Zelle und kann Zeichen aus Suchbegriffen oder Inhalten enthalten.
        // Als Text setzen, nie als HTML (der Zellentext ist nach `textContent` bereits entmaskiert).
        var emptyTitle = cell.querySelector('[data-empty-title]');
        if (emptyTitle) {
            emptyTitle.textContent = 'Keine ' + entity + ' vorhanden.';
        }
    });
}

function initAdminSearchFallbacks() {
    var searchInputs = document.querySelectorAll('.content-listing-filters__search input[type="search"], .content-listing-filters__search input[type="text"]');
    if (!searchInputs.length) {
        return;
    }

    searchInputs.forEach(function (input) {
        if (!(input instanceof HTMLInputElement)) {
            return;
        }

        if ((input.placeholder || '').trim() === '') {
            input.placeholder = 'Suche';
        }

        input.addEventListener('keydown', function (event) {
            if (event.key !== 'Enter') {
                return;
            }

            var formId = input.getAttribute('form');
            var form = formId ? document.getElementById(formId) : input.form;
            if (form instanceof HTMLFormElement) {
                event.preventDefault();
                cmsSubmitFormSafely(form);
            }
        });
    });
}

function cleanupStaleGlobalOverlays() {
    var activeBlockingOverlay = document.querySelector('.cms-modal.active, .cms-unsaved-modal.is-open, .cms-editor-image-picker.is-open');
    var activeBootstrapModal = document.querySelector('.modal.show');

    if (activeBlockingOverlay || activeBootstrapModal) {
        return;
    }

    document.querySelectorAll('.modal-backdrop').forEach(function(backdrop) {
        backdrop.remove();
    });

    document.body.classList.remove('modal-open');
    document.body.style.removeProperty('padding-right');
}

function cmsShowTopToast(message, durationMs) {
    var text = String(message || '').trim();
    if (text === '') {
        return;
    }

    var root = document.querySelector('.cms-top-toast-wrap');
    if (!(root instanceof HTMLElement)) {
        root = document.createElement('div');
        root.className = 'cms-top-toast-wrap';
        document.body.appendChild(root);
    }

    var toast = document.createElement('div');
    toast.className = 'cms-top-toast';
    toast.textContent = text;
    root.appendChild(toast);

    requestAnimationFrame(function () {
        toast.classList.add('is-visible');
    });

    window.setTimeout(function () {
        toast.classList.remove('is-visible');
        window.setTimeout(function () {
            toast.remove();
        }, 160);
    }, Math.max(1200, Number(durationMs) || 3000));
}

function initTaxonomySlideovers() {
    var roots = document.querySelectorAll('[data-taxonomy-panel-root]');
    if (!roots.length) {
        return;
    }

    function slugify(value) {
        return String(value || '')
            .normalize('NFKD')
            .replace(/[\u0300-\u036f]/g, '')
            .toLowerCase()
            .replace(/[^a-z0-9]+/g, '-')
            .replace(/^-+|-+$/g, '');
    }

    function parseStateFromNode(node) {
        if (!(node instanceof HTMLElement)) {
            return {};
        }

        var payload = node.getAttribute('data-taxonomy-panel-state') || '{}';
        try {
            var parsed = JSON.parse(payload);
            return parsed && typeof parsed === 'object' ? parsed : {};
        } catch (error) {
            return {};
        }
    }

    function applyFieldValue(form, field, value) {
        var target = form.querySelector('[data-taxonomy-field="' + field + '"]');
        if (!(target instanceof HTMLElement)) {
            return;
        }

        if (target instanceof HTMLInputElement) {
            if (target.type === 'checkbox') {
                target.checked = value === true || value === 1 || value === '1';
            } else {
                target.value = value == null ? '' : String(value);
            }
            return;
        }

        if (target instanceof HTMLSelectElement || target instanceof HTMLTextAreaElement) {
            target.value = value == null ? '' : String(value);
        }
    }

    function showInlineError(form, field, message) {
        var input = form.querySelector('[data-taxonomy-field="' + field + '"]');
        var hint = form.querySelector('[data-taxonomy-error-for="' + field + '"]');
        if (input instanceof HTMLElement) {
            input.classList.add('is-invalid');
        }
        if (hint instanceof HTMLElement) {
            hint.textContent = String(message || '');
            hint.classList.remove('d-none');
        }
    }

    function clearInlineErrors(form) {
        form.querySelectorAll('.is-invalid').forEach(function (el) {
            el.classList.remove('is-invalid');
        });
        form.querySelectorAll('[data-taxonomy-error-for]').forEach(function (el) {
            el.textContent = '';
            el.classList.add('d-none');
        });
        var formError = form.querySelector('[data-taxonomy-form-error]');
        if (formError instanceof HTMLElement) {
            formError.classList.add('d-none');
            formError.textContent = '';
        }
    }

    roots.forEach(function (root) {
        var panel = document.querySelector('[data-taxonomy-panel]');
        var backdrop = document.querySelector('[data-taxonomy-backdrop]');
        var openButton = root.querySelector('[data-taxonomy-open]') || document.querySelector('[data-taxonomy-open]');
        var isTagPanel = String(root.getAttribute('data-taxonomy-list-url') || '').indexOf('post-tags') > -1;
        var form = panel instanceof HTMLElement ? panel.querySelector('[data-taxonomy-form]') : null;
        if (!(panel instanceof HTMLElement) || !(backdrop instanceof HTMLElement) || !(openButton instanceof HTMLElement) || !(form instanceof HTMLFormElement)) {
            return;
        }

        var closeButtons = panel.querySelectorAll('[data-taxonomy-close], [data-taxonomy-cancel]');
        var submitButton = form.querySelector('[data-taxonomy-submit]');
        var titleNode = panel.querySelector('[data-taxonomy-title]');
        var nameInput = form.querySelector('[data-taxonomy-name]');
        var slugInput = form.querySelector('[data-taxonomy-slug]');
        var stateNode = panel.querySelector('[data-taxonomy-panel-state]');
        var initialState = parseStateFromNode(stateNode);
        var initialValues = initialState.values && typeof initialState.values === 'object' ? initialState.values : {};

        Object.keys(initialValues).forEach(function (field) {
            applyFieldValue(form, field, initialValues[field]);
        });

        function setMode(mode) {
            var isEdit = mode === 'edit';
            if (titleNode instanceof HTMLElement) {
                titleNode.textContent = isTagPanel
                    ? (isEdit ? 'Tag bearbeiten' : 'Neues Tag anlegen')
                    : (isEdit ? 'Kategorie bearbeiten' : 'Neue Kategorie anlegen');
            }
            if (submitButton instanceof HTMLElement) {
                submitButton.textContent = isEdit ? 'Speichern' : 'Anlegen';
            }
        }

        function openPanel() {
            panel.classList.add('is-open');
            backdrop.classList.add('is-open');
            panel.setAttribute('aria-hidden', 'false');
            document.body.classList.add('overflow-hidden');
        }

        function closePanel() {
            panel.classList.remove('is-open');
            backdrop.classList.remove('is-open');
            panel.setAttribute('aria-hidden', 'true');
            document.body.classList.remove('overflow-hidden');
            clearInlineErrors(form);
        }

        setMode(initialState.mode || 'create');
        if (initialState.open) {
            openPanel();
        }

        if (initialState.formError && typeof initialState.formError === 'object') {
            var formError = form.querySelector('[data-taxonomy-form-error]');
            if (formError instanceof HTMLElement) {
                var details = Array.isArray(initialState.formError.details) ? initialState.formError.details : [];
                formError.innerHTML = [String(initialState.formError.message || '')].concat(details).filter(Boolean).join('<br>');
                formError.classList.remove('d-none');
            }
        }

        openButton.addEventListener('click', function () {
            openPanel();
            if (nameInput instanceof HTMLElement) {
                nameInput.focus();
            }
        });

        closeButtons.forEach(function (button) {
            button.addEventListener('click', function () {
                closePanel();
            });
        });

        backdrop.addEventListener('click', closePanel);

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && panel.classList.contains('is-open')) {
                closePanel();
            }
        });

        if (nameInput instanceof HTMLInputElement && slugInput instanceof HTMLInputElement) {
            slugInput.addEventListener('input', function () {
                slugInput.dataset.manual = '1';
            });
            nameInput.addEventListener('blur', function () {
                if ((slugInput.dataset.manual || '') === '1' && slugInput.value.trim() !== '') {
                    return;
                }
                slugInput.value = slugify(nameInput.value);
            });
        }

        form.addEventListener('submit', function (event) {
            event.preventDefault();
            clearInlineErrors(form);

            var hasError = false;
            if (nameInput instanceof HTMLInputElement && nameInput.value.trim() === '') {
                showInlineError(form, nameInput.getAttribute('name') || 'name', 'Dieses Feld ist erforderlich.');
                hasError = true;
            }

            if (hasError) {
                return;
            }

            var currentSubmitter = submitButton instanceof HTMLButtonElement ? submitButton : null;
            if (currentSubmitter) {
                currentSubmitter.disabled = true;
            }

            var postUrl = form.getAttribute('action') || window.location.pathname;
            var payload = new FormData(form);
            fetch(postUrl, {
                method: 'POST',
                body: payload,
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
                .then(function (response) {
                    return response.text();
                })
                .then(function (html) {
                    var parser = new DOMParser();
                    var nextDoc = parser.parseFromString(html, 'text/html');
                    var nextStateNode = nextDoc.querySelector('[data-taxonomy-panel-state]');
                    var nextState = parseStateFromNode(nextStateNode);
                    var fallbackDangerAlert = nextDoc.querySelector('.alert-danger:not(.d-none):not([data-taxonomy-form-error])');

                    if (nextState && nextState.formError) {
                        var formError = form.querySelector('[data-taxonomy-form-error]');
                        if (formError instanceof HTMLElement) {
                            var details = Array.isArray(nextState.formError.details) ? nextState.formError.details : [];
                            formError.innerHTML = [String(nextState.formError.message || '')].concat(details).filter(Boolean).join('<br>');
                            formError.classList.remove('d-none');
                        }

                        var loweredError = String(nextState.formError.message || '').toLowerCase();
                        if (loweredError.indexOf('name') > -1) {
                            showInlineError(form, nameInput ? nameInput.getAttribute('name') || '' : '', String(nextState.formError.message || ''));
                        } else if (loweredError.indexOf('slug') > -1 && slugInput instanceof HTMLInputElement) {
                            showInlineError(form, slugInput.getAttribute('name') || 'slug', String(nextState.formError.message || ''));
                        }

                        var nextValues = nextState.values && typeof nextState.values === 'object' ? nextState.values : {};
                        Object.keys(nextValues).forEach(function (field) {
                            applyFieldValue(form, field, nextValues[field]);
                        });
                        openPanel();
                        return;
                    }

                    if (fallbackDangerAlert instanceof HTMLElement) {
                        var fallbackError = form.querySelector('[data-taxonomy-form-error]');
                        if (fallbackError instanceof HTMLElement) {
                            fallbackError.textContent = String(fallbackDangerAlert.textContent || 'Speichern fehlgeschlagen.').trim();
                            fallbackError.classList.remove('d-none');
                        }
                        openPanel();
                        return;
                    }

                    closePanel();
                    cmsShowTopToast(nextState && nextState.successMessage ? String(nextState.successMessage) : 'Erfolgreich gespeichert.', 3000);
                    window.setTimeout(function () {
                        window.location.reload();
                    }, 3000);
                })
                .catch(function () {
                    var formError = form.querySelector('[data-taxonomy-form-error]');
                    if (formError instanceof HTMLElement) {
                        formError.textContent = 'Die Anfrage konnte nicht abgeschlossen werden. Bitte erneut versuchen.';
                        formError.classList.remove('d-none');
                    }
                })
                .finally(function () {
                    if (currentSubmitter) {
                        currentSubmitter.disabled = false;
                    }
                });
        });
    });
}

// ── Dialoge: zugänglicher Name (Audit 4.2.0, AP-14, Punkt 14.2) ───────────────────────────
// Bootstrap-Dialoge setzen beim Öffnen role="dialog" und aria-modal="true", aber keinen Namen. 27 von 32 Dialogen im Admin
// hatten weder aria-label noch aria-labelledby; Bildschirmleser sagten nur „Dialog“. Der Titel (.modal-title) wird zum Namen.
function initModalAccessibleNames() {
    document.addEventListener('show.bs.modal', function (event) {
        var modal = event.target;
        if (!(modal instanceof HTMLElement) || modal.hasAttribute('aria-label') || modal.hasAttribute('aria-labelledby')) {
            return;
        }
        var title = modal.querySelector('.modal-title');
        if (!title) {
            return;
        }
        if (!title.id) {
            title.id = (modal.id || 'cms-modal') + '-title';
        }
        modal.setAttribute('aria-labelledby', title.id);
    });
}

document.addEventListener('DOMContentLoaded', function() {
    cleanupStaleGlobalOverlays();
    
    // Confirmation for destructive actions
    const deleteButtons = document.querySelectorAll('[data-confirm]');
    deleteButtons.forEach(button => {
        button.addEventListener('click', function(e) {
            if (!confirm(this.dataset.confirm)) {
                e.preventDefault();
            }
        });
    });
    
    // Active nav highlighting
    const navItems = document.querySelectorAll('.nav-item');
    const currentPath = window.location.pathname;
    
    navItems.forEach(item => {
        if (item.getAttribute('href') === currentPath) {
            item.classList.add('active');
        }
    });

    initModalAccessibleNames();
    initPostCategoryDeleteFlow();
    initPostTagDeleteFlow();
    initReplacementCategoryBulkDeleteFlow();
    initConfirmForms();
    initResponsiveTableDropdowns();
    initGlobalEmptyTablePattern();
    initAdminSearchFallbacks();
    initTaxonomySlideovers();
    
});
