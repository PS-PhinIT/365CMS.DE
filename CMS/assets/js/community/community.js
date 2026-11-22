/*
 * Community (4.2.0): Reaktionen, Abo, Melden, Moderation, Lösung und Vorschau.
 * ES-Modul ohne Build-Schritt; kein Inline-Skript. Die Seite trägt `data-cc-api` (Basisadresse der JSON-API) und `data-cc-token`
 * (dauerhaftes CSRF-Token). Alle Rechte entscheidet der Server; die Schaltflächen sind nur Komfort.
 */
const root = document.querySelector('[data-cc-api]');

if (root) {
    const api = root.getAttribute('data-cc-api') || '';
    const token = root.getAttribute('data-cc-token') || '';
    const status = document.getElementById('cc-status');
    const dialog = document.getElementById('cc-dialog');
    let messages = {};
    try {
        messages = JSON.parse(root.getAttribute('data-cc-messages') || '{}');
    } catch (error) {
        messages = {};
    }
    // Texte kommen vom Server (Sprachschlüssel community.js.*); ohne Text bleibt der Schlüssel sichtbar.
    const msg = (key) => messages[key] || key;

    const say = (text, isError) => {
        if (!status) {
            return;
        }
        status.textContent = text || '';
        status.setAttribute('role', isError ? 'alert' : 'status');
        status.hidden = !text;
    };

    const call = async (action, data) => {
        const body = new URLSearchParams();
        Object.entries(data).forEach(([key, value]) => {
            if (value !== undefined && value !== null) {
                body.append(key, String(value));
            }
        });
        body.append('csrf_token', token);
        let response;
        try {
            response = await fetch(api + action, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'X-CSRF-Token': token, 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
                body,
            });
        } catch (error) {
            return { success: false, message: msg('offline') };
        }
        let json = null;
        try {
            json = await response.json();
        } catch (error) {
            json = null;
        }
        if (response.status === 403) {
            return { success: false, message: msg('expired') };
        }

        return json || { success: false, message: msg('unexpected') };
    };

    /** Dialog mit Feldern (DOM-API, kein HTML aus Text); löst mit den Werten oder null (abgebrochen) auf. */
    const ask = (title, fields) => new Promise((resolve) => {
        if (!dialog || typeof dialog.showModal !== 'function') {
            resolve(null);
            return;
        }
        const form = dialog.querySelector('form');
        const holder = dialog.querySelector('[data-cc-fields]');
        dialog.querySelector('[data-cc-title]').textContent = title;
        holder.replaceChildren();
        fields.forEach((field) => {
            const label = document.createElement('label');
            label.textContent = field.label;
            let input;
            if (field.type === 'select') {
                input = document.createElement('select');
                Object.entries(field.options).forEach(([value, text]) => {
                    const option = document.createElement('option');
                    option.value = value;
                    option.textContent = text;
                    input.appendChild(option);
                });
            } else if (field.type === 'textarea') {
                input = document.createElement('textarea');
                input.rows = 3;
            } else {
                input = document.createElement('input');
                input.type = 'text';
            }
            input.name = field.name;
            input.required = !!field.required;
            input.className = 'cms-community-input';
            label.appendChild(input);
            holder.appendChild(label);
        });
        // Fokus zurück zum auslösenden Element (WCAG 2.4.3), auch wenn der Browser ihn beim Schließen nicht selbst zurückgibt
        const opener = document.activeElement;
        const onClose = () => {
            dialog.removeEventListener('close', onClose);
            if (opener && opener !== document.body && typeof opener.focus === 'function') {
                // Nach dem Schließen (der Browser setzt den Fokus beim Schließen selbst noch um)
                window.setTimeout(() => {
                    if (opener.isConnected) {
                        opener.focus();
                    }
                }, 0);
            }
            if (dialog.returnValue !== 'ok') {
                resolve(null);
                return;
            }
            const values = {};
            new FormData(form).forEach((value, key) => {
                values[key] = String(value);
            });
            resolve(values);
        };
        dialog.addEventListener('close', onClose);
        dialog.returnValue = '';
        dialog.showModal();
    });

    const needsReason = root.getAttribute('data-cc-reason-required') === '1';
    let reasons = {};
    try {
        reasons = JSON.parse(root.getAttribute('data-cc-reasons') || '{}');
    } catch (error) {
        reasons = {};
    }

    const handlers = {
        async react(button) {
            const active = button.getAttribute('aria-pressed') === 'true';
            const result = await call('reaktion', { post_id: button.dataset.postId, reaction: active ? '' : button.dataset.reaction });
            if (!result.success) {
                say(result.message, true);
                return;
            }
            const bar = button.closest('[data-cc-reactions]');
            bar.querySelectorAll('button[data-reaction]').forEach((other) => other.setAttribute('aria-pressed', 'false'));
            if (!active) {
                button.setAttribute('aria-pressed', 'true');
            }
            say('', false);
            window.location.reload();
        },
        async subscribe(button) {
            const active = button.getAttribute('aria-pressed') === 'true';
            const result = await call('abo', { type: button.dataset.type, id: button.dataset.id, mode: active ? 'none' : 'instant' });
            if (!result.success) {
                say(result.message, true);
                return;
            }
            button.setAttribute('aria-pressed', active ? 'false' : 'true');
            button.textContent = active ? button.dataset.labelOff : button.dataset.labelOn;
            say(active ? msg('unsubscribed') : msg('subscribed'), false);
        },
        async report(button) {
            const values = await ask(msg('report_title'), [
                { type: 'select', name: 'reason', label: msg('reason'), options: reasons, required: true },
                { type: 'textarea', name: 'message', label: msg('note') },
            ]);
            if (!values) {
                return;
            }
            const result = await call('melden', { type: button.dataset.type, id: button.dataset.id, reason: values.reason, message: values.message });
            say(result.success ? msg('reported') : result.message, !result.success);
        },
        async moderate(button) {
            const values = await ask(button.textContent.trim(), [
                { type: 'text', name: 'reason', label: needsReason ? msg('reason_required') : msg('reason'), required: needsReason },
            ]);
            if (!values) {
                return;
            }
            const result = await call('moderation', { op: button.dataset.op, id: button.dataset.id, reason: values.reason });
            if (!result.success) {
                say(result.message, true);
                return;
            }
            window.location.reload();
        },
        async solution(button) {
            const result = await call('loesung', { topic_id: button.dataset.topicId, post_id: button.dataset.postId || '' });
            if (!result.success) {
                say(result.message, true);
                return;
            }
            window.location.reload();
        },
        async 'mark-read'(button) {
            const result = await call('gelesen', { forum_id: button.dataset.forumId || '0' });
            say(result.success ? button.dataset.done : result.message, !result.success);
        },
        async preview(button) {
            const target = document.getElementById('cc-preview');
            const body = document.getElementById('cc-body');
            if (!target || !body) {
                return;
            }
            const data = { body: body.value };
            data[button.dataset.targetKey] = button.dataset.targetId;
            const result = await call('vorschau', data);
            if (!result.success) {
                say(result.message, true);
                return;
            }
            say('', false);
            // Server-seitig gereinigtes HTML; die Trusted-Types-Standardrichtlinie des CMS bereinigt zusätzlich (DOMPurify).
            target.innerHTML = result.html;
            target.hidden = false;
        },
    };

    root.addEventListener('click', (event) => {
        const button = event.target instanceof Element ? event.target.closest('[data-cc-action]') : null;
        if (!button || !root.contains(button)) {
            return;
        }
        const handler = handlers[button.getAttribute('data-cc-action')];
        if (handler) {
            event.preventDefault();
            button.disabled = true;
            Promise.resolve(handler(button)).finally(() => {
                button.disabled = false;
            });
        }
    });
}
