/**
 * 365CMS Admin – JSON-Client für die Admin-Endpunkte.
 *
 * - GET  ?action=…          lesende Aktionen (Cookies der Sitzung, same-origin)
 * - POST action=…           schreibende Aktionen mit Feld `csrf_token`
 * - Antwortet ein Endpunkt mit `code: "csrf"` (Token abgelaufen), wird einmalig
 *   über `?action=token` ein frisches Token geholt und die Anfrage wiederholt.
 *
 * Alle Methoden werfen nicht, sondern liefern immer ein Objekt mit `success`.
 */

const NETWORK_ERROR = 'Keine Verbindung zum Server. Bitte später erneut versuchen.';

function toFormData(data) {
    if (data instanceof FormData) {
        return data;
    }

    const formData = new FormData();
    Object.entries(data || {}).forEach(([key, value]) => {
        if (value === undefined || value === null) {
            return;
        }
        if (Array.isArray(value)) {
            value.forEach((item) => formData.append(key + '[]', String(item)));
            return;
        }
        formData.append(key, typeof value === 'boolean' ? (value ? '1' : '0') : String(value));
    });

    return formData;
}

async function parseResponse(response) {
    let payload = null;
    try {
        payload = await response.json();
    } catch {
        payload = null;
    }

    if (!payload || typeof payload !== 'object' || Array.isArray(payload)) {
        return {
            success: false,
            status: response.status,
            error: 'Unerwartete Antwort vom Server (HTTP ' + response.status + ').',
        };
    }

    payload.success = payload.success === true && response.ok;
    payload.status = response.status;
    if (!payload.success && typeof payload.error !== 'string') {
        payload.error = 'Die Anfrage ist fehlgeschlagen (HTTP ' + response.status + ').';
    }

    return payload;
}

function failure(error) {
    if (error && error.name === 'AbortError') {
        return { success: false, aborted: true, error: 'Anfrage abgebrochen.' };
    }

    return { success: false, status: 0, error: NETWORK_ERROR };
}

/**
 * @param {{endpoint: string, token?: string}} options
 */
export function createJsonClient({ endpoint, token = '' }) {
    let csrfToken = token;
    let tokenRequest = null;

    async function get(action, params = {}, { signal } = {}) {
        const url = new URL(endpoint, window.location.href);
        url.searchParams.set('action', action);
        Object.entries(params || {}).forEach(([key, value]) => {
            if (value !== undefined && value !== null && value !== '') {
                url.searchParams.set(key, String(value));
            }
        });

        try {
            const response = await fetch(url.toString(), {
                method: 'GET',
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
                signal,
            });
            return await parseResponse(response);
        } catch (error) {
            return failure(error);
        }
    }

    function refreshToken() {
        if (!tokenRequest) {
            tokenRequest = get('token').then((payload) => {
                tokenRequest = null;
                if (payload.success && typeof payload.token === 'string' && payload.token !== '') {
                    csrfToken = payload.token;
                    return true;
                }
                return false;
            });
        }

        return tokenRequest;
    }

    async function post(action, data = {}, { signal, keepalive = false, retry = true } = {}) {
        const body = toFormData(data);
        body.set('action', action);
        body.set('csrf_token', csrfToken);

        let payload;
        try {
            const response = await fetch(endpoint, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
                body,
                signal,
                keepalive,
            });
            payload = await parseResponse(response);
        } catch (error) {
            return failure(error);
        }

        if (!payload.success && payload.code === 'csrf' && retry && await refreshToken()) {
            return post(action, body, { signal, keepalive, retry: false });
        }

        return payload;
    }

    return {
        get,
        post,
        refreshToken,
        get token() {
            return csrfToken;
        },
    };
}
