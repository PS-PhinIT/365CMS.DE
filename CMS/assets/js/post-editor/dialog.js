/**
 * 365CMS Beitragseditor – Helfer für native <dialog>-Elemente.
 *
 * - showModal() liefert Fokusfalle, Escape (cancel) und inert-Hintergrund nativ.
 * - [data-dialog-close] und ein Klick auf den Backdrop schließen den Dialog.
 * - Nach dem Schließen geht der Fokus an den Auslöser zurück, außer onClose()
 *   liefert true (der Aufrufer hat den Fokus bereits selbst gesetzt).
 */

/**
 * @param {HTMLDialogElement} dialog
 * @param {{onClose?: () => (boolean|void)}} options
 */
export function setupDialog(dialog, { onClose = null } = {}) {
    let returnFocusTo = null;
    let pointerStartedOnBackdrop = false;

    dialog.addEventListener('pointerdown', (event) => {
        pointerStartedOnBackdrop = event.target === dialog;
    });

    dialog.addEventListener('click', (event) => {
        const target = event.target;
        if (target === dialog) {
            // Nur echte Backdrop-Klicks schließen – nicht das Ende einer Textauswahl im Dialog.
            if (pointerStartedOnBackdrop) {
                dialog.close();
            }
            return;
        }

        if (target instanceof Element && target.closest('[data-dialog-close]')) {
            dialog.close();
        }
    });

    dialog.addEventListener('close', () => {
        // close wird asynchron ausgelöst: wurde der Dialog inzwischen erneut geöffnet, ist das Event veraltet.
        if (dialog.open) {
            return;
        }

        const target = returnFocusTo;
        returnFocusTo = null;
        pointerStartedOnBackdrop = false;

        const handled = typeof onClose === 'function' && onClose() === true;
        if (!handled && target instanceof HTMLElement && target.isConnected) {
            target.focus();
        }
    });

    return {
        open(trigger = document.activeElement) {
            returnFocusTo = trigger instanceof HTMLElement ? trigger : null;
            if (!dialog.open) {
                dialog.showModal();
            }
        },
        close() {
            if (dialog.open) {
                dialog.close();
            }
        },
        isOpen() {
            return dialog.open;
        },
    };
}

/**
 * Entfernt alle Kindknoten (Ersatz für innerHTML = '').
 *
 * @param {Element|null} element
 */
export function clearChildren(element) {
    while (element && element.firstChild) {
        element.removeChild(element.firstChild);
    }
}

/**
 * Erzeugt ein Element mit Klassen und optionalem Text (ohne innerHTML).
 *
 * @param {string} tag
 * @param {string} [className]
 * @param {string} [text]
 */
export function createElement(tag, className = '', text = '') {
    const element = document.createElement(tag);
    if (className !== '') {
        element.className = className;
    }
    if (text !== '') {
        element.textContent = text;
    }

    return element;
}
