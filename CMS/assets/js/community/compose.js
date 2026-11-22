/*
 * Community-Schreibformular (4.2.0): Anhänge (Dateiauswahl, Ziehen und Ablegen, Einfügen), Live-Vorschau sowie lokale (`localStorage`)
 * und serverseitige Entwürfe (`/forum/api/entwurf`). ES-Modul ohne Build-Schritt, kein Inline-Skript; ohne dieses Skript bleibt das Formular
 * vollständig nutzbar (Dateien dann nur über die Bearbeitung). Rechte, Grenzen und Dateiprüfung entscheidet der Server.
 */
const root = document.querySelector('[data-cc-compose]');
const textarea = document.getElementById('cc-body');
const form = textarea ? textarea.closest('form') : null;

if (root && textarea && form) {
    const api = root.getAttribute('data-cc-api') || '';
    const token = root.getAttribute('data-cc-token') || '';
    const titleInput = document.getElementById('cc-title');
    let texts = {};
    try {
        texts = JSON.parse(root.getAttribute('data-cc-texts') || '{}');
    } catch (error) {
        texts = {};
    }
    const t = (key, vars = {}) => Object.entries(vars).reduce((text, [name, value]) => text.split(`{${name}}`).join(String(value)), texts[key] || key);
    const say = (text, isError) => {
        const status = document.getElementById('cc-status');
        if (status) {
            status.textContent = text || '';
            status.setAttribute('role', isError ? 'alert' : 'status');
            status.hidden = !text;
        }
    };

    const post = async (action, body) => {
        body.append('csrf_token', token);
        try {
            const response = await fetch(api + action, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'X-CSRF-Token': token, 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
                body,
            });
            const json = await response.json().catch(() => null);

            return json || { success: false, message: String(response.status) };
        } catch (error) {
            return { success: false, message: '' };
        }
    };

    /** Visueller Editor aktiv? Dann ist das Textfeld verborgen und wird aus dem Editor geschrieben. */
    const visual = () => textarea.hidden;

    const insertAtCursor = (snippet) => {
        if (visual()) {
            say(t('visual_hint'), false);
            return;
        }
        const start = textarea.selectionStart ?? textarea.value.length;
        const end = textarea.selectionEnd ?? start;
        const before = textarea.value.slice(0, start);
        const glue = before === '' || before.endsWith('\n') ? '' : '\n';
        textarea.value = `${before}${glue}${snippet}\n${textarea.value.slice(end)}`;
        const caret = before.length + glue.length + snippet.length + 1;
        textarea.setSelectionRange(caret, caret);
        textarea.dispatchEvent(new Event('input', { bubbles: true }));
        textarea.focus();
    };

    // ── Anhänge ─────────────────────────────────────────────────────────────────────────────────────────────
    const box = form.querySelector('[data-cc-attachments]');
    const list = box ? box.querySelector('[data-cc-attachment-list]') : null;
    const uploadToken = form.querySelector('input[name="upload_token"]');
    const uploadKey = root.getAttribute('data-cc-upload-key') || '';
    const uploadId = root.getAttribute('data-cc-upload-id') || '';

    const addItem = (id, name, markdown) => {
        const item = document.createElement('li');
        item.dataset.ccAttachment = String(id);
        item.dataset.markdown = markdown;
        const hidden = document.createElement('input');
        hidden.type = 'hidden';
        hidden.name = 'attachments[]';
        hidden.value = String(id);
        const label = document.createElement('span');
        label.textContent = name;
        const insert = document.createElement('button');
        insert.type = 'button';
        insert.dataset.ccAttachmentInsert = '';
        insert.textContent = t('insert');
        const discard = document.createElement('button');
        discard.type = 'button';
        discard.dataset.ccAttachmentDiscard = '';
        discard.textContent = t('discard');
        item.append(hidden, label, ' ', insert, ' ', discard);
        list.appendChild(item);
    };

    const upload = async (file) => {
        if (!uploadToken || !list || !uploadKey || uploadId === '0') {
            return;
        }
        const name = file.name || 'datei';
        say(t('uploading', { name }), false);
        const body = new FormData();
        body.append('file', file, name);
        body.append('token', uploadToken.value);
        body.append(uploadKey, uploadId);
        const result = await post('anhang', body);
        if (!result.success || !result.attachment) {
            say(t('upload_failed', { name, message: result.message || '' }), true);
            return;
        }
        addItem(result.attachment.id, result.attachment.name, result.markdown || '');
        say(t('uploaded', { name: result.attachment.name }), false);
        if (result.markdown) {
            insertAtCursor(result.markdown);
        }
    };

    const uploadAll = async (files) => {
        for (const file of Array.from(files || [])) {
            // Nacheinander: der Server zählt Grenzen je Mitglied und Formular.
            // eslint-disable-next-line no-await-in-loop
            await upload(file);
        }
    };

    if (box && list) {
        const input = box.querySelector('[data-cc-file-input]');
        if (input) {
            input.addEventListener('change', () => {
                uploadAll(input.files).finally(() => {
                    input.value = '';
                });
            });
        }
        list.addEventListener('click', async (event) => {
            const button = event.target instanceof Element ? event.target.closest('button') : null;
            const item = button ? button.closest('[data-cc-attachment]') : null;
            if (!item) {
                return;
            }
            if (button.hasAttribute('data-cc-attachment-insert')) {
                insertAtCursor(item.dataset.markdown || '');
                return;
            }
            if (button.hasAttribute('data-cc-attachment-discard')) {
                button.disabled = true;
                const body = new URLSearchParams({ id: item.dataset.ccAttachment });
                const result = await post('anhang-entfernen', body);
                if (!result.success) {
                    button.disabled = false;
                    say(result.message || '', true);
                    return;
                }
                const pattern = `(attachment:${item.dataset.ccAttachment})`;
                textarea.value = textarea.value.split('\n').filter((line) => !line.includes(pattern)).join('\n');
                item.remove();
                say('', false);
            }
        });
        // Ziehen und Ablegen sowie Einfügen auf dem ganzen Formular (Textfeld und visueller Editor).
        const hasFiles = (event) => !!event.dataTransfer && Array.from(event.dataTransfer.types || []).includes('Files');
        form.addEventListener('dragover', (event) => {
            if (hasFiles(event)) {
                event.preventDefault();
                box.classList.add('is-dragover');
            }
        });
        form.addEventListener('dragleave', () => box.classList.remove('is-dragover'));
        form.addEventListener('drop', (event) => {
            box.classList.remove('is-dragover');
            if (hasFiles(event) && event.dataTransfer.files.length > 0) {
                event.preventDefault();
                event.stopPropagation();
                uploadAll(event.dataTransfer.files);
            }
        }, true);
        form.addEventListener('paste', (event) => {
            const files = event.clipboardData ? Array.from(event.clipboardData.files || []) : [];
            if (files.length > 0) {
                event.preventDefault();
                event.stopPropagation();
                uploadAll(files);
            }
        }, true);
    }

    // ── Live-Vorschau ───────────────────────────────────────────────────────────────────────────────────────
    const live = form.querySelector('[data-cc-live-preview]');
    const previewButton = form.querySelector('[data-cc-action="preview"]');
    let previewTimer = 0;
    const schedulePreview = () => {
        if (!live || !live.checked || !previewButton) {
            return;
        }
        window.clearTimeout(previewTimer);
        previewTimer = window.setTimeout(() => {
            if (!previewButton.disabled) {
                previewButton.click();
            }
        }, 800);
    };
    if (live) {
        live.addEventListener('change', schedulePreview);
    }

    // ── Entwürfe ────────────────────────────────────────────────────────────────────────────────────────────
    const localKey = root.getAttribute('data-cc-local-draft') || '';
    const draftKey = root.getAttribute('data-cc-draft-key') || '';
    const offer = form.querySelector('[data-cc-draft-offer]');
    const draftStatus = form.querySelector('[data-cc-draft-status]');
    const initialBody = textarea.value;
    const initialTitle = titleInput ? titleInput.value : '';
    let candidate = null;
    let lastSaved = `${initialTitle}\u0000${initialBody}`;
    let serverTimer = 0;
    let localTimer = 0;

    const storage = (() => {
        try {
            const probe = '__cc';
            window.localStorage.setItem(probe, probe);
            window.localStorage.removeItem(probe);

            return window.localStorage;
        } catch (error) {
            return null;
        }
    })();

    const current = () => ({ title: titleInput ? titleInput.value : '', body: textarea.value });

    const showOffer = (draft, text) => {
        if (!offer || !draft || (draft.body === textarea.value && draft.title === (titleInput ? titleInput.value : ''))) {
            return;
        }
        candidate = draft;
        offer.querySelector('[data-cc-draft-text]').textContent = text;
        offer.hidden = false;
    };

    const saveLocal = () => {
        if (!storage || !localKey) {
            return;
        }
        const { title, body } = current();
        try {
            if (body.trim() === '' && title.trim() === '') {
                storage.removeItem(localKey);
            } else {
                storage.setItem(localKey, JSON.stringify({ title, body, at: Date.now() }));
            }
        } catch (error) {
            // Speicher voll oder gesperrt: der Entwurf bleibt nur im Formular.
        }
    };

    const saveServer = async () => {
        if (!draftKey || uploadId === '0') {
            return;
        }
        const { title, body } = current();
        const signature = `${title}\u0000${body}`;
        if (signature === lastSaved) {
            return;
        }
        const data = new URLSearchParams({ op: 'save', body, [draftKey]: uploadId });
        if (titleInput) {
            data.append('title', title);
        }
        const result = await post('entwurf', data);
        if (result.success) {
            lastSaved = signature;
        }
        if (draftStatus) {
            draftStatus.textContent = result.success ? (result.saved ? t('draft_saved') : '') : t('draft_failed');
        }
    };

    const onChange = () => {
        schedulePreview();
        window.clearTimeout(localTimer);
        localTimer = window.setTimeout(saveLocal, 500);
        window.clearTimeout(serverTimer);
        serverTimer = window.setTimeout(saveServer, 15000);
    };
    textarea.addEventListener('input', onChange);
    if (titleInput) {
        titleInput.addEventListener('input', onChange);
    }

    if (offer) {
        offer.querySelector('[data-cc-draft-restore]').addEventListener('click', () => {
            if (candidate) {
                if (titleInput && candidate.title) {
                    titleInput.value = candidate.title;
                }
                if (visual()) {
                    // Zurück in die Markdown-Ansicht, damit der Entwurf verlustfrei im Textfeld landet.
                    const tab = document.querySelector('.cms-community-editor__tabs [data-mode="markdown"]');
                    if (tab) {
                        tab.click();
                    }
                }
                textarea.value = candidate.body;
                textarea.dispatchEvent(new Event('input', { bubbles: true }));
            }
            offer.hidden = true;
        });
        offer.querySelector('[data-cc-draft-dismiss]').addEventListener('click', () => {
            offer.hidden = true;
            if (storage && localKey) {
                storage.removeItem(localKey);
            }
            if (draftKey && uploadId !== '0') {
                post('entwurf', new URLSearchParams({ op: 'delete', [draftKey]: uploadId }));
            }
        });
    }

    // Vorhandene Entwürfe anbieten: zuerst lokal (jüngster Stand dieses Browsers), sonst vom Server.
    // Lokale Entwürfe verfallen nach 30 Tagen (auch die anderer Formulare, Schlüssel `cc-draft:*`).
    const LOCAL_DRAFT_TTL = 30 * 24 * 60 * 60 * 1000;
    const expired = (raw) => {
        try {
            const parsed = JSON.parse(raw || 'null');

            return !parsed || typeof parsed.at !== 'number' || Date.now() - parsed.at > LOCAL_DRAFT_TTL;
        } catch (error) {
            return true;
        }
    };
    if (storage) {
        try {
            const stale = [];
            for (let i = 0; i < storage.length; i += 1) {
                const key = storage.key(i);
                if (key && key.startsWith('cc-draft:') && expired(storage.getItem(key))) {
                    stale.push(key);
                }
            }
            stale.forEach((key) => storage.removeItem(key));
        } catch (error) {
            // Speicher nicht lesbar: nichts aufräumen.
        }
    }
    const local = (() => {
        if (!storage || !localKey) {
            return null;
        }
        try {
            const parsed = JSON.parse(storage.getItem(localKey) || 'null');

            return parsed && typeof parsed.body === 'string' ? { title: String(parsed.title || ''), body: parsed.body } : null;
        } catch (error) {
            return null;
        }
    })();
    if (local) {
        showOffer(local, t('draft_local'));
    } else if (draftKey && uploadId !== '0') {
        post('entwurf', new URLSearchParams({ op: 'load', [draftKey]: uploadId })).then((result) => {
            if (result.success && result.draft) {
                showOffer({ title: String(result.draft.title || ''), body: String(result.draft.body || '') }, t('draft_server', { time: result.draft.updated_at || '' }));
            }
        });
    }

    form.addEventListener('submit', () => {
        window.clearTimeout(serverTimer);
        if (storage && localKey) {
            // Scheitert die Absendung, zeigt der Server das Formular mit der Eingabe erneut.
            storage.removeItem(localKey);
        }
    });
}
