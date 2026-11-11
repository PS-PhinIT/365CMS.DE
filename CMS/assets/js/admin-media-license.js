/**
 * Medienbibliothek: Urheber & Lizenz bearbeiten (#11)
 *
 * - Einzeldatei: Klick auf `.js-media-license-edit` (Spalte „Lizenz“ bzw. Kachelzeile) öffnet
 *   `#mediaLicenseModal` mit den gespeicherten Angaben.
 * - Sammelaktion: Der Button „Lizenz für Auswahl …“ wird in `#mediaBulkFormWrap` eingefügt und
 *   nutzt die vorhandenen Auswahl-Checkboxen (`.bulk-row-check`); Ordner werden übersprungen.
 * - Speichern per POST an `/admin/media-license-api` (CSRF-Aktion `admin_media_license`).
 *
 * Keine nativen Dialoge, keine Inline-Handler.
 */
(function () {
    'use strict';

    var FIELD_NAMES = ['creator', 'license', 'license_custom', 'license_url', 'source_url', 'credit', 'copyright', 'ai_tool'];

    function init() {
        var modalElement = document.getElementById('mediaLicenseModal');
        var form = document.getElementById('mediaLicenseForm');
        if (!modalElement || !form) {
            return;
        }

        var endpoint = modalElement.getAttribute('data-endpoint') || '';
        var token = modalElement.getAttribute('data-csrf-token') || '';
        var labels = {
            titleSingle: modalElement.getAttribute('data-label-title-single') || '',
            titleBulk: modalElement.getAttribute('data-label-title-bulk') || '',
            bulkButton: modalElement.getAttribute('data-label-bulk-button') || '',
            bulkCount: modalElement.getAttribute('data-label-bulk-count') || '%d',
            noSelection: modalElement.getAttribute('data-label-no-selection') || '',
            columnEmpty: modalElement.getAttribute('data-label-column-empty') || '',
            creditPreview: modalElement.getAttribute('data-label-credit-preview') || '',
            error: modalElement.getAttribute('data-label-error') || ''
        };
        var title = document.getElementById('mediaLicenseModalTitle');
        var target = document.getElementById('mediaLicenseTarget');
        var bulkModeWrap = document.getElementById('mediaLicenseBulkMode');
        var licenseSelect = document.getElementById('mediaLicenseLicense');
        var customWrap = document.getElementById('mediaLicenseCustomWrap');
        var aiCheckbox = document.getElementById('mediaLicenseAi');
        var aiToolWrap = document.getElementById('mediaLicenseAiToolWrap');
        var preview = document.getElementById('mediaLicensePreview');
        var errorBox = document.getElementById('mediaLicenseError');
        var submitButton = form.querySelector('button[type="submit"]');
        var modal = window.bootstrap && window.bootstrap.Modal ? window.bootstrap.Modal.getOrCreateInstance(modalElement) : null;
        var state = { mode: 'single', path: '', paths: [], trigger: null, busy: false };

        function toast(type, message) {
            if (typeof window.cmsToast === 'function' && message) {
                window.cmsToast(type, message);
            }
        }

        function field(name) {
            return form.elements.namedItem(name);
        }

        function toggleDependentFields() {
            if (customWrap) {
                customWrap.classList.toggle('d-none', !licenseSelect || licenseSelect.value !== 'custom');
            }
            if (aiToolWrap) {
                aiToolWrap.classList.toggle('d-none', !aiCheckbox || !aiCheckbox.checked);
            }
        }

        function showError(message) {
            if (!errorBox) {
                return;
            }
            errorBox.textContent = message || '';
            errorBox.classList.toggle('d-none', !message);
        }

        function fillForm(license) {
            FIELD_NAMES.forEach(function (name) {
                var input = field(name);
                if (input && 'value' in input) {
                    input.value = license && typeof license[name] === 'string' ? license[name] : '';
                }
            });
            if (aiCheckbox) {
                aiCheckbox.checked = !!(license && license.ai_generated);
            }
            var mergeRadio = form.querySelector('input[name="mode"][value="merge"]');
            if (mergeRadio) {
                mergeRadio.checked = true;
            }
            toggleDependentFields();
            showError('');
        }

        function parseLicense(trigger) {
            try {
                var parsed = JSON.parse(trigger.getAttribute('data-media-license') || '{}');
                return parsed && typeof parsed === 'object' ? parsed : {};
            } catch (e) {
                return {};
            }
        }

        function setPreview(text) {
            if (!preview) {
                return;
            }
            preview.textContent = text ? labels.creditPreview + ' ' + text : '';
        }

        function open() {
            if (modal) {
                modal.show();
            } else {
                modalElement.classList.add('show');
                modalElement.style.display = 'block';
            }
        }

        function close() {
            if (modal) {
                modal.hide();
            } else {
                modalElement.classList.remove('show');
                modalElement.style.display = 'none';
            }
        }

        function openSingle(trigger) {
            state.mode = 'single';
            state.path = trigger.getAttribute('data-media-path') || '';
            state.paths = [];
            state.trigger = trigger;
            fillForm(parseLicense(trigger));
            if (title) {
                title.textContent = labels.titleSingle;
            }
            if (target) {
                target.textContent = trigger.getAttribute('data-media-name') || state.path;
            }
            if (bulkModeWrap) {
                bulkModeWrap.classList.add('d-none');
            }
            setPreview(trigger.getAttribute('data-media-credit') || '');
            open();
        }

        function licenseTriggers() {
            return Array.prototype.slice.call(document.querySelectorAll('.js-media-license-edit[data-media-path]'));
        }

        function selectedFilePaths() {
            var filePaths = {};
            licenseTriggers().forEach(function (trigger) {
                filePaths[trigger.getAttribute('data-media-path') || ''] = true;
            });
            var root = document.querySelector('[data-media-library-root]') || document;
            var result = [];
            root.querySelectorAll('.bulk-row-check').forEach(function (checkbox) {
                var value = String(checkbox.value || '');
                if (checkbox.checked && filePaths[value] && result.indexOf(value) === -1) {
                    result.push(value);
                }
            });
            return result;
        }

        function openBulk() {
            var paths = selectedFilePaths();
            if (paths.length === 0) {
                toast('warning', labels.noSelection);
                return;
            }
            state.mode = 'bulk';
            state.path = '';
            state.paths = paths;
            state.trigger = null;
            fillForm({});
            if (title) {
                title.textContent = labels.titleBulk;
            }
            if (target) {
                target.textContent = labels.bulkCount.replace('%d', String(paths.length));
            }
            if (bulkModeWrap) {
                bulkModeWrap.classList.remove('d-none');
            }
            setPreview('');
            open();
        }

        function updateTrigger(path, item) {
            licenseTriggers().forEach(function (trigger) {
                if ((trigger.getAttribute('data-media-path') || '') !== path) {
                    return;
                }
                trigger.setAttribute('data-media-license', JSON.stringify(item.license && !Array.isArray(item.license) ? item.license : {}));
                trigger.setAttribute('data-media-credit', item.credit_text || '');
                var summary = trigger.querySelector('.js-media-license-summary');
                if (summary) {
                    summary.textContent = item.summary || labels.columnEmpty;
                    summary.classList.toggle('text-secondary', !item.summary);
                }
            });
        }

        function setBusy(busy) {
            state.busy = busy;
            if (submitButton) {
                submitButton.disabled = busy;
                submitButton.setAttribute('aria-busy', busy ? 'true' : 'false');
            }
        }

        form.addEventListener('submit', function (event) {
            event.preventDefault();
            if (state.busy || !endpoint) {
                return;
            }

            var body = new FormData(form);
            body.set('csrf_token', token);
            if (state.mode === 'bulk') {
                body.set('action', 'bulk_save');
                state.paths.forEach(function (path) {
                    body.append('paths[]', path);
                });
            } else {
                body.set('action', 'save');
                body.set('path', state.path);
                body.delete('mode');
            }

            setBusy(true);
            showError('');
            fetch(endpoint, {
                method: 'POST',
                body: body,
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
            }).then(function (response) {
                return response.json().catch(function () {
                    return { success: false };
                });
            }).then(function (payload) {
                setBusy(false);
                if (!payload || payload.success !== true) {
                    showError(payload && payload.error ? String(payload.error) : labels.error);
                    return;
                }
                if (state.mode === 'bulk') {
                    var items = payload.items && typeof payload.items === 'object' ? payload.items : {};
                    Object.keys(items).forEach(function (path) {
                        updateTrigger(path, items[path] || {});
                    });
                } else {
                    updateTrigger(state.path, payload);
                }
                close();
                toast('success', payload.message || '');
            }).catch(function () {
                setBusy(false);
                showError(labels.error);
            });
        });

        document.addEventListener('click', function (event) {
            var trigger = event.target && event.target.closest ? event.target.closest('.js-media-license-edit') : null;
            if (trigger) {
                event.preventDefault();
                openSingle(trigger);
            }
        });

        if (licenseSelect) {
            licenseSelect.addEventListener('change', toggleDependentFields);
        }
        if (aiCheckbox) {
            aiCheckbox.addEventListener('change', toggleDependentFields);
        }

        var bulkWrap = document.getElementById('mediaBulkFormWrap');
        var bulkSubmit = bulkWrap ? bulkWrap.querySelector('button[type="submit"]') : null;
        if (bulkSubmit && labels.bulkButton && licenseTriggers().length > 0) {
            var bulkButton = document.createElement('button');
            bulkButton.type = 'button';
            bulkButton.className = 'btn btn-outline-secondary';
            bulkButton.id = 'mediaLicenseBulkButton';
            bulkButton.innerHTML = '<i class="ti ti-license me-1" aria-hidden="true"></i>';
            bulkButton.appendChild(document.createTextNode(labels.bulkButton));
            bulkButton.addEventListener('click', openBulk);
            bulkSubmit.parentNode.insertBefore(bulkButton, bulkSubmit);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
