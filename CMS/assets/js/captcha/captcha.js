/**
 * Captcha-Widget (Core): löst den Proof-of-Work des Verfahrens „pow“ im Browser (Web Crypto, SHA-256) und trägt die gefundene Zahl
 * in das versteckte Feld ein. Solange das Skript arbeitet, ist die Rechenaufgabe (Alternative) ausgeblendet; geht es nicht (kein Web
 * Crypto, etwa ohne sicheren Kontext, oder ein Fehler), wird sie sichtbar, und ein Absenden, das noch läuft, wartet auf das Ergebnis.
 * Ohne JavaScript bleibt die Rechenaufgabe sichtbar. Texte kommen aus `data-text-*` des Elements; kein innerHTML (Trusted Types), keine
 * Inline-Handler (CSP), keine Anfragen: der Server hat die Aufgabe schon im HTML mitgeliefert.
 */

const BATCH = 400;

function toHex(buffer) {
    return Array.from(new Uint8Array(buffer), (byte) => byte.toString(16).padStart(2, '0')).join('');
}

/** Sucht n in 0..max mit SHA-256(salt + n) = challenge; wirft, wenn es nicht geht. */
export async function solve(salt, challenge, max, subtle = globalThis.crypto && globalThis.crypto.subtle) {
    if (!subtle || typeof subtle.digest !== 'function' || !salt || !challenge || !(max > 0)) {
        throw new Error('unsupported');
    }
    const encoder = new TextEncoder();
    for (let start = 0; start <= max; start += BATCH) {
        const end = Math.min(max, start + BATCH - 1);
        const jobs = [];
        for (let n = start; n <= end; n += 1) {
            jobs.push(subtle.digest('SHA-256', encoder.encode(salt + n)).then((buffer) => ({ n, hex: toHex(buffer) })));
        }
        const results = await Promise.all(jobs);
        for (const result of results) {
            if (result.hex === challenge) {
                return result.n;
            }
        }
        // dem Browser Luft lassen (Eingaben, Anzeige)
        await new Promise((resolve) => globalThis.setTimeout(resolve, 0));
    }
    throw new Error('not found');
}

function setStatus(element, text) {
    const status = element.querySelector('[data-cms-captcha-status]');
    if (status) {
        status.textContent = text;
    }
}

async function start(element) {
    if (element.dataset.cmsCaptchaStarted === '1') {
        return;
    }
    element.dataset.cmsCaptchaStarted = '1';
    const payload = element.querySelector('[data-cms-captcha-payload]');
    const fallback = element.querySelector('[data-cms-captcha-fallback]');
    const form = element.closest('form');
    if (!payload) {
        return;
    }
    const subtle = globalThis.crypto && globalThis.crypto.subtle;
    if (!subtle) {
        setStatus(element, element.dataset.textFailed || '');
        return;
    }

    let state = 'working';
    let pending = null;
    if (fallback) {
        fallback.hidden = true;
    }
    setStatus(element, element.dataset.textWorking || '');
    if (form) {
        form.addEventListener('submit', (event) => {
            if (state === 'working') {
                event.preventDefault();
                pending = event.submitter || true;
            }
        });
    }

    try {
        const number = await solve(element.dataset.salt, element.dataset.challenge, parseInt(element.dataset.max || '0', 10), subtle);
        payload.value = String(number);
        state = 'done';
        setStatus(element, element.dataset.textDone || '');
    } catch (error) {
        state = 'failed';
        if (fallback) {
            fallback.hidden = false;
        }
        setStatus(element, element.dataset.textFailed || '');
    }
    if (pending && form) {
        if (typeof form.requestSubmit === 'function') {
            form.requestSubmit(pending === true ? undefined : pending);
        } else {
            form.submit();
        }
    }
}

export function init(root = document) {
    root.querySelectorAll('[data-cms-captcha="pow"]').forEach((element) => {
        start(element);
    });
}

if (typeof document !== 'undefined') {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => init());
    } else {
        init();
    }
}
