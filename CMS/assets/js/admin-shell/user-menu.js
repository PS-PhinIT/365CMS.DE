/**
 * 365CMS Admin – Benutzerbereich im Fuß der Seitenleiste.
 *
 * - Avatar-Button öffnet ein Popover (Profil, Website, Abmelden).
 * - Für Administratoren ([data-cms-notices]) werden Systemhinweise aus
 *   GET admin-ui?action=notices geladen (serverseitig 5 Minuten gecacht):
 *   Punkt am Avatar nur bei mindestens einem Hinweis, Liste im Popover.
 */

import { createDisclosure } from 'cms-admin/dom.js';

const SEVERITY_RANK = { danger: 3, warning: 2, info: 1 };
const SEVERITY_LABEL = { danger: 'Fehler', warning: 'Warnung', info: 'Hinweis' };
const REFRESH_AFTER_MS = 5 * 60 * 1000;

function safeUrl(value) {
    if (typeof value !== 'string' || value.trim() === '') {
        return '';
    }

    try {
        const url = new URL(value, window.location.href);
        if (url.origin !== window.location.origin || !/^https?:$/.test(url.protocol)) {
            return '';
        }
        return url.href;
    } catch {
        return '';
    }
}

function createElement(tag, className, text = '') {
    const element = document.createElement(tag);
    if (className) {
        element.className = className;
    }
    if (text !== '') {
        element.textContent = text;
    }
    return element;
}

function renderNotice(notice) {
    const severity = SEVERITY_RANK[notice.severity] ? notice.severity : 'info';
    const item = createElement('li', 'cms-notice cms-notice--' + severity);

    const marker = createElement('span', 'cms-notice__marker');
    marker.setAttribute('aria-hidden', 'true');

    const body = createElement('div', 'cms-notice__body');
    const title = createElement('p', 'cms-notice__title');
    title.append(
        createElement('span', 'visually-hidden', SEVERITY_LABEL[severity] + ': '),
        document.createTextNode(String(notice.title || ''))
    );
    body.append(title);

    const text = String(notice.text || notice.message || '').trim();
    if (text !== '') {
        body.append(createElement('p', 'cms-notice__message', text));
    }

    const href = safeUrl(notice.url);
    if (href !== '') {
        const link = createElement('a', 'cms-notice__link', String(notice.link_label || 'Öffnen'));
        link.href = href;
        body.append(link);
    }

    item.append(marker, body);
    return item;
}

export function initUserMenu(sidebar, client) {
    const toggle = sidebar.querySelector('#cms-user-toggle');
    const popover = sidebar.querySelector('#cms-user-popover');
    if (!toggle || !popover) {
        return;
    }

    const noticesEnabled = popover.hasAttribute('data-cms-notices');
    const dot = toggle.querySelector('[data-cms-notice-dot]');
    const srText = toggle.querySelector('[data-cms-notice-sr]');
    const status = popover.querySelector('[data-cms-notices-status]');
    const list = popover.querySelector('[data-cms-notices-list]');
    let loadedAt = 0;
    let loading = null;

    function applyNotices(notices) {
        const count = notices.length;
        const topSeverity = notices.reduce((best, notice) => (
            (SEVERITY_RANK[notice.severity] || 0) > (SEVERITY_RANK[best] || 0) ? notice.severity : best
        ), 'info');

        if (dot) {
            dot.hidden = count === 0;
            dot.dataset.severity = topSeverity;
        }
        if (srText) {
            srText.textContent = count === 0 ? '' : (count === 1 ? ', 1 Systemhinweis' : ', ' + count + ' Systemhinweise');
        }
        if (list) {
            list.replaceChildren(...notices.map(renderNotice));
            list.hidden = count === 0;
        }
        if (status) {
            status.textContent = count === 0 ? 'Alles in Ordnung – keine offenen Systemhinweise.' : '';
            status.hidden = count !== 0;
        }
    }

    function load() {
        if (!noticesEnabled || loading) {
            return loading;
        }

        loading = client.get('notices').then((result) => {
            loading = null;
            if (!result.success || !Array.isArray(result.notices)) {
                if (status) {
                    status.hidden = false;
                    status.textContent = 'Systemhinweise konnten nicht geladen werden.';
                }
                return;
            }

            loadedAt = Date.now();
            applyNotices(result.notices.filter((notice) => notice && typeof notice === 'object'));
        });

        return loading;
    }

    createDisclosure(toggle, popover, {
        onOpen() {
            if (noticesEnabled && Date.now() - loadedAt > REFRESH_AFTER_MS) {
                load();
            }
        },
    });

    if (!noticesEnabled) {
        return;
    }

    if (typeof window.requestIdleCallback === 'function') {
        window.requestIdleCallback(() => load(), { timeout: 2500 });
    } else {
        window.setTimeout(load, 400);
    }
}
