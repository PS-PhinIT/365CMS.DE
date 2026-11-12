/**
 * Newsletter – Admin (/admin/newsletter): Auswahl aller Abonnenten, Fortschrittsbalken,
 * automatisches Neuladen während eines laufenden Versands.
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-nl-check-all]').forEach(function (master) {
            var form = master.closest('form');
            master.addEventListener('change', function () {
                form.querySelectorAll('input[name="ids[]"]').forEach(function (box) {
                    box.checked = master.checked;
                });
            });
        });

        var bar = document.querySelector('[data-nl-progress]');
        if (bar) {
            bar.style.width = Math.max(0, Math.min(100, parseInt(bar.getAttribute('data-nl-progress'), 10) || 0)) + '%';
            // Laufender Versand: Seite alle 20 Sekunden aktualisieren, solange nichts bearbeitet wird.
            var dirty = false;
            document.addEventListener('input', function () { dirty = true; });
            window.setTimeout(function reload() {
                if (!dirty && document.visibilityState === 'visible') {
                    window.location.reload();
                } else {
                    window.setTimeout(reload, 20000);
                }
            }, 20000);
        }
    });
})();
