/**
 * 365CMS Beitragseditor – Fokusmodus.
 *
 * Strg+Umschalt+F (macOS: ⌘+Umschalt+F) oder der Kopfleisten-Button blenden Seitenleiste,
 * Topbar und Einstellungsspalte aus (html.cms-focus-mode). Escape beendet den Modus, sofern
 * nicht gerade ein Dialog, Popover oder EditorJS-Menü offen ist.
 * Der Zustand gilt nur für die aktuelle Bearbeitungssitzung (sessionStorage pro Beitrag).
 */

const STORAGE_PREFIX = 'cms.postEditor.focus.';
const PENDING_KEY = STORAGE_PREFIX + 'pending';
const PENDING_TTL_MS = 60000;
const HIDDEN_REGIONS = '.cms-sidebar, .cms-topbar, .cms-editor-settings, .cms-editor-extras';
const OVERLAY_SELECTOR = [
    'dialog[open]',
    '.ce-popover--opened',
    '.ce-inline-toolbar--showed',
    '.ce-conversion-toolbar--showed',
    '.ce-settings--opened',
    '.ce-toolbox--opened',
    '.cms-editor-inserter-panel.is-open',
    '.cms-editor-image-picker.is-open',
    '.modal.show',
    '.cms-unsaved-modal.is-open',
].join(',');

function storageGet(key) {
    try {
        return window.sessionStorage.getItem(key);
    } catch {
        return null;
    }
}

function storageSet(key, value) {
    try {
        if (value === null) {
            window.sessionStorage.removeItem(key);
        } else {
            window.sessionStorage.setItem(key, value);
        }
    } catch {
        // Privater Modus o. Ä.: Zustand dann nur bis zum Neuladen.
    }
}

export function isMacPlatform() {
    const platform = String(navigator.userAgentData?.platform || navigator.platform || navigator.userAgent || '');

    return /mac|iphone|ipad|ipod/i.test(platform);
}

/**
 * @param {HTMLFormElement} form
 * @param {{toggle?: HTMLElement|null, announce?: (message: string) => void}} options
 */
export function initFocusMode(form, { toggle = null, announce = null } = {}) {
    const root = document.documentElement;
    const postId = Number.parseInt(form.dataset.postId || '0', 10) || 0;
    const storageKey = STORAGE_PREFIX + (postId > 0 ? String(postId) : 'new');
    const isMac = isMacPlatform();
    let active = false;
    let overlayOpenAtKeydown = false;

    if (toggle && isMac) {
        toggle.setAttribute('title', 'Fokusmodus (⌘+Umschalt+F)');
        toggle.setAttribute('aria-keyshortcuts', 'Meta+Shift+F');
    }

    function setActive(next, { silent = false } = {}) {
        if (next === active) {
            return;
        }

        active = next;
        root.classList.toggle('cms-focus-mode', active);
        toggle?.setAttribute('aria-pressed', active ? 'true' : 'false');
        storageSet(storageKey, active ? '1' : null);

        if (active) {
            // Fokus nicht in einem gerade ausgeblendeten Bereich zurücklassen.
            const focused = document.activeElement;
            if (focused instanceof Element && focused !== document.body && focused.closest(HIDDEN_REGIONS)) {
                toggle?.focus();
            }
        }

        if (!silent) {
            announce?.(active
                ? 'Fokusmodus aktiv – Seitenleiste und Einstellungen sind ausgeblendet. Escape beendet den Fokusmodus.'
                : 'Fokusmodus beendet.');
        }
    }

    function isShortcut(event) {
        const modifier = isMac ? event.metaKey && !event.ctrlKey : event.ctrlKey && !event.metaKey;
        if (!modifier || !event.shiftKey || event.altKey) {
            return false;
        }

        const key = String(event.key || '');
        if (key.toLowerCase() === 'f') {
            return true;
        }

        // Tastaturbelegungen, die auf der F-Taste kein „f“ liefern.
        return event.code === 'KeyF' && !/^[a-z]$/i.test(key);
    }

    // Capture-Phase: vor EditorJS & Co. festhalten, ob Escape ein offenes Menü/Dialog schließt.
    document.addEventListener('keydown', (event) => {
        overlayOpenAtKeydown = event.key === 'Escape' && document.querySelector(OVERLAY_SELECTOR) !== null;
    }, true);

    document.addEventListener('keydown', (event) => {
        if (isShortcut(event)) {
            event.preventDefault();
            if (event.repeat || root.classList.contains('cms-drawer-open') || document.querySelector('dialog[open]')) {
                return;
            }
            setActive(!active);
            return;
        }

        if (event.key === 'Escape' && active && !event.defaultPrevented && !event.isComposing && !overlayOpenAtKeydown) {
            event.preventDefault();
            setActive(false);
        }
    });

    toggle?.addEventListener('click', () => setActive(!active));

    form.addEventListener('submit', () => {
        // Neuer Beitrag: nach dem ersten Speichern wechselt die URL auf ?id=… – Zustand mitnehmen.
        if (active && postId <= 0) {
            storageSet(PENDING_KEY, String(Date.now()));
        }
    });

    let restore = storageGet(storageKey) === '1';
    const pendingAt = Number.parseInt(storageGet(PENDING_KEY) || '0', 10) || 0;
    if (postId > 0 && pendingAt > 0) {
        storageSet(PENDING_KEY, null);
        restore = restore || Date.now() - pendingAt <= PENDING_TTL_MS;
    }
    if (restore) {
        setActive(true, { silent: true });
    }

    return {
        exit: () => setActive(false),
        toggle: () => setActive(!active),
        isActive: () => active,
    };
}
