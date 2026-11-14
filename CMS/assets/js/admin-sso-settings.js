/**
 * Einstellungen › SSO (4.1.0, Bereich 1c): Zeilen der Rollen-Mapping-Tabelle hinzufügen/entfernen und den Tenant
 * aus der Mail-Konfiguration übernehmen. Ohne Inline-Skript (CSP mit Nonce und Trusted Types).
 */
(function () {
    'use strict';

    const init = function () {
        const body = document.querySelector('[data-sso-mapping-body]');
        const template = document.querySelector('template[data-sso-mapping-template]');
        const addButton = document.querySelector('[data-sso-mapping-add]');

        if (body && template && addButton) {
            addButton.addEventListener('click', function () {
                const row = template.content.firstElementChild;
                if (!row) {
                    return;
                }
                const clone = row.cloneNode(true);
                body.appendChild(clone);
                const input = clone.querySelector('input');
                if (input) {
                    input.focus();
                }
            });

            body.addEventListener('click', function (event) {
                const target = event.target instanceof Element ? event.target.closest('[data-sso-mapping-remove]') : null;
                if (!target) {
                    return;
                }
                const row = target.closest('[data-sso-mapping-row]');
                if (row) {
                    row.remove();
                    addButton.focus();
                }
            });
        }

        document.querySelectorAll('[data-sso-fill-target]').forEach(function (button) {
            button.addEventListener('click', function () {
                const field = document.getElementById(button.getAttribute('data-sso-fill-target') || '');
                if (field instanceof HTMLInputElement) {
                    field.value = button.getAttribute('data-sso-fill-value') || '';
                    field.focus();
                }
            });
        });
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
}());
