/**
 * 365CMS Admin – kleine DOM-Helfer für die Admin-Shell.
 *
 * - focusableWithin(): sichtbare, fokussierbare Elemente eines Containers
 * - moveFocus():       Pfeiltasten-Navigation (Up/Down/Home/End) innerhalb einer Liste
 * - createDisclosure(): Button (aria-expanded) + Panel (hidden) mit Escape,
 *                       Klick außerhalb und Fokusverlust als Schließen-Auslöser
 */

export const FOCUSABLE_SELECTOR = [
    'a[href]',
    'button:not([disabled])',
    'input:not([disabled]):not([type="hidden"])',
    'select:not([disabled])',
    'textarea:not([disabled])',
    '[tabindex]:not([tabindex="-1"])',
].join(',');

export function isVisible(element) {
    if (!(element instanceof HTMLElement) || !element.isConnected) {
        return false;
    }

    if (typeof element.checkVisibility === 'function') {
        return element.checkVisibility({ visibilityProperty: true, checkVisibilityCSS: true });
    }

    return element.getClientRects().length > 0 && window.getComputedStyle(element).visibility !== 'hidden';
}

export function focusableWithin(root, { exclude = null } = {}) {
    if (!root) {
        return [];
    }

    return Array.from(root.querySelectorAll(FOCUSABLE_SELECTOR)).filter((element) => {
        if (element.closest('[inert]') || !isVisible(element)) {
            return false;
        }

        return !(exclude && element.closest(exclude));
    });
}

/**
 * Verschiebt den Fokus innerhalb von `items` gemäß Pfeiltaste. Liefert true, wenn behandelt.
 *
 * @param {KeyboardEvent} event
 * @param {HTMLElement[]} items
 * @param {{wrap?: boolean}} options
 */
export function moveFocus(event, items, { wrap = true } = {}) {
    if (items.length === 0 || !['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) {
        return false;
    }

    const index = items.indexOf(document.activeElement);
    let next = index;
    if (event.key === 'Home') {
        next = 0;
    } else if (event.key === 'End') {
        next = items.length - 1;
    } else if (event.key === 'ArrowDown') {
        next = index < 0 ? 0 : index + 1;
        if (next >= items.length) {
            next = wrap ? 0 : items.length - 1;
        }
    } else {
        next = index < 0 ? items.length - 1 : index - 1;
        if (next < 0) {
            next = wrap ? items.length - 1 : 0;
        }
    }

    event.preventDefault();
    items[next].focus();

    return true;
}

/**
 * @param {HTMLElement} button  Auslöser mit aria-expanded / aria-controls
 * @param {HTMLElement} panel   Panel mit hidden-Attribut
 * @param {{onOpen?: Function, onClose?: Function}} hooks
 */
export function createDisclosure(button, panel, { onOpen = null, onClose = null } = {}) {
    let open = !panel.hidden;

    const isInside = (node) => node instanceof Node && (panel.contains(node) || button.contains(node));

    const handlePointerDown = (event) => {
        if (!isInside(event.target)) {
            setOpen(false);
        }
    };

    const handleKeyDown = (event) => {
        if (event.key !== 'Escape' || event.defaultPrevented) {
            return;
        }
        event.preventDefault();
        event.stopPropagation();
        setOpen(false, { restoreFocus: isInside(document.activeElement) || document.activeElement === document.body });
    };

    const handleFocusOut = (event) => {
        if (open && event.relatedTarget instanceof Node && !isInside(event.relatedTarget)) {
            setOpen(false);
        }
    };

    function setOpen(next, { restoreFocus = false, viaKeyboard = false } = {}) {
        if (next === open) {
            return;
        }

        open = next;
        panel.hidden = !open;
        button.setAttribute('aria-expanded', open ? 'true' : 'false');

        if (open) {
            document.addEventListener('pointerdown', handlePointerDown, true);
            document.addEventListener('keydown', handleKeyDown, true);
            onOpen?.({ viaKeyboard });
            return;
        }

        document.removeEventListener('pointerdown', handlePointerDown, true);
        document.removeEventListener('keydown', handleKeyDown, true);
        onClose?.();
        if (restoreFocus) {
            button.focus();
        }
    }

    button.addEventListener('click', (event) => {
        setOpen(!open, { viaKeyboard: event.detail === 0 });
    });
    button.addEventListener('focusout', handleFocusOut);
    panel.addEventListener('focusout', handleFocusOut);

    return {
        open: (options = {}) => setOpen(true, options),
        close: (options = {}) => setOpen(false, options),
        isOpen: () => open,
    };
}
