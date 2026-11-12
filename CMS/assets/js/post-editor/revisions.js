/**
 * 365CMS Beitragseditor – Revisionsliste, Vergleich und Wiederherstellen.
 *
 * - „Vergleichen“ öffnet einen Dialog: gewählte Revision gegen ihre Vorgängerversion
 *   (Titel/Metadaten als Textdiff, EditorJS-Inhalt Block für Block).
 * - „Diese Version wiederherstellen“ legt serverseitig eine neue Revision an (Historie bleibt
 *   vollständig) und lädt den Editor danach neu.
 * Alle Inhalte werden per textContent/createElement aufgebaut (kein innerHTML).
 */

import { clearChildren, createElement, setupDialog } from 'cms-editor/dialog.js';

const STATUS_LABELS = {
    added: 'Neu',
    removed: 'Entfernt',
    changed: 'Geändert',
    unchanged: 'Unverändert',
};

function joinParts(parts) {
    return parts.map((part) => String(part ?? '').trim()).filter((part) => part !== '').join(' · ');
}

function visuallyHidden(text) {
    return createElement('span', 'visually-hidden', text);
}

/**
 * @param {HTMLElement} container
 * @param {Array<{op: string, text: string}>} segments
 */
function renderSegments(container, segments) {
    const list = Array.isArray(segments) ? segments : [];
    let hasText = false;

    list.forEach((segment) => {
        const text = String(segment?.text ?? '');
        if (text === '') {
            return;
        }
        hasText = true;

        if (segment.op === 'ins' || segment.op === 'del') {
            const isInsert = segment.op === 'ins';
            const element = createElement(isInsert ? 'ins' : 'del', isInsert ? 'cms-diff-ins' : 'cms-diff-del');
            element.append(visuallyHidden(isInsert ? '[Hinzugefügt: ' : '[Entfernt: '), document.createTextNode(text), visuallyHidden(']'));
            container.appendChild(element);
            return;
        }

        container.appendChild(document.createTextNode(text));
    });

    if (!hasText) {
        container.appendChild(createElement('span', 'cms-revision-diff__empty', '(leer)'));
    }
}

function buildBlock(block) {
    const status = Object.hasOwn(STATUS_LABELS, block?.status) ? block.status : 'changed';
    const item = createElement('li', 'cms-revision-block is-' + status);
    const head = createElement('div', 'cms-revision-block__head');
    head.append(
        createElement('span', 'cms-revision-badge is-' + status, STATUS_LABELS[status]),
        createElement('span', 'cms-revision-block__type', String(block?.type_label || block?.type || 'Block'))
    );

    const text = createElement('div', 'cms-revision-block__text cms-revision-diff');
    renderSegments(text, block?.segments);
    item.append(head, text);

    return item;
}

function buildUnchangedGroup(blocks) {
    const item = createElement('li', 'cms-revision-block cms-revision-block--group');
    const details = document.createElement('details');
    details.className = 'cms-revision-unchanged';
    details.appendChild(createElement(
        'summary',
        'cms-revision-unchanged__summary',
        blocks.length === 1 ? '1 unveränderter Block' : blocks.length + ' unveränderte Blöcke'
    ));

    const nested = createElement('ol', 'cms-revision-blocks cms-revision-blocks--nested');
    blocks.forEach((block) => nested.appendChild(buildBlock(block)));
    details.appendChild(nested);
    item.appendChild(details);

    return item;
}

function buildContentSection(entry) {
    const section = createElement('section', 'cms-revision-compare__section');
    const head = createElement('div', 'cms-revision-compare__head');
    const stats = entry?.stats || {};
    head.append(
        createElement('h3', 'cms-revision-compare__heading', String(entry?.label || 'Inhalt')),
        createElement('span', 'cms-revision-compare__stats', [
            (stats.added || 0) + ' neu',
            (stats.removed || 0) + ' entfernt',
            (stats.changed || 0) + ' geändert',
            (stats.unchanged || 0) + ' unverändert',
        ].join(' · '))
    );
    section.appendChild(head);

    const blocks = Array.isArray(entry?.blocks) ? entry.blocks : [];
    if (blocks.length === 0) {
        section.appendChild(createElement('p', 'cms-revision-compare__empty', 'Kein Inhalt vorhanden.'));
        return section;
    }

    const list = createElement('ol', 'cms-revision-blocks');
    let unchangedRun = [];
    const flushRun = () => {
        if (unchangedRun.length > 0) {
            list.appendChild(buildUnchangedGroup(unchangedRun));
            unchangedRun = [];
        }
    };

    blocks.forEach((block) => {
        if (block?.status === 'unchanged') {
            unchangedRun.push(block);
            return;
        }
        flushRun();
        list.appendChild(buildBlock(block));
    });
    flushRun();
    section.appendChild(list);

    return section;
}

