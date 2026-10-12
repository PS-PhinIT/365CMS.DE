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
            console.error('admin-theme-explorer: Konfiguration konnte nicht gelesen werden.', error);
            return null;
        }
    }

    function isSubmitControl(element, form) {
        if (!element || !form || element.form !== form) {
            return false;
        }

        if (element.tagName === 'BUTTON') {
            return String(element.getAttribute('type') || element.type || 'submit').toLowerCase() === 'submit';
        }

        return element.tagName === 'INPUT'
            && String(element.getAttribute('type') || element.type || '').toLowerCase() === 'submit';
    }

    function requestNativeSubmit(form, submitter) {
        var temporarySubmitter;

        if (!form) {
            return;
        }

        if (typeof form.requestSubmit === 'function') {
            if (isSubmitControl(submitter, form)) {
                form.requestSubmit(submitter);
                return;
            }

            form.requestSubmit();
            return;
        }

        if (isSubmitControl(submitter, form) && typeof submitter.click === 'function') {
            submitter.click();
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

    function bindEditor(config) {
        var editor = document.querySelector(config.editorSelector || '#codeEditor');
        var form = document.querySelector(config.formSelector || '#themeExplorerForm');
        var saveButton = document.querySelector(config.saveButtonSelector || '#themeExplorerSaveButton');
        var unsavedMessage = String((config && config.unsavedChangesMessage) || form && form.getAttribute('data-unsaved-message') || 'Es gibt ungespeicherte Änderungen.');
        var initialValue = editor ? String(editor.value || '') : '';
        var isDirty = false;

        if (!editor || !form) {
            return;
        }

        function setSubmittingState() {
            form.dataset.submitting = '1';

            if (saveButton) {
                saveButton.disabled = true;
                saveButton.setAttribute('aria-disabled', 'true');
            }
        }

        if (saveButton && saveButton.disabled) {
            saveButton.setAttribute('data-disabled-permanently', '1');
            saveButton.setAttribute('aria-disabled', 'true');
        }

        function syncDirtyState() {
            isDirty = String(editor.value || '') !== initialValue;
            form.dataset.dirty = isDirty ? '1' : '0';
        }

        function markSubmitting() {
            var pendingText = String((config && config.savePendingText) || saveButton && saveButton.getAttribute('data-pending-text') || 'Speichert …');

            if (saveButton) {
                setSubmittingState();
                saveButton.dataset.originalText = saveButton.dataset.originalText || saveButton.textContent;
                saveButton.textContent = pendingText;
            }
        }

        editor.addEventListener('keydown', function (event) {
            if (event.key === 'Tab') {
                event.preventDefault();

                var start = editor.selectionStart;
                var end = editor.selectionEnd;
                editor.value = editor.value.substring(0, start) + '    ' + editor.value.substring(end);
                editor.selectionStart = editor.selectionEnd = start + 4;
                return;
            }

            if (event.ctrlKey && event.key.toLowerCase() === 's') {
                event.preventDefault();
                if (editor.hasAttribute('readonly') || form.dataset.submitting === '1') {
                    return;
                }

                requestNativeSubmit(form, saveButton);
            }
        });

        editor.addEventListener('input', syncDirtyState);
        syncDirtyState();

        form.addEventListener('submit', function (event) {
            if (form.dataset.submitting === '1') {
                event.preventDefault();
                return;
            }

            markSubmitting();
            isDirty = false;
        });

        window.addEventListener('beforeunload', function (event) {
            if (!isDirty) {
                return;
            }

            event.preventDefault();
            event.returnValue = unsavedMessage;
        });
    }

    function bindSearch(config) {
        var linkSelector = config.treeLinkSelector || '[data-theme-explorer-path]';
        var folderSelector = config.treeFolderSelector || '[data-theme-explorer-folder]';
        var input = document.querySelector(config.searchInputSelector || '#themeExplorerSearch');
        var links = Array.prototype.slice.call(document.querySelectorAll(linkSelector));
        var folders = Array.prototype.slice.call(document.querySelectorAll(folderSelector));

        if (!input || (!links.length && !folders.length)) {
            return;
        }

        function showBranch(listItem) {
            Array.prototype.slice.call(listItem.querySelectorAll(linkSelector + ', ' + folderSelector)).forEach(function (node) {
                var branchItem = node.closest('li');
                if (branchItem) {
                    branchItem.style.display = '';
                }
            });
        }

        function syncFolderVisibility(query) {
            folders.slice().reverse().forEach(function (folder) {
                var listItem = folder.closest('li');
                if (!listItem) {
                    return;
                }

                var folderText = String(folder.textContent || '').trim().toLowerCase();
                var folderMatches = query !== '' && folderText.indexOf(query) !== -1;

                if (folderMatches) {
                    listItem.style.display = '';
                    showBranch(listItem);
                    return;
                }

                var hasVisibleFile = Array.prototype.slice.call(listItem.querySelectorAll(linkSelector)).some(function (link) {
                    var linkItem = link.closest('li');
                    return !!linkItem && linkItem.style.display !== 'none';
                });

                var hasVisibleFolder = Array.prototype.slice.call(listItem.querySelectorAll(folderSelector)).some(function (childFolder) {
                    if (childFolder === folder) {
                        return false;
                    }

                    var childItem = childFolder.closest('li');
                    return !!childItem && childItem.style.display !== 'none';
                });

                listItem.style.display = query === '' || hasVisibleFile || hasVisibleFolder ? '' : 'none';
            });
        }

        input.addEventListener('input', function () {
            var query = String(input.value || '').trim().toLowerCase();

            links.forEach(function (link) {
                var listItem = link.closest('li');
                if (!listItem) {
                    return;
                }

                var text = String(link.textContent || '').trim().toLowerCase();
                listItem.style.display = query === '' || text.indexOf(query) !== -1 ? '' : 'none';
            });

            syncFolderVisibility(query);
        });

        syncFolderVisibility('');
    }

    document.addEventListener('DOMContentLoaded', function () {
        var config = parseConfig('theme-explorer-config') || {};
        bindEditor(config);
        bindSearch(config);
    });
})();