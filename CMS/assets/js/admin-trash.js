/**
 * Zentraler Papierkorb: „Alle auswählen“ und Rückfrage vor dem endgültigen Löschen per Sammelaktion.
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var form = document.querySelector('form[data-trash-bulk]');
        var master = document.querySelector('[data-trash-check-all]');
        var boxes = function () {
            return document.querySelectorAll('input[data-trash-check]');
        };

        if (master) {
            master.addEventListener('change', function () {
                boxes().forEach(function (box) { box.checked = master.checked; });
            });
        }

        if (!form) {
            return;
        }
        form.addEventListener('submit', function (event) {
            var select = form.querySelector('select[name="action"]');
            if (!select || select.value !== 'delete_permanently' || form.dataset.confirmed === '1') {
                return;
            }
            event.preventDefault();
            var submit = function () {
                form.dataset.confirmed = '1';
                if (typeof form.requestSubmit === 'function') {
                    form.requestSubmit();
                } else {
                    form.submit();
                }
            };
            if (typeof window.cmsConfirm === 'function') {
                window.cmsConfirm({
                    title: form.dataset.deleteTitle || '',
                    message: form.dataset.deleteMessage || '',
                    confirmText: form.dataset.deleteText || '',
                    confirmClass: 'btn-danger',
                    onConfirm: submit
                });
            } else {
                submit();
            }
        });
    });
})();