function buildFieldsSection(fields) {
    const section = createElement('section', 'cms-revision-compare__section');
    section.appendChild(createElement('h3', 'cms-revision-compare__heading', 'Titel & Metadaten'));

    const list = createElement('dl', 'cms-revision-fields');
    fields.forEach((field) => {
        const row = createElement('div', 'cms-revision-fields__row');
        const value = createElement('dd', 'cms-revision-fields__value cms-revision-diff');
        renderSegments(value, field?.segments);
        row.append(createElement('dt', 'cms-revision-fields__label', String(field?.label || field?.field || 'Feld')), value);
        list.appendChild(row);
    });
    section.appendChild(list);

    return section;
}

/**
 * @param {HTMLFormElement} form
 * @param {{client: {get: Function, post: Function}, announce?: (message: string) => void}} options
 */
export function initRevisions(form, { client, announce = null }) {
    const postId = Number.parseInt(form.dataset.postId || '0', 10) || 0;
    const list = document.getElementById('postRevisionList');
    const empty = document.getElementById('postRevisionEmpty');
    const count = document.getElementById('postRevisionCount');
    const dialog = document.getElementById('postRevisionDialog');

    const noop = { render() {} };
    if (postId <= 0 || !list || !client) {
        return noop;
    }

    const body = document.getElementById('postRevisionDialogBody');
    const meta = document.getElementById('postRevisionDialogMeta');
    const status = document.getElementById('postRevisionDialogStatus');
    const confirmBox = document.getElementById('postRevisionRestoreConfirm');
    const dirtyWarning = document.getElementById('postRevisionRestoreDirtyWarning');
    const cancelButton = document.getElementById('postRevisionRestoreCancel');
    const confirmButton = document.getElementById('postRevisionRestoreConfirmButton');
    const actions = document.getElementById('postRevisionDialogActions');
    const restoreButton = document.getElementById('postRevisionRestoreButton');

    let currentRevisionId = 0;
    let compareController = null;
    let restoring = false;
    let lastTrigger = null;

    function findCompareButton(revisionId) {
        return Array.from(list.querySelectorAll('[data-revision-compare]'))
            .find((button) => button.getAttribute('data-revision-compare') === String(revisionId)) || null;
    }

    function setStatus(text) {
        if (status) {
            status.textContent = text;
        }
    }

    function resetConfirm() {
        if (confirmBox) {
            confirmBox.hidden = true;
        }
        if (actions) {
            actions.hidden = false;
        }
        if (confirmButton) {
            confirmButton.disabled = false;
        }
        if (cancelButton) {
            cancelButton.disabled = false;
        }
    }

    const dialogController = dialog instanceof HTMLDialogElement
        ? setupDialog(dialog, {
            onClose() {
                compareController?.abort();
                compareController = null;
                resetConfirm();
                setStatus('');

                // Wurde die Liste währenddessen neu aufgebaut (Autosave), den passenden neuen Button fokussieren.
                const trigger = lastTrigger;
                lastTrigger = null;
                if (trigger && !trigger.isConnected) {
                    const replacement = findCompareButton(currentRevisionId)
                        || list.querySelector('[data-revision-compare]')
                        || document.querySelector('#postRevisionPanel > summary');
                    if (replacement instanceof HTMLElement) {
                        replacement.focus();
                        return true;
                    }
                }

                return false;
            },
        })
        : null;

    function buildListItem(revision) {
        const id = Number.parseInt(String(revision?.id ?? '0'), 10) || 0;
        const dateLabel = String(revision?.created_at_label || 'Unbekanntes Datum');
        const item = createElement('li', 'cms-revision-list__item');
        item.dataset.revisionId = String(id);

        const metaBox = createElement('div', 'cms-revision-list__meta');
        const time = createElement('time', 'cms-revision-list__time', dateLabel);
        if (revision?.created_at) {
            time.dateTime = String(revision.created_at);
        }
        metaBox.append(
            time,
            ' ',
            createElement('span', 'cms-revision-list__author', joinParts([revision?.author_label || 'Unbekannt', revision?.type_label]))
        );

        const button = createElement('button', 'btn btn-sm btn-outline-secondary cms-revision-list__compare', 'Vergleichen');
        button.type = 'button';
        button.dataset.revisionCompare = String(id);
        button.setAttribute('aria-label', 'Revision vom ' + dateLabel + ' vergleichen');

        item.append(metaBox, ' ', button);

        return item;
    }

    function render(revisions) {
        if (!Array.isArray(revisions)) {
            return;
        }

        const focused = document.activeElement instanceof Element ? document.activeElement.closest('[data-revision-compare]') : null;
        const focusedId = focused && list.contains(focused) ? String(focused.getAttribute('data-revision-compare') || '') : '';

        clearChildren(list);
        revisions.forEach((revision) => list.appendChild(buildListItem(revision)));
        list.hidden = revisions.length === 0;
        if (empty) {
            empty.hidden = revisions.length > 0;
        }
        if (count) {
            count.textContent = String(revisions.length);
        }

        if (focusedId !== '') {
            findCompareButton(focusedId)?.focus();
        }
    }

    function renderMeta(comparison) {
        if (!meta) {
            return;
        }

        clearChildren(meta);
        const revision = comparison?.revision || {};
        const previous = comparison?.previous || null;
        meta.append(
            createElement('span', 'cms-editor-dialog__subtitle-line', joinParts([
                'Revision vom ' + String(revision.created_at_label || 'unbekannten Datum'),
                revision.author_label,
                revision.type_label,
            ])),
            createElement('span', 'cms-editor-dialog__subtitle-line', previous
                ? 'Verglichen mit der Version vom ' + joinParts([previous.created_at_label, previous.author_label, previous.type_label])
                : 'Älteste gespeicherte Version – verglichen mit einem leeren Stand.')
        );
    }

    function renderComparison(comparison) {
        clearChildren(body);
        renderMeta(comparison);

        const fields = Array.isArray(comparison?.fields) ? comparison.fields : [];
        const content = Array.isArray(comparison?.content) ? comparison.content : [];

        if (!comparison?.has_changes) {
            body.appendChild(createElement('p', 'cms-revision-compare__nochange', 'Keine Unterschiede zur vorherigen Version.'));
        }
        if (fields.length > 0) {
            body.appendChild(buildFieldsSection(fields));
        }
        content.forEach((entry) => body.appendChild(buildContentSection(entry)));
    }

    async function openCompare(revisionId, trigger) {
        if (!dialogController || !body) {
            return;
        }

        currentRevisionId = revisionId;
        lastTrigger = trigger instanceof HTMLElement ? trigger : null;
        resetConfirm();
        setStatus('');
        clearChildren(meta);
        clearChildren(body);
        body.setAttribute('aria-busy', 'true');
        body.appendChild(createElement('p', 'cms-revision-compare__loading', 'Vergleich wird geladen …'));
        if (restoreButton) {
            restoreButton.disabled = true;
        }
        dialogController.open(trigger);

        compareController?.abort();
        const controller = new AbortController();
        compareController = controller;
        const result = await client.get('compare', { post_id: postId, revision_id: revisionId }, { signal: controller.signal });
        if (result.aborted || compareController !== controller) {
            return;
        }
        compareController = null;
        body.setAttribute('aria-busy', 'false');

        if (!result.success) {
            clearChildren(body);
            body.appendChild(createElement('p', 'cms-revision-compare__error', String(result.error || 'Der Vergleich konnte nicht geladen werden.')));
            setStatus('Der Vergleich konnte nicht geladen werden.');
            return;
        }

        renderComparison(result.comparison || result);
        if (restoreButton) {
            restoreButton.disabled = false;
        }
        setStatus('Vergleich geladen.');
    }

    list.addEventListener('click', (event) => {
        const trigger = event.target instanceof Element ? event.target.closest('[data-revision-compare]') : null;
        if (!trigger) {
            return;
        }

        const revisionId = Number.parseInt(String(trigger.getAttribute('data-revision-compare') || '0'), 10) || 0;
        if (revisionId > 0) {
            openCompare(revisionId, trigger);
        }
    });

    restoreButton?.addEventListener('click', () => {
        if (currentRevisionId <= 0 || !confirmBox) {
            return;
        }

        if (dirtyWarning) {
            dirtyWarning.hidden = !window.cmsContentEditor?.isDirty?.();
        }
        confirmBox.hidden = false;
        if (actions) {
            actions.hidden = true;
        }
        cancelButton?.focus();
    });

    cancelButton?.addEventListener('click', () => {
        resetConfirm();
        restoreButton?.focus();
    });

    confirmButton?.addEventListener('click', async () => {
        if (restoring || currentRevisionId <= 0) {
            return;
        }

        restoring = true;
        confirmButton.disabled = true;
        if (cancelButton) {
            cancelButton.disabled = true;
        }
        setStatus('Version wird wiederhergestellt …');

        const result = await client.post('restore', { post_id: postId, revision_id: currentRevisionId });
        restoring = false;

        if (result.success && !result.unchanged) {
            setStatus(String(result.message || 'Version wiederhergestellt.') + ' Der Editor wird neu geladen …');
            window.cmsContentEditor?.allowLeave?.();
            const activeLanguage = document.querySelector('.cms-editor-langs__link.is-active');
            const target = activeLanguage instanceof HTMLAnchorElement && activeLanguage.href ? activeLanguage.href : window.location.href;
            window.location.assign(target);
            return;
        }

        resetConfirm();
        const message = result.success
            ? String(result.message || 'Diese Version entspricht bereits dem aktuellen Stand.')
            : String(result.error || 'Die Version konnte nicht wiederhergestellt werden.');
        setStatus(message);
        announce?.(message);
        restoreButton?.focus();
    });

    // Sprung aus „Letzte Änderungen“: ?revision=<id> öffnet das Panel und den Vergleich dieser Revision.
    const requestedRevision = Number.parseInt(new URLSearchParams(window.location.search).get('revision') || '0', 10) || 0;
    if (requestedRevision > 0) {
        const panel = document.getElementById('postRevisionPanel');
        if (panel instanceof HTMLDetailsElement) {
            panel.open = true;
        }
        window.setTimeout(() => {
            openCompare(requestedRevision, findCompareButton(requestedRevision));
        }, 0);
    }

    return { render };
}
