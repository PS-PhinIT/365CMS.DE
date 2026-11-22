/**
 * Community – Forenstruktur (/admin/community-structure): Reihenfolge per Ziehen.
 *
 * Gezogen wird am Griff (`[data-cc-grip]`) eines Eintrags mit `data-cc-sort="<art>:<ebene>"`; abgelegt werden darf nur auf einem
 * Eintrag derselben Ebene. Danach sendet das versteckte Formular `form[data-cc-reorder="<art>:<ebene>"]` die neue Reihenfolge
 * (`order` = IDs mit Komma) an die Aktion `reorder`. Die Pfeil-Schaltflächen bleiben die Alternative für die Tastatur.
 */
(function () {
    'use strict';

    var dragged = null;

    function itemOf(node) {
        return node instanceof Element ? node.closest('[data-cc-sort]') : null;
    }

    function sameGroup(a, b) {
        return a && b && a !== b && a.getAttribute('data-cc-sort') === b.getAttribute('data-cc-sort');
    }

    function init() {
        var items = document.querySelectorAll('[data-cc-sort]');
        if (!items.length) {
            return;
        }

        items.forEach(function (item) {
            var grip = item.querySelector('[data-cc-grip]');
            if (grip) {
                grip.style.cursor = 'grab';
                grip.addEventListener('mousedown', function () { item.draggable = true; });
                grip.addEventListener('mouseup', function () { item.draggable = false; });
            }

            item.addEventListener('dragstart', function (event) {
                if (!item.draggable) {
                    event.preventDefault();
                    return;
                }
                dragged = item;
                item.classList.add('opacity-50');
                if (event.dataTransfer) {
                    event.dataTransfer.effectAllowed = 'move';
                    event.dataTransfer.setData('text/plain', item.getAttribute('data-id') || '');
                }
            });

            item.addEventListener('dragend', function () {
                item.draggable = false;
                item.classList.remove('opacity-50');
                document.querySelectorAll('[data-cc-sort].bg-azure-lt').forEach(function (node) { node.classList.remove('bg-azure-lt'); });
                dragged = null;
            });

            item.addEventListener('dragover', function (event) {
                if (sameGroup(dragged, item)) {
                    event.preventDefault();
                    item.classList.add('bg-azure-lt');
                }
            });

            item.addEventListener('dragleave', function () {
                item.classList.remove('bg-azure-lt');
            });

            item.addEventListener('drop', function (event) {
                var target = itemOf(event.target);
                if (!sameGroup(dragged, target)) {
                    return;
                }
                event.preventDefault();
                var group = target.getAttribute('data-cc-sort');
                var ids = [];
                document.querySelectorAll('[data-cc-sort]').forEach(function (node) {
                    if (node.getAttribute('data-cc-sort') === group && node !== dragged) {
                        ids.push(node.getAttribute('data-id'));
                    }
                });
                var rect = target.getBoundingClientRect();
                var index = ids.indexOf(target.getAttribute('data-id'));
                if (event.clientY > rect.top + rect.height / 2) {
                    index += 1;
                }
                ids.splice(index, 0, dragged.getAttribute('data-id'));

                var form = null;
                document.querySelectorAll('form[data-cc-reorder]').forEach(function (candidate) {
                    if (candidate.getAttribute('data-cc-reorder') === group) {
                        form = candidate;
                    }
                });
                if (!form || !form.elements.order) {
                    return;
                }
                form.elements.order.value = ids.join(',');
                form.submit();
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
