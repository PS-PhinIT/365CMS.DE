/**
 * Passkey-Registrierung im Mitgliederbereich. Fehler erscheinen als Inline-Meldung (role="alert")
 * statt per Browser-Dialog (UI-12). Texte kommen aus data-msg-*-Attributen des Formulars.
 */

function fromBase64Url(value) {
    if (!value) {
        return new Uint8Array();
    }
    const normalized = String(value).replace(/-/g, '+').replace(/_/g, '/');
    const padded = normalized + '==='.slice((normalized.length + 3) % 4);
    const binary = atob(padded);
    const bytes = new Uint8Array(binary.length);
    for (let index = 0; index < binary.length; index += 1) {
        bytes[index] = binary.charCodeAt(index);
    }
    return bytes;
}

function toBase64Url(bufferSource) {
    if (!bufferSource) {
        return '';
    }
    const view = bufferSource instanceof Uint8Array ? bufferSource : new Uint8Array(bufferSource);
    let binary = '';
    view.forEach((byte) => {
        binary += String.fromCharCode(byte);
    });
    return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/g, '');
}

function normalizePublicKeyOptions(options) {
    if (!options || typeof options !== 'object') {
        return options;
    }

    const publicKey = options.publicKey && typeof options.publicKey === 'object' ? options.publicKey : options;

    if (publicKey.challenge) {
        publicKey.challenge = fromBase64Url(publicKey.challenge);
    }
    if (publicKey.user && publicKey.user.id) {
        publicKey.user.id = fromBase64Url(publicKey.user.id);
    }
    if (Array.isArray(publicKey.excludeCredentials)) {
        publicKey.excludeCredentials = publicKey.excludeCredentials.map((credential) => {
            if (credential.id) {
                credential.id = fromBase64Url(credential.id);
            }
            return credential;
        });
    }

    return publicKey;
}

function submitForm(form) {
    const submitter = document.createElement('button');
    submitter.type = 'submit';
    submitter.hidden = true;
    submitter.tabIndex = -1;
    form.appendChild(submitter);
    if (typeof form.requestSubmit === 'function') {
        form.requestSubmit(submitter);
    } else {
        submitter.click();
    }
    form.removeChild(submitter);
}

export function init(root = document) {
    const form = root.querySelector('[data-passkey-form]');
    const trigger = form ? form.querySelector('[data-passkey-register]') : null;
    const errorBox = form ? form.querySelector('[data-passkey-error]') : null;
    if (!form || !trigger) {
        return;
    }

    const showError = (message) => {
        if (!errorBox) {
            return;
        }
        errorBox.textContent = message;
        errorBox.hidden = false;
    };
    const clearError = () => {
        if (errorBox) {
            errorBox.textContent = '';
            errorBox.hidden = true;
        }
    };

    if (!window.PublicKeyCredential || !navigator.credentials || typeof navigator.credentials.create !== 'function') {
        trigger.disabled = true;
        showError(form.getAttribute('data-msg-unsupported') || '');
        return;
    }

    trigger.addEventListener('click', async () => {
        clearError();

        const password = form.querySelector('input[name="current_password"]');
        if (password && typeof password.reportValidity === 'function' && !password.reportValidity()) {
            return;
        }

        let options;
        try {
            options = JSON.parse(form.getAttribute('data-passkey-options') || '{}');
        } catch (error) {
            showError(form.getAttribute('data-msg-options') || '');
            return;
        }

        trigger.disabled = true;
        try {
            const credential = await navigator.credentials.create({ publicKey: normalizePublicKeyOptions(options) });
            if (!credential || !credential.response || !credential.response.clientDataJSON || !credential.response.attestationObject) {
                showError(form.getAttribute('data-msg-create') || '');
                return;
            }

            form.querySelector('input[name="client_data_json"]').value = toBase64Url(credential.response.clientDataJSON);
            form.querySelector('input[name="attestation_object"]').value = toBase64Url(credential.response.attestationObject);
            submitForm(form);
        } catch (error) {
            const cancelled = error && (error.name === 'NotAllowedError' || error.name === 'AbortError');
            showError(cancelled || !error || !error.message
                ? (form.getAttribute('data-msg-cancelled') || '')
                : error.message);
        } finally {
            trigger.disabled = false;
        }
    });
}
