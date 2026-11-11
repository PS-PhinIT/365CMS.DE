/**
 * Einstellungen → Feeds: „Alle auswählen“ für Massenaktionen und Rückfrage vor dem Löschen per Massenaktion.
 */
(function () {
    'use strict';

    function initCheckAll() {
        document.querySelectorAll('[data-fd-check-all]').forEach(function (master) {
            var formId = master.getAttribute('data-fd-check-all');
            var boxes = function () {
                return document.querySelectorAll('input[data-fd-check][form="' + formId + '"]');
            };
            master.addEventListener('change', function () {
                boxes().forEach(function (box) {
                    box.checked = master.checked;
                });
            });
            boxes().forEach(function (box) {
                box.addEventListener('change', function () {
                    var all = Array.prototype.every.call(boxes(), function (item) { return item.checked; });
                    var some = Array.prototype.some.call(boxes(), function (item) { return item.checked; });
                    master.checked = all;
                    master.indeterminate = some && !all;
                });
            });
        });
    }

    function initBulkConfirm() {
        document.querySelectorAll('form[data-fd-bulk]').forEach(function (form) {
            form.addEventListener('submit', function (event) {
                var select = form.querySelector('select[name="bulk"]');
                var message = form.getAttribute('data-fd-delete-confirm') || '';
                if (select && select.value === 'delete' && message !== '' && !window.confirm(message)) {
                    event.preventDefault();
                }
            });
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        initCheckAll();
        initBulkConfirm();
    });
})();
