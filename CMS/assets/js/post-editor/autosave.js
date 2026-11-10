/**
 * 365CMS Beitragseditor – Autosave.
 *
 * Nur für gespeicherte Beiträge (ID > 0) und nur, wenn seit dem letzten Lauf etwas geändert wurde:
 * - Entwürfe: höchstens jede Minute wird der Entwurf selbst gespeichert (Server: TYPE_AUTOSAVE);
 *   der Server legt daraus höchstens alle 10 Minuten eine Revision an.
 * - Veröffentlichte/geplante/private Beiträge bleiben unverändert; höchstens alle 10 Minuten wird
 *   der Zwischenstand als Revision gesichert.
 * Die Statusanzeige in der Kopfleiste zeigt „Gespeichert um HH:MM“ bzw. den Zwischenstand; sie ist
 * bewusst keine Live-Region (kein minütliches Vorlesen) – nur Fehler werden einmalig angesagt.
 */

const TICK_MS = 15000;
const DRAFT_INTERVAL_MS = 60000;
const REVISION_INTERVAL_MS = 600000;

/**
 * @param {HTMLFormElement} form
 * @param {{
 *   client: {post: Function},
 *   statusText?: HTMLElement|null,
 *   statusContainer?: HTMLElement|null,
 *   onRevisions?: (revisions: Array<object>) => void,
 *   announce?: (message: string) => void,
 * }} options
 */
export function initAutosave(form, { client, statusText = null, statusContainer = null, onRevisions = null, announce = null }) {
    const postId = Number.parseInt(form.dataset.postId || '0', 10) || 0;
    if (postId <= 0 || !client) {
        return null;
    }

    const isDraft = String(form.dataset.storedStatus || '') === 'draft';
    const interval = isDraft ? DRAFT_INTERVAL_MS : REVISION_INTERVAL_MS;
    let pendingSince = 0;
    let changeSeq = 0;
    let lastRunAt = Date.now();
    let running = false;
    let submitting = false;
    let controller = null;
    let failureAnnounced = false;
    let submitResetTimer = 0;
    // Letzte zutreffende Statuszeile (vom Server gerendert bzw. nach erfolgreichem Autosave).
    let lastGoodText = statusText ? String(statusText.textContent || '') : '';

    function setStatus(text, state = '') {
        if (statusText && statusText.textContent !== text) {
            statusText.textContent = text;
        }
        if (statusContainer) {
            statusContainer.classList.toggle('is-error', state === 'error');
        }
    }

    function markPending() {
        changeSeq += 1;
        if (pendingSince === 0) {
            pendingSince = Date.now();
        }
    }

    function isPostField(target) {
        if (!(target instanceof Element)) {
            return false;
        }
        if (target.matches('input, select, textarea')) {
            return target.hasAttribute('name') || target.closest('.editorjs-holder, .cms-editor-plain-wrap') !== null;
        }

        // contenteditable des EditorJS bzw. der Plain-Editoren
        return target.closest('.editorjs-holder, .cms-editor-plain-wrap') !== null;
    }

    const handleChange = (event) => {
        if (!submitting && isPostField(event.target)) {
            markPending();
        }
    };

    async function run() {
        running = true;
        const sequenceAtStart = changeSeq;
        pendingSince = 0;
        controller = new AbortController();
        statusContainer?.classList.add('is-saving');
        setStatus(isDraft ? 'Speichert …' : 'Sichert Zwischenstand …');

        const showLastGoodStatus = () => {
            setStatus(lastGoodText);
        };
        const keepPending = () => {
            if (pendingSince === 0) {
                pendingSince = Date.now();
            }
        };

        try {
            await window.cmsContentEditor?.syncAll?.();
        } catch {
            // Fällt auf den zuletzt synchronisierten Stand der Formularfelder zurück.
        }

        if (submitting || controller.signal.aborted) {
            running = false;
            controller = null;
            statusContainer?.classList.remove('is-saving');
            keepPending();
            showLastGoodStatus();
            return;
        }

        const data = new FormData(form);
        data.set('post_id', String(postId));
        const result = await client.post('autosave', data, { signal: controller.signal });

        controller = null;
        running = false;
        lastRunAt = Date.now();
        statusContainer?.classList.remove('is-saving');

        if (result.aborted || submitting) {
            keepPending();
            showLastGoodStatus();
            return;
        }

        if (!result.success) {
            keepPending();
            const message = 'Autosave fehlgeschlagen: ' + String(result.error || 'Unbekannter Fehler.');
            setStatus(message, 'error');
            if (!failureAnnounced) {
                failureAnnounced = true;
                announce?.(message);
            }
            return;
        }

        failureAnnounced = false;
        const label = String(result.saved_at_label || '');

        if (result.mode === 'saved') {
            if (changeSeq === sequenceAtStart) {
                window.cmsContentEditor?.markSaved?.();
            }
            lastGoodText = label !== '' ? 'Gespeichert um ' + label : 'Gespeichert';
            if (statusText && typeof result.saved_at === 'string') {
                statusText.dataset.savedAt = result.saved_at;
            }
            if (result.revision?.reason === 'failed') {
                const message = 'Entwurf gespeichert, aber die Revision konnte nicht gesichert werden.';
                setStatus(message, 'error');
                if (!failureAnnounced) {
                    failureAnnounced = true;
                    announce?.(message);
                }
                return;
            }
        } else if (result.revision && result.revision.stored) {
            lastGoodText = label !== '' ? 'Zwischenstand gesichert um ' + label : 'Zwischenstand gesichert';
        } else if (result.revision && result.revision.reason === 'throttled') {
            keepPending();
        }
        showLastGoodStatus();

        if (Array.isArray(result.revisions)) {
            onRevisions?.(result.revisions);
        }
    }

    function tick() {
        if (running || submitting || pendingSince === 0) {
            return;
        }
        if (Date.now() - lastRunAt < interval) {
            return;
        }

        run();
    }

    form.addEventListener('input', handleChange);
    form.addEventListener('change', handleChange);
    form.addEventListener('submit', () => {
        // Manuelles Speichern hat Vorrang: laufenden Autosave abbrechen, vorerst keinen neuen starten.
        // Bleibt die Seite stehen (Validierungs- oder Editorfehler), gibt der Timer den Autosave wieder frei.
        submitting = true;
        lastRunAt = Date.now();
        controller?.abort();
        window.clearTimeout(submitResetTimer);
        submitResetTimer = window.setTimeout(() => {
            submitting = false;
        }, 30000);
    });

    window.setInterval(tick, TICK_MS);

    return {
        isPending: () => pendingSince !== 0,
        runNow: () => (running ? Promise.resolve() : run()),
    };
}
