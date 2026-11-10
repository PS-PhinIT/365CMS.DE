/**
 * 365CMS Admin – Site-Wechsler im Kopf der Seitenleiste.
 *
 * Öffnet die Site-Liste (Disclosure), Pfeiltasten wechseln zwischen den Sites.
 * Auswahl einer anderen Site speichert den Kontext serverseitig (POST admin-ui
 * action=switch_site) und lädt den Admin im Kontext dieser Site neu.
 */

import { createDisclosure, moveFocus } from 'cms-admin/dom.js';

export function initSiteSwitcher(sidebar, client) {
    const toggle = sidebar.querySelector('#cms-site-switcher-toggle');
    const menu = sidebar.querySelector('#cms-site-switcher-menu');
    if (!toggle || !menu) {
        return;
    }

    const status = menu.querySelector('[data-cms-site-status]');
    const options = Array.from(menu.querySelectorAll('[data-cms-site-option]'));
    let busy = false;

    const currentOption = () => options.find((option) => option.getAttribute('aria-current') === 'true') || options[0];

    function setStatus(text, isError = false) {
        if (!status) {
            return;
        }
        status.textContent = text;
        status.classList.toggle('is-error', isError);
    }

    const disclosure = createDisclosure(toggle, menu, {
        onOpen({ viaKeyboard }) {
            if (viaKeyboard) {
                currentOption()?.focus();
            }
        },
        onClose() {
            if (!busy) {
                setStatus('');
            }
        },
    });

    toggle.addEventListener('keydown', (event) => {
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            disclosure.open();
            (event.key === 'ArrowUp' ? options[options.length - 1] : currentOption())?.focus();
        }
    });

    menu.addEventListener('keydown', (event) => {
        moveFocus(event, options);
    });

    options.forEach((option) => {
        option.addEventListener('click', async () => {
            if (busy) {
                return;
            }

            if (option.getAttribute('aria-current') === 'true') {
                disclosure.close({ restoreFocus: true });
                return;
            }

            const siteKey = option.dataset.cmsSiteOption || '';
            const label = option.querySelector('.cms-site-switcher__option-label')?.textContent?.trim() || siteKey;

            busy = true;
            menu.setAttribute('aria-busy', 'true');
            options.forEach((item) => item.setAttribute('aria-disabled', 'true'));
            setStatus('Wechsle zu „' + label + '“ …');

            const result = await client.post('switch_site', { site: siteKey });
            if (result.success) {
                setStatus((result.message || 'Site gewechselt.') + ' Admin wird neu geladen …');
                const url = new URL(window.location.href);
                url.hash = '';
                window.location.replace(url.toString());
                return;
            }

            busy = false;
            menu.removeAttribute('aria-busy');
            options.forEach((item) => item.removeAttribute('aria-disabled'));
            setStatus(result.error || 'Die Site konnte nicht gewechselt werden.', true);
            option.focus();
        });
    });
}
