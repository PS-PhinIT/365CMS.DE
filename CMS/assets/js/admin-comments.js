(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var selectAll = document.getElementById('selectAll');
        var bulkBar = document.getElementById('bulkBar');
        var bulkForm = document.getElementById('bulkForm');
        var countElement = document.getElementById('selectedCount');
        var bulkAction = document.getElementById('bulkActionComments');
        var bulkSubmit = document.getElementById('bulkSubmitComments');
        var tableWrap = document.querySelector('.comments-table-responsive');
        var rowChecks = Array.prototype.slice.call(document.querySelectorAll('.row-check'));
        var rowActionTriggers = Array.prototype.slice.call(document.querySelectorAll('.comment-row-menu-trigger'));

        function bulkSubmitMeta(action) {
            switch (action) {
                case 'approve':
                    return { text: 'Kommentare freigeben', className: 'btn btn-sm btn-primary' };
                case 'spam':
                    return { text: 'Als Spam markieren', className: 'btn btn-sm btn-warning' };
                case 'trash':
                    return { text: 'In den Papierkorb', className: 'btn btn-sm btn-outline-secondary' };
                case 'delete':
                    return { text: 'Kommentare löschen', className: 'btn btn-sm btn-danger' };
                default:
                    return { text: 'Anwenden', className: 'btn btn-sm btn-primary' };
            }
        }

        function submitForm(form) {
            if (!form) {
                return;
            }

            if (typeof form.requestSubmit === 'function') {
                form.requestSubmit();
                return;
            }

            if (typeof form.reportValidity === 'function' && !form.reportValidity()) {
                return;
            }

            var fallbackSubmitter = document.createElement('button');
            fallbackSubmitter.type = 'submit';
            fallbackSubmitter.hidden = true;
            form.appendChild(fallbackSubmitter);
            fallbackSubmitter.click();
            fallbackSubmitter.remove();
        }

        function updateBulkBar() {
            if (!bulkBar || !countElement) {
                return;
            }

            var checkedCount = rowChecks.filter(function (checkbox) {
                return checkbox.checked;
            }).length;

            countElement.textContent = String(checkedCount);
            bulkBar.classList.toggle('d-none', checkedCount === 0);
            document.body.classList.toggle('comments-batch-mode', checkedCount > 0);
            toggleRowActionsDisabled(checkedCount > 0);
            updateBulkSubmitState(checkedCount);
        }

        function toggleRowActionsDisabled(disabled) {
            rowActionTriggers.forEach(function (trigger) {
                trigger.disabled = !!disabled;
                trigger.setAttribute('aria-disabled', disabled ? 'true' : 'false');
                trigger.classList.toggle('disabled', !!disabled);
            });
        }

        function updateBulkSubmitState(checkedCountOverride) {
            if (!bulkSubmit || !bulkAction) {
                return;
            }

            var checkedCount = typeof checkedCountOverride === 'number'
                ? checkedCountOverride
                : rowChecks.filter(function (checkbox) {
                    return checkbox.checked;
                }).length;
            var action = String(bulkAction.value || '').trim();
            var meta = bulkSubmitMeta(action);

            bulkSubmit.textContent = meta.text;
            bulkSubmit.className = meta.className;
            bulkSubmit.disabled = checkedCount === 0 || action === '';
        }

        function syncSelectAllState() {
            if (!selectAll || rowChecks.length === 0) {
                return;
            }

            var checkedCount = rowChecks.filter(function (checkbox) {
                return checkbox.checked;
            }).length;

            selectAll.checked = checkedCount > 0 && checkedCount === rowChecks.length;
            selectAll.indeterminate = checkedCount > 0 && checkedCount < rowChecks.length;
        }

        function setDropdownOverflow(open) {
            if (!tableWrap) {
                return;
            }

            tableWrap.style.overflow = open ? 'visible' : '';
        }

        if (selectAll) {
            selectAll.addEventListener('change', function () {
                rowChecks.forEach(function (checkbox) {
                    checkbox.checked = selectAll.checked;
                });
                selectAll.indeterminate = false;
                updateBulkBar();
            });
        }

        if (bulkForm) {
            bulkForm.addEventListener('submit', function (event) {
                var selectedAction = bulkAction ? String(bulkAction.value || '').trim() : '';
                var checkedCount = rowChecks.filter(function (checkbox) {
                    return checkbox.checked;
                }).length;

                if (selectedAction === '' || checkedCount === 0) {
                    event.preventDefault();
                    return;
                }

                if (selectedAction !== 'delete') {
                    return;
                }

                if (bulkForm.dataset.confirmAccepted === '1') {
                    bulkForm.dataset.confirmAccepted = '0';
                    return;
                }

                event.preventDefault();

                var message = checkedCount > 1
                    ? String(checkedCount) + ' Kommentare endgültig löschen? Dies kann nicht rückgängig gemacht werden.'
                    : 'Kommentar endgültig löschen? Dies kann nicht rückgängig gemacht werden.';
                var confirmDelete = function () {
                    bulkForm.dataset.confirmAccepted = '1';
                    submitForm(bulkForm);
                };

                try {
                    if (typeof cmsConfirm === 'function') {
                        cmsConfirm({
                            title: 'Kommentare löschen',
                            message: message,
                            confirmText: 'Löschen',
                            confirmClass: 'btn-danger',
                            statusClass: 'bg-danger',
                            onConfirm: confirmDelete,
                        });
                        return;
                    }
                } catch (error) {
                    console.warn('Bestätigungsdialog für Kommentar-Sammellöschung fehlgeschlagen, Fallback auf window.confirm wird verwendet.', error);
                }

                if (window.confirm(message)) {
                    confirmDelete();
                }
            });
        }

        if (bulkAction) {
            bulkAction.addEventListener('change', function () {
                updateBulkSubmitState();
            });
        }

        rowChecks.forEach(function (checkbox) {
            checkbox.addEventListener('change', function () {
                syncSelectAllState();
                updateBulkBar();
            });
        });

        document.querySelectorAll('.comments-table-responsive .dropdown').forEach(function (dropdown) {
            dropdown.addEventListener('show.bs.dropdown', function () {
                setDropdownOverflow(true);
            });

            dropdown.addEventListener('hidden.bs.dropdown', function () {
                setDropdownOverflow(false);
            });
        });

        var editModal = document.getElementById('commentEditModal');
        var editId = document.getElementById('commentEditId');
        var editAuthor = document.getElementById('commentEditAuthor');
        var editContent = document.getElementById('commentEditContent');

        function fillEditForm(trigger) {
            if (!trigger || !editId || !editAuthor || !editContent) {
                return;
            }

            editId.value = trigger.getAttribute('data-comment-id') || '0';
            editAuthor.value = trigger.getAttribute('data-comment-author') || '';
            editContent.value = trigger.getAttribute('data-comment-content') || '';
        }

        document.querySelectorAll('.js-comment-edit-trigger').forEach(function (trigger) {
            trigger.addEventListener('click', function () {
                fillEditForm(trigger);
            });
        });

        if (editModal) {
            editModal.addEventListener('show.bs.modal', function (event) {
                if (event.relatedTarget) {
                    fillEditForm(event.relatedTarget);
                }
            });

            editModal.addEventListener('shown.bs.modal', function () {
                if (editContent) {
                    editContent.focus();
                }
            });
        }

        syncSelectAllState();
        updateBulkBar();
        updateBulkSubmitState();
        toggleRowActionsDisabled(false);
    });
})();