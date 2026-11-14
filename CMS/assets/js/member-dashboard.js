/*!
 * 365CMS Mitgliederbereich – Einstieg
 *
 * Klassisches Skript (mit `defer` eingebunden, auch von Themes ohne `type="module"` nutzbar).
 * Lädt die Seitenmodule aus `js/member/` nur dort, wo sie gebraucht werden (UI-18):
 *   passkey.js  – Passkey-Registrierung (Sicherheit)
 *   uploads.js  – Datei-Upload (Dateien)
 *   media.js    – Umbenennen/Verschieben-Dialoge (Dateien)
 * Gemeinsam: Backup-Codes kopieren (Schaltfläche mit Rückmeldung, UI-12).
 */
(function () {
    'use strict';

    var currentScript = document.currentScript;
    var scriptUrl = currentScript && currentScript.src ? new URL(currentScript.src, window.location.href) : null;

    function moduleUrl(name) {
        if (!scriptUrl) {
            return null;
        }

        var url = new URL('member/' + name + '.js', scriptUrl);
        // Cache-Busting des Einstiegsskripts (?v=…) an die Module weitergeben.
        url.search = scriptUrl.search;

        return url.href;
    }

    function loadModule(name) {
        var url = moduleUrl(name);
        if (!url) {
            return Promise.resolve();
        }

        return import(url).then(function (module) {
            if (module && typeof module.init === 'function') {
                module.init(document);
            }
        }).catch(function (error) {
            if (window.console && typeof window.console.error === 'function') {
                window.console.error('[member-dashboard] Modul ' + name + ' konnte nicht geladen werden.', error);
            }
        });
    }

    function initBackupCodeCopy() {
        document.querySelectorAll('[data-member-copy-codes]').forEach(function (button) {
            var container = button.closest('.alert') || document;
            var codes = container.querySelector('.member-backup-codes');
            var status = container.querySelector('[data-member-copy-status]');
            if (!codes || !navigator.clipboard || typeof navigator.clipboard.writeText !== 'function') {
                return;
            }

            button.hidden = false;
            button.addEventListener('click', function () {
                var text = Array.prototype.map.call(codes.querySelectorAll('code'), function (node) {
                    return node.textContent || '';
                }).join('\n');

                navigator.clipboard.writeText(text).then(function () {
                    if (status) {
                        status.textContent = button.getAttribute('data-copied-label') || '';
                    }
                }).catch(function () {
                    if (status) {
                        status.textContent = button.getAttribute('data-failed-label') || '';
                    }
                });
            });
        });
    }

    function init() {
        initBackupCodeCopy();

        if (document.querySelector('[data-passkey-form]')) {
            loadModule('passkey');
        }
        if (document.querySelector('[data-member-upload-form]')) {
            loadModule('uploads');
        }
        if (document.getElementById('member-media-config')) {
            loadModule('media');
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
}());
