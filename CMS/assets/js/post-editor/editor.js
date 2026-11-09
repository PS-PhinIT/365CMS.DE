/**
 * 365CMS Beitragseditor – Einstiegsmodul (4.0.0).
 *
 * Verdrahtet die kleinen Editor-Module mit dem bestehenden Formular:
 * Fokusmodus, Veröffentlichungs-Checkliste, Revisionen und Autosave.
 * Die EditorJS-Integration (admin-content-editor.js) bleibt unverändert zuständig für
 * Inhalte, Validierung und das eigentliche Absenden.
 */

import { createJsonClient } from 'cms-admin/api.js';
import { initAutosave } from 'cms-editor/autosave.js';
import { initChecklist } from 'cms-editor/checklist.js';
import { initFocusMode } from 'cms-editor/focus-mode.js';
import { initRevisions } from 'cms-editor/revisions.js';

function createAnnouncer() {
    const region = document.createElement('div');
    region.className = 'visually-hidden';
    region.setAttribute('role', 'status');
    region.setAttribute('aria-live', 'polite');
    region.setAttribute('aria-atomic', 'true');
    document.body.appendChild(region);

    let timer = 0;

    return (message) => {
        const text = String(message ?? '').trim();
        if (text === '') {
            return;
        }

        // Erst leeren, dann setzen – so werden auch identische Meldungen erneut vorgelesen.
        region.textContent = '';
        window.clearTimeout(timer);
        timer = window.setTimeout(() => {
            region.textContent = text;
        }, 80);
    };
}

function applyWritingWidth(form) {
    const width = Number.parseInt(form.dataset.writingWidth || '', 10);
    if (Number.isFinite(width) && width >= 320 && width <= 1600) {
        form.style.setProperty('--cms-editor-writing-width', width + 'px');
    }
}

function trackStickyOffsets() {
    const topbar = document.querySelector('.cms-topbar');
    const header = document.getElementById('postEditorHeader');
    const root = document.documentElement;

    const update = () => {
        const topbarHeight = topbar && window.getComputedStyle(topbar).position === 'sticky'
            ? Math.round(topbar.getBoundingClientRect().height)
            : 0;
        root.style.setProperty('--cms-editor-header-offset', topbarHeight + 'px');
        if (header) {
            root.style.setProperty('--cms-editor-bar-height', Math.round(header.getBoundingClientRect().height) + 'px');
        }
    };

    update();
    if ('ResizeObserver' in window) {
        const observer = new ResizeObserver(update);
        [topbar, header].forEach((element) => {
            if (element) {
                observer.observe(element);
            }
        });
    } else {
        window.addEventListener('resize', update);
    }
}

function initPreviewHint(announce) {
    const button = document.getElementById('postPreviewButton');
    const hint = document.getElementById('postPreviewHint');
    if (!(button instanceof HTMLButtonElement) || button.getAttribute('aria-disabled') !== 'true') {
        return;
    }

    const message = hint ? String(hint.textContent || '').trim() : 'Die Vorschau ist nach dem ersten Speichern verfügbar.';
    button.title = message;
    button.addEventListener('click', (event) => {
        event.preventDefault();
        announce(message);
    });
}

/**
 * Der Titel ist eine einzeilige Textarea, damit lange Titel umbrechen statt abgeschnitten zu werden.
 * Zeilenumbrüche werden verhindert bzw. beim Einfügen zu Leerzeichen; ohne `field-sizing` wächst
 * das Feld per CSSOM mit dem Inhalt.
 */
function initTitleField(form) {
    const field = form.querySelector('textarea.cms-editor-title-input');
    if (!(field instanceof HTMLTextAreaElement)) {
        return;
    }

    const needsAutosize = !(window.CSS && typeof window.CSS.supports === 'function' && window.CSS.supports('field-sizing', 'content'));
    const resize = () => {
        if (!needsAutosize) {
            return;
        }
        field.style.setProperty('height', 'auto');
        field.style.setProperty('height', field.scrollHeight + 'px');
    };

    field.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' && !event.isComposing) {
            event.preventDefault();
        }
    });

    field.addEventListener('input', () => {
        if (/[\r\n]/.test(field.value)) {
            const caret = field.selectionStart;
            const before = field.value.slice(0, caret).replace(/\s*[\r\n]+\s*/g, ' ');
            field.value = field.value.replace(/\s*[\r\n]+\s*/g, ' ');
            field.setSelectionRange(before.length, before.length);
        }
        resize();
    });

    resize();
    if (needsAutosize && 'ResizeObserver' in window) {
        let lastWidth = field.clientWidth;
        new ResizeObserver(() => {
            if (field.clientWidth !== lastWidth) {
                lastWidth = field.clientWidth;
                resize();
            }
        }).observe(field);
    }
}

function init() {
    const form = document.querySelector('form[data-post-editor]');
    if (!(form instanceof HTMLFormElement)) {
        return;
    }

    applyWritingWidth(form);
    trackStickyOffsets();
    initTitleField(form);

    const announce = createAnnouncer();
    const endpoint = String(form.dataset.apiUrl || '');
    const client = endpoint !== '' ? createJsonClient({ endpoint, token: String(form.dataset.apiToken || '') }) : null;

    initPreviewHint(announce);

    const focusMode = initFocusMode(form, {
        toggle: document.getElementById('postFocusModeToggle'),
        announce,
    });

    initChecklist(form, {
        beforeReveal: () => focusMode?.exit?.(),
        announce,
    });

    const revisions = client && document.getElementById('postRevisionPanel')
        ? initRevisions(form, { client, announce })
        : null;

    if (client) {
        initAutosave(form, {
            client,
            statusText: document.getElementById('postEditorSaveStatus'),
            statusContainer: document.getElementById('postEditorSaveState'),
            onRevisions: (list) => revisions?.render(list),
            announce,
        });
    }
}

init();
