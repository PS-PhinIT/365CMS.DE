/**
 * Formulareingänge (Admin): „Alle auswählen“, Zähler der Auswahl und Rückfrage vor dem endgültigen Löschen
 * per Sammelaktion. Ohne JavaScript bleiben Auswahl und Sammelaktion nutzbar.
 */
(function () {
    'use strict';

    var form = document.querySelector('[data-fs-bulk]');
    if (!form) {
        return;
    }

    var checks = Array.prototype.slice.call(document.querySelectorAll('[data-fs-check]'));
    var checkAll = document.querySelector('[data-fs-check-all]');
    var selected = form.querySelector('[data-fs-selected]');
    var action = form.querySelector('[name="bulk_action"]');
    var confirmed = false;

    function update() {
        var count = checks.filter(function (check) {
            return check.checked;
        }).length;
        if (selected) {
            selected.textContent = count > 0 ? String(form.getAttribute('data-fs-selected-text') || '%d').replace('%d', String(count)) : '';
        }
        if (checkAll) {
            checkAll.checked = count > 0 && count === checks.length;
            checkAll.indeterminate = count > 0 && count < checks.length;
        }
    }

    if (checkAll) {
        checkAll.addEventListener('change', function () {
            checks.forEach(function (check) {
                check.checked = checkAll.checked;
            });
            update();
        });
    }
    checks.forEach(function (check) {
        check.addEventListener('change', update);
    });

    form.addEventListener('submit', function (event) {
        if (confirmed || !action || action.value !== 'delete' || typeof window.cmsConfirm !== 'function') {
            return;
        }
        event.preventDefault();
        window.cmsConfirm({
            title: form.getAttribute('data-fs-delete-title') || '',
            message: form.getAttribute('data-fs-delete-message') || '',
            confirmText: form.getAttribute('data-fs-delete-text') || 'OK',
            confirmClass: 'btn-danger',
            onConfirm: function () {
                confirmed = true;
                form.submit();
            }
        });
    });

    update();
}());
