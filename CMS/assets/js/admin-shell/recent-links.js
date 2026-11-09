/**
 * 365CMS Admin – „Zuletzt genutzt“-Ziele für das Dashboard.
 *
 * Speichert die aktuelle Admin-Seite (max. 8 Einträge) im localStorage
 * (Schlüssel cms365-admin-recent-links, Format {url, label, ts}).
 * Ausgelesen von admin-dashboard.js.
 */

const STORAGE_KEY = 'cms365-admin-recent-links';
const STORAGE_LIMIT = 8;
const VOLATILE_PARAMS = ['csrf_token', 'token', 'success', 'error', 'message', 'flash'];

function isValidAdminUrl(url) {
    return typeof url === 'string'
        && /^\/admin(?:\/|$)/.test(url)
        && /[\x00-\x1F\x7F]/.test(url) === false;
}

function normalizeEntry(entry) {
    if (!entry || typeof entry !== 'object') {
        return null;
    }

    const url = typeof entry.url === 'string' ? entry.url.trim() : '';
    const label = typeof entry.label === 'string' ? entry.label.trim() : '';
    if (!isValidAdminUrl(url) || label === '') {
        return null;
    }

    const normalized = { url: url.slice(0, 512), label: label.slice(0, 160) };
    if (typeof entry.ts === 'string') {
        const timestamp = new Date(entry.ts);
        if (!Number.isNaN(timestamp.getTime())) {
            normalized.ts = timestamp.toISOString();
        }
    }

    return normalized;
}

function sanitize(entries) {
    const seen = new Set();
    const result = [];
    (Array.isArray(entries) ? entries : []).forEach((entry) => {
        const normalized = normalizeEntry(entry);
        if (normalized && !seen.has(normalized.url)) {
            seen.add(normalized.url);
            result.push(normalized);
        }
    });

    return result.slice(0, STORAGE_LIMIT);
}

function currentLabel() {
    const nav = document.querySelector('[data-cms-nav]');
    const fromNav = nav?.dataset.recentLabel?.trim() || '';
    if (fromNav !== '') {
        return fromNav;
    }

    return (document.title || 'Dashboard').replace(/\s*[·|–-]\s*365CMS.*$/i, '').trim();
}

export function recordRecentLink() {
    let storage;
    try {
        storage = window.localStorage;
    } catch {
        return;
    }
    if (!storage) {
        return;
    }

    const current = new URL(window.location.href);
    if (!/^\/admin(?:\/|$)/.test(current.pathname)) {
        return;
    }

    VOLATILE_PARAMS.forEach((key) => current.searchParams.delete(key));
    const query = current.searchParams.toString();
    const url = current.pathname + (query !== '' ? '?' + query : '');
    const label = currentLabel();
    if (!isValidAdminUrl(url) || label === '') {
        return;
    }

    let entries = [];
    try {
        entries = JSON.parse(storage.getItem(STORAGE_KEY) || '[]');
    } catch {
        entries = [];
    }

    const next = sanitize(entries).filter((entry) => entry.url !== url);
    next.unshift({ url: url.slice(0, 512), label: label.slice(0, 160), ts: new Date().toISOString() });

    try {
        storage.setItem(STORAGE_KEY, JSON.stringify(sanitize(next)));
    } catch {
        // Speicher voll oder gesperrt – Verlauf ist optional.
    }
}
