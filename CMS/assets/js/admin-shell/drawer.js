/**
 * 365CMS Admin – Off-Canvas-Navigation (< 992px).
 *
 * Hamburger [data-cms-drawer-open] öffnet die Seitenleiste als Drawer:
 * - Hintergrund (.page-wrapper) wird inert, Fokus bleibt in der Seitenleiste (Fokusfalle)
 * - Escape, Hintergrund-Klick und [data-cms-drawer-close] schließen
 * - Fokus kehrt zum auslösenden Button zurück; ab 992px wird der Drawer automatisch geschlossen
 */

import { focusableWithin } from 'cms-admin/dom.js';

const DESKTOP_QUERY = '(min-width: 992px)';

export function initDrawer(sidebar) {
    const root = document.documentElement;
    const openers = Array.from(document.querySelectorAll('[data-cms-drawer-open]'));
    const closeButton = sidebar.querySelector('[data-cms-drawer-close]');
    const backdrop = document.querySelector('[data-cms-drawer-backdrop]');
    const pageWrapper = document.querySelector('.page > .page-wrapper');
    const desktop = window.matchMedia(DESKTOP_QUERY);
    let lastOpener = null;

    const isOpen = () => root.classList.contains('cms-drawer-open');

    function handleKeyDown(event) {
        if (event.key === 'Escape') {
            if (!event.defaultPrevented) {
                event.preventDefault();
                close();
            }
            return;
        }

        if (event.key !== 'Tab') {
            return;
        }

        const items = focusableWithin(sidebar);
        if (items.length === 0) {
            event.preventDefault();
            return;
        }

        const first = items[0];
        const last = items[items.length - 1];
        const active = document.activeElement;
        if (event.shiftKey && (active === first || !sidebar.contains(active))) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && (active === last || !sidebar.contains(active))) {
            event.preventDefault();
            first.focus();
        }
    }

    function open(opener) {
        if (isOpen() || desktop.matches) {
            return;
        }

        lastOpener = opener || openers[0] || null;
        root.classList.add('cms-drawer-open');
        if (backdrop) {
            backdrop.hidden = false;
        }
        pageWrapper?.setAttribute('inert', '');
        openers.forEach((button) => button.setAttribute('aria-expanded', 'true'));
        document.addEventListener('keydown', handleKeyDown);

        window.requestAnimationFrame(() => {
            (closeButton || focusableWithin(sidebar)[0])?.focus();
        });
    }

    function close({ restoreFocus = true } = {}) {
        if (!isOpen()) {
            return;
        }

        const focusWasInside = sidebar.contains(document.activeElement) || document.activeElement === document.body;
        root.classList.remove('cms-drawer-open');
        if (backdrop) {
            backdrop.hidden = true;
        }
        pageWrapper?.removeAttribute('inert');
        openers.forEach((button) => button.setAttribute('aria-expanded', 'false'));
        document.removeEventListener('keydown', handleKeyDown);

        if (restoreFocus && focusWasInside && lastOpener) {
            lastOpener.focus();
        }
    }

    openers.forEach((button) => button.addEventListener('click', () => open(button)));
    closeButton?.addEventListener('click', () => close());
    backdrop?.addEventListener('click', () => close());

    const handleMediaChange = () => {
        if (desktop.matches) {
            close({ restoreFocus: false });
        }
    };
    if (typeof desktop.addEventListener === 'function') {
        desktop.addEventListener('change', handleMediaChange);
    }

    return { open, close, isOpen };
}
