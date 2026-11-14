/**
 * Datei-Upload im Mitgliederbereich (/member/media) über /api/upload.
 * Texte kommen aus `i18n` in #member-media-config.
 */

function readTexts(root) {
    const element = root.getElementById ? root.getElementById('member-media-config') : null;
    try {
        const config = JSON.parse((element && element.textContent) || '{}');
        return config.i18n || {};
    } catch (error) {
        return {};
    }
}

function format(template, values) {
    return String(template || '').replace(/\{(\w+)\}/g, (match, key) => (key in values ? String(values[key]) : match));
}

function clear(element) {
    while (element && element.firstChild) {
        element.removeChild(element.firstChild);
    }
}

async function uploadFile(endpoint, token, path, file, failedText) {
    const formData = new FormData();
    formData.append('file', file);
    formData.append('target_path', path);
    formData.append('csrf_token', token);
    formData.append('member_upload', '1');

    const response = await fetch(endpoint, {
        method: 'POST',
        headers: { 'X-CSRF-Token': token },
        body: formData,
        credentials: 'same-origin',
    });
    const payload = await response.json().catch(() => ({}));

    if (!response.ok || payload.success === false) {
        const error = new Error(payload.error || failedText);
        error.payload = payload;
        throw error;
    }

    return payload;
}

function parentPath(path) {
    const segments = String(path || '').replace(/\\/g, '/').replace(/^\/+|\/+$/g, '').split('/');
    segments.pop();
    return segments.join('/');
}

function goToPath(path) {
    const params = new URLSearchParams(window.location.search);
    const safe = String(path || '').replace(/\\/g, '/').split('/')
        .filter((segment) => segment && segment !== '.' && segment !== '..')
        .join('/');
    if (safe) {
        params.set('path', safe);
    } else {
        params.delete('path');
    }
    const query = params.toString();
    window.location.search = query ? '?' + query : '';
}

function resultItem(kind, fileName, text) {
    const item = document.createElement('div');
    item.className = 'alert alert-' + kind + ' py-2 mb-0';
    item.setAttribute('role', kind === 'danger' ? 'alert' : 'status');
    const strong = document.createElement('strong');
    strong.textContent = fileName;
    item.appendChild(strong);
    item.appendChild(document.createTextNode(text));
    return item;
}

export function init(root = document) {
    const texts = readTexts(root);

    root.querySelectorAll('[data-member-upload-form]').forEach((form) => {
        const endpoint = form.getAttribute('data-upload-endpoint');
        const path = form.getAttribute('data-upload-path');
        const input = form.querySelector('input[type="file"]');
        const status = form.querySelector('[data-member-upload-status]');
        const results = form.querySelector('[data-member-upload-results]');
        let token = form.getAttribute('data-upload-token') || '';

        if (!endpoint || !token || !path || !input || !status || !results) {
            return;
        }

        form.addEventListener('submit', async (event) => {
            event.preventDefault();

            const files = Array.from(input.files || []);
            if (files.length === 0) {
                status.hidden = false;
                status.textContent = texts.selectFiles || '';
                results.hidden = true;
                clear(results);
                input.focus();
                return;
            }

            const submitButton = form.querySelector('button[type="submit"]');
            if (submitButton) {
                submitButton.disabled = true;
            }

            status.hidden = false;
            status.textContent = texts.uploading || '';
            results.hidden = false;
            clear(results);

            let hadError = false;
            let targetPath = path;

            for (let index = 0; index < files.length; index += 1) {
                const file = files[index];
                status.textContent = format(texts.progress, { current: index + 1, total: files.length, name: file.name });

                try {
                    const payload = await uploadFile(endpoint, token, path, file, texts.failed || '');
                    results.appendChild(resultItem('success', file.name, ' ' + (texts.success || '')));
                    if (payload.new_token) {
                        token = payload.new_token;
                        form.setAttribute('data-upload-token', token);
                    }
                    if (payload.path) {
                        targetPath = parentPath(payload.path);
                    }
                } catch (error) {
                    if (error && error.payload && error.payload.new_token) {
                        token = error.payload.new_token;
                        form.setAttribute('data-upload-token', token);
                    }
                    hadError = true;
                    results.appendChild(resultItem('danger', file.name, ': ' + ((error && error.message) || texts.failed || '')));
                }
            }

            status.textContent = hadError ? (texts.doneWithErrors || '') : (texts.done || '');
            if (submitButton) {
                submitButton.disabled = false;
            }
            if (!hadError) {
                window.setTimeout(() => goToPath(targetPath), 900);
            }
        });
    });
}
