/**
 * Downloads (Frontend): SHA-256 in die Zwischenablage kopieren. Die Schaltflächen tragen den Wert in
 * `data-cms-dl-copy`; der Status wird für Screenreader angesagt.
 */
(function () {
    'use strict';

    function fallbackCopy(text) {
        var area = document.createElement('textarea');
        area.value = text;
        area.setAttribute('readonly', '');
        area.style.position = 'fixed';
        area.style.opacity = '0';
        document.body.appendChild(area);
        area.select();
        var ok = false;
        try {
            ok = document.execCommand('copy');
        } catch (error) {
            ok = false;
        }
        document.body.removeChild(area);
        return ok;
    }

    document.addEventListener('click', function (event) {
        var button = event.target && event.target.closest ? event.target.closest('[data-cms-dl-copy]') : null;
        if (!button) {
            return;
        }
        var text = button.getAttribute('data-cms-dl-copy') || '';
        var status = button.parentNode ? button.parentNode.querySelector('.cms-dl__copy-status') : null;
        var done = function (ok) {
            if (status && ok) {
                status.textContent = button.getAttribute('data-copied-label') || '';
                window.setTimeout(function () {
                    status.textContent = '';
                }, 4000);
            }
        };
        if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
            navigator.clipboard.writeText(text).then(function () {
                done(true);
            }, function () {
                done(fallbackCopy(text));
            });
        } else {
            done(fallbackCopy(text));
        }
    });
}());
