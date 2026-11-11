/**
 * Downloads (Admin): Auswahl in der Liste, Zusatzfelder der Sammelaktion (Kategorie, Zugriff) und Rückfrage
 * vor dem endgültigen Löschen per Sammelaktion.
 */
(function () {
    'use strict';

    var form = document.querySelector('[data-dl-bulk]');
    if (!form) {
        return;
    }
    var checks = Array.prototype.slice.call(document.querySelectorAll('[data-dl-check]'));
    var checkAll = document.querySelector('[data-dl-check-all]');
    var select = form.querySelector('[data-dl-bulk-select]');
    var extras = Array.prototype.slice.call(form.querySelectorAll('[data-dl-bulk-extra]'));
    var confirmed = false;

    function syncAll() {
        if (!checkAll) {
            return;
        }
        var count = checks.filter(function (check) { return check.checked; }).length;
        checkAll.checked = count > 0 && count === checks.length;
        checkAll.indeterminate = count > 0 && count < checks.length;
    }

    function syncExtras() {
        extras.forEach(function (extra) {
            extra.hidden = !select || extra.getAttribute('data-dl-bulk-extra') !== select.value;
        });
    }

    if (checkAll) {
        checkAll.addEventListener('change', function () {
            checks.forEach(function (check) { check.checked = checkAll.checked; });
        });
    }
    checks.forEach(function (check) { check.addEventListener('change', syncAll); });
    if (select) {
        select.addEventListener('change', syncExtras);
    }

    form.addEventListener('submit', function (event) {
        if (confirmed || !select || select.value !== 'delete' || typeof window.cmsConfirm !== 'function') {
            return;
        }
        event.preventDefault();
        window.cmsConfirm({
            title: form.getAttribute('data-delete-title') || '',
            message: form.getAttribute('data-delete-message') || '',
            confirmText: form.getAttribute('data-delete-text') || 'OK',
            confirmClass: 'btn-danger',
            onConfirm: function () {
                confirmed = true;
                form.submit();
            }
        });
    });

    syncExtras();
}());
