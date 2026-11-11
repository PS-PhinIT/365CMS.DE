/**
 * Redaktionskalender (Admin) – Monats- und Wochenansicht ohne Kalender-Bibliothek.
 *
 * Konfiguration: <script type="application/json" id="cmsEditorialCalendarConfig"> (EditorialCalendarModule).
 * Termine: GET  {apiUrl}?action=events&start&end&author&type&category&lang&published
 * Verschieben: POST {apiUrl} action=move, csrf_token, type, id, date[, publish_now=1]
 *   409 + needs_confirmation=publish_now → Rückfrage „Jetzt veröffentlichen?“, danach erneut mit publish_now=1.
 *   403 + code=csrf → einmal neues Token holen (action=token) und wiederholen.
 *
 * Bedienung: Drag & Drop auf einen Tag (mit Bestätigung über cmsConfirm) oder – per Tastatur – über die
 * Schaltfläche „Verschieben“ am Eintrag (Popover mit Datumsauswahl). Ein Klick auf den Titel öffnet den Editor.
 * Alle Daten sind Website-Daten (Y-m-d); gerechnet wird in UTC, damit Sommerzeit keine Tage verschiebt.
 */
(function () {
    'use strict';

    const root = document.getElementById('cmsEditorialCalendar');
    const configElement = document.getElementById('cmsEditorialCalendarConfig');
    if (!root || !configElement) {
        return;
    }

    let config;
    try {
        config = JSON.parse(configElement.textContent || '{}');
    } catch (error) {
        return;
    }

    const t = config.i18n || {};
    const DAY_MS = 86400000;
    const grid = document.getElementById('cmsEcalGrid');
    const periodLabel = document.getElementById('cmsEcalPeriod');
    const liveRegion = document.getElementById('cmsEcalLive');
    const notice = root.querySelector('[data-ecal-notice]');
    const filterForm = document.getElementById('cmsEcalFilters');
    const popover = document.getElementById('cmsEcalPopover');
    const popoverForm = document.getElementById('cmsEcalPopoverForm');
    const popoverDate = document.getElementById('cmsEcalPopoverDate');
    const popoverItem = document.getElementById('cmsEcalPopoverItem');
    if (popover && popover.parentElement !== document.body) {
        // Absolut zum Dokument positionieren, unabhängig von positionierten Vorfahren.
        document.body.appendChild(popover);
    }

    const state = {
        view: config.view === 'week' ? 'week' : 'month',
        anchor: parseYmd(config.anchor) || parseYmd(config.today) || todayUtc(),
        today: String(config.today || ''),
        filters: normalizeFilters(config.filters || {}),
        events: [],
        byUid: {},
        token: String(config.csrfToken || ''),
        requestSeq: 0,
        dragUid: '',
        popoverEvent: null,
        popoverTrigger: null
    };

    // ── Datum ──────────────────────────────────────────────────────────

    function todayUtc() {
        const now = new Date();
        return new Date(Date.UTC(now.getFullYear(), now.getMonth(), now.getDate()));
    }

    function parseYmd(value) {
        const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(value || ''));
        if (!match) {
            return null;
        }
        const year = Number(match[1]);
        const month = Number(match[2]) - 1;
        const day = Number(match[3]);
        const date = new Date(Date.UTC(year, month, day));

        return date.getUTCFullYear() === year && date.getUTCMonth() === month && date.getUTCDate() === day ? date : null;
    }

    function ymd(date) {
        return date.toISOString().slice(0, 10);
    }

    function addDays(date, days) {
        return new Date(date.getTime() + days * DAY_MS);
    }

    function weekdayIndex(date) {
        return (date.getUTCDay() + 6) % 7; // Montag = 0
    }

    function startOfWeek(date) {
        return addDays(date, -weekdayIndex(date));
    }

    function startOfMonth(date) {
        return new Date(Date.UTC(date.getUTCFullYear(), date.getUTCMonth(), 1));
    }

    function addMonths(date, months) {
        return new Date(Date.UTC(date.getUTCFullYear(), date.getUTCMonth() + months, 1));
    }

    function isoWeek(date) {
        const thursday = addDays(date, 3 - weekdayIndex(date));
        const yearStart = new Date(Date.UTC(thursday.getUTCFullYear(), 0, 1));

        return Math.ceil(((thursday.getTime() - yearStart.getTime()) / DAY_MS + 1) / 7);
    }

    function formatter(options) {
        const merged = Object.assign({ timeZone: 'UTC' }, options);
        try {
            return new Intl.DateTimeFormat(config.uiLocale || 'de', merged);
        } catch (error) {
            return new Intl.DateTimeFormat('de', merged);
        }
    }

    const formatMonthTitle = formatter({ month: 'long', year: 'numeric' });
    const formatWeekdayShort = formatter({ weekday: 'short' });
    const formatDayLong = formatter({ weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
    const formatDayHead = formatter({ weekday: 'short', day: 'numeric', month: 'short' });
    const formatDate = formatter({ day: '2-digit', month: '2-digit', year: 'numeric' });

    function formatYmd(value) {
        const date = parseYmd(value);
        return date ? formatDate.format(date) : String(value || '');
    }

    function sprintf(template) {
        const args = Array.prototype.slice.call(arguments, 1);
        let index = 0;
        return String(template || '').replace(/%(?:(\d+)\$)?[sd]/g, function (match, position) {
            const value = position ? args[Number(position) - 1] : args[index++];
            return value === undefined || value === null ? '' : String(value);
        });
    }

    // ── Zeitraum, URL, Filter ──────────────────────────────────────────

    function currentRange() {
        if (state.view === 'week') {
            const start = startOfWeek(state.anchor);
            return { start: start, end: addDays(start, 6), days: 7 };
        }
        const start = startOfWeek(startOfMonth(state.anchor));
        return { start: start, end: addDays(start, 41), days: 42 };
    }

    function normalizeFilters(source) {
        return {
            author: Math.max(0, parseInt(source.author, 10) || 0),
            type: source.type === 'post' || source.type === 'page' ? source.type : '',
            category: Math.max(0, parseInt(source.category, 10) || 0),
            lang: typeof source.lang === 'string' ? source.lang : '',
            published: source.published === true || source.published === '1' || source.published === 1
        };
    }

    function readFilterForm() {
        if (!filterForm) {
            return;
        }
        const field = function (name) {
            const element = filterForm.elements.namedItem(name);
            return element && 'value' in element ? element.value : '';
        };
        const published = filterForm.elements.namedItem('published');
        state.filters = normalizeFilters({
            author: field('author'),
            type: field('type'),
            category: field('category'),
            lang: field('lang'),
            published: published && published.checked ? '1' : ''
        });
    }

    function filterParams(params) {
        if (state.filters.author > 0) {
            params.set('author', String(state.filters.author));
        }
        if (state.filters.type !== '') {
            params.set('type', state.filters.type);
        }
        if (state.filters.category > 0) {
            params.set('category', String(state.filters.category));
        }
        if (state.filters.lang !== '') {
            params.set('lang', state.filters.lang);
        }
        if (state.filters.published) {
            params.set('published', '1');
        }

        return params;
    }

    function syncUrl() {
        const params = filterParams(new URLSearchParams());
        params.set('view', state.view);
        params.set('date', ymd(state.anchor));
        if (window.history && typeof window.history.replaceState === 'function') {
            window.history.replaceState(null, '', (config.pageUrl || window.location.pathname) + '?' + params.toString());
        }
        if (filterForm) {
            const viewInput = filterForm.elements.namedItem('view');
            const dateInput = filterForm.elements.namedItem('date');
            if (viewInput) {
                viewInput.value = state.view;
            }
            if (dateInput) {
                dateInput.value = ymd(state.anchor);
            }
        }
    }

    // ── Netzwerk ───────────────────────────────────────────────────────

    function requestJson(url, options) {
        return fetch(url, Object.assign({ credentials: 'same-origin', headers: { Accept: 'application/json' } }, options || {}))
            .then(function (response) {
                return response.json().catch(function () {
                    return {};
                }).then(function (data) {
                    return { status: response.status, data: data && typeof data === 'object' ? data : {} };
                });
            });
    }

    function refreshToken() {
        return requestJson(config.apiUrl + '?action=token').then(function (result) {
            if (result.status === 200 && result.data.success && typeof result.data.token === 'string') {
                state.token = result.data.token;
                return true;
            }
            return false;
        }).catch(function () {
            return false;
        });
    }

    function loadEvents() {
        const range = currentRange();
        const seq = ++state.requestSeq;
        const params = filterParams(new URLSearchParams({ action: 'events', start: ymd(range.start), end: ymd(range.end) }));

        grid.setAttribute('aria-busy', 'true');
        setNotice('', '');

        requestJson(config.apiUrl + '?' + params.toString()).then(function (result) {
            if (seq !== state.requestSeq) {
                return;
            }
            if (result.status !== 200 || !result.data.success) {
                throw new Error(result.data.error || t.loadError || '');
            }
            if (typeof result.data.today === 'string' && parseYmd(result.data.today)) {
                state.today = result.data.today;
            }
            setEvents(Array.isArray(result.data.events) ? result.data.events : []);
            render();
            if (result.data.truncated) {
                setNotice('warning', t.truncated);
            }
            announce(state.events.length > 0 ? sprintf(t.eventsCount, state.events.length) : t.noEvents);
        }).catch(function (error) {
            if (seq !== state.requestSeq) {
                return;
            }
            setEvents([]);
            render();
            setNotice('danger', (error && error.message) || t.loadError);
        }).finally(function () {
            if (seq === state.requestSeq) {
                grid.setAttribute('aria-busy', 'false');
            }
        });
    }

    function postMove(event, date, publishNow, retried) {
        const body = new URLSearchParams({
            action: 'move',
            csrf_token: state.token,
            type: event.type,
            id: String(event.id),
            date: date
        });
        if (publishNow) {
            body.set('publish_now', '1');
        }

        requestJson(config.apiUrl, {
            method: 'POST',
            headers: { Accept: 'application/json', 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
            body: body.toString()
        }).then(function (result) {
            const data = result.data;
            if (result.status === 403 && data.code === 'csrf' && !retried) {
                refreshToken().then(function (ok) {
                    if (ok) {
                        postMove(event, date, publishNow, true);
                    } else {
                        toast('danger', t.csrfExpired);
                    }
                });
                return;
            }
            if (result.status === 409 && data.needs_confirmation === 'publish_now') {
                confirmAction({
                    title: t.publishNowTitle,
                    message: data.error || '',
                    confirmText: t.publishNowButton,
                    confirmClass: 'btn-success',
                    statusClass: 'bg-warning',
                    onConfirm: function () {
                        postMove(event, date, true, false);
                    }
                });
                return;
            }
            if (!data.success) {
                let message = data.error || t.moveFailed;
                if (data.code === 'checklist' && Array.isArray(data.details) && data.details.length > 0) {
                    message += ' ' + sprintf(t.checklistMissing, data.details.join(' '));
                }
                toast('danger', message);
                return;
            }
            toast('success', data.message || '');
            announce(data.message || '');
            loadEvents();
        }).catch(function () {
            toast('danger', t.moveFailed);
        });
    }

    // ── Rückmeldungen ──────────────────────────────────────────────────

    function toast(type, message) {
        if (message === '') {
            return;
        }
        if (typeof window.cmsToast === 'function') {
            window.cmsToast(type, message);
        } else {
            setNotice(type, message);
        }
    }

    function announce(message) {
        if (!liveRegion) {
            return;
        }
        liveRegion.textContent = '';
        window.setTimeout(function () {
            liveRegion.textContent = message || '';
        }, 50);
    }

    function setNotice(type, message) {
        if (!notice) {
            return;
        }
        notice.textContent = '';
        if (!message) {
            return;
        }
        const alert = document.createElement('div');
        alert.className = 'alert alert-' + (type === 'danger' ? 'danger' : 'warning') + ' mb-3';
        alert.setAttribute('role', type === 'danger' ? 'alert' : 'status');
        alert.textContent = message;
        notice.appendChild(alert);
    }

    function confirmAction(options) {
        if (typeof window.cmsConfirm === 'function') {
            window.cmsConfirm(options);
            return;
        }
        // Fallback wie in admin.js, falls der globale Dialog nicht geladen wurde (z. B. blockiertes Inline-Skript).
        const message = [options.title || '', options.message || ''].filter(Boolean).join('\n\n');
        if (window.confirm(message) && typeof options.onConfirm === 'function') {
            options.onConfirm();
        }
    }

    // ── Darstellung ────────────────────────────────────────────────────

    function setEvents(events) {
        state.events = events.filter(function (event) {
            return event && typeof event.uid === 'string' && parseYmd(event.date);
        });
        state.byUid = {};
        state.events.forEach(function (event) {
            state.byUid[event.uid] = event;
        });
    }

    function kindLabel(kind) {
        if (kind === 'scheduled') {
            return t.kindScheduled || kind;
        }
        if (kind === 'planned') {
            return t.kindPlanned || kind;
        }
        return t.kindPublished || kind;
    }

    function element(tag, className, text) {
        const node = document.createElement(tag);
        if (className) {
            node.className = className;
        }
        if (text !== undefined && text !== null && text !== '') {
            node.textContent = String(text);
        }
        return node;
    }

    function renderEvent(event, withMeta) {
        const kind = ['scheduled', 'planned', 'published'].indexOf(event.kind) !== -1 ? event.kind : 'published';
        const type = event.type === 'page' ? 'page' : 'post';
        const item = element('li', 'cms-ecal__event cms-ecal__event--' + kind + ' cms-ecal__event--' + type);
        item.dataset.uid = event.uid;
        if (event.movable) {
            item.draggable = true;
            item.classList.add('is-movable');
        }

        const icon = element('i', 'ti ' + (type === 'page' ? 'ti-file' : 'ti-article') + ' cms-ecal__event-icon');
        icon.setAttribute('aria-hidden', 'true');
        item.appendChild(icon);

        if (event.time_label) {
            const time = element('time', 'cms-ecal__event-time', event.time_label);
            time.setAttribute('datetime', String(event.datetime || ''));
            item.appendChild(time);
        }

        const body = element('span', 'cms-ecal__event-body');
        const link = element('a', 'cms-ecal__event-link', event.title);
        link.href = String(event.edit_url || '#');
        link.draggable = false;
        const details = [kindLabel(kind), type === 'page' ? t.typePage : t.typePost];
        if (event.author) {
            details.push(sprintf(t.author, event.author));
        }
        if (event.category) {
            details.push(sprintf(t.category, event.category));
        }
        link.title = event.title + ' · ' + details.join(' · ');
        body.appendChild(link);
        body.appendChild(element('span', 'visually-hidden', ', ' + details.join(', ')));
        if (withMeta) {
            const meta = [kindLabel(kind)];
            if (event.author) {
                meta.push(event.author);
            }
            if (event.category) {
                meta.push(event.category);
            }
            const metaLine = element('span', 'cms-ecal__event-meta', meta.join(' · '));
            metaLine.setAttribute('aria-hidden', 'true');
            body.appendChild(metaLine);
        }
        item.appendChild(body);

        if (event.movable) {
            const move = element('button', 'cms-ecal__event-move');
            move.type = 'button';
            move.dataset.ecalMove = event.uid;
            move.setAttribute('aria-haspopup', 'dialog');
            move.setAttribute('aria-label', sprintf(t.moveButton, event.title));
            move.title = sprintf(t.moveButton, event.title);
            const moveIcon = element('i', 'ti ti-calendar-time');
            moveIcon.setAttribute('aria-hidden', 'true');
            move.appendChild(moveIcon);
            item.appendChild(move);
        }

        return item;
    }

    function render() {
        const range = currentRange();
        const isWeek = state.view === 'week';
        const monthStart = startOfMonth(state.anchor);
        const eventsByDate = {};
        state.events.forEach(function (event) {
            (eventsByDate[event.date] = eventsByDate[event.date] || []).push(event);
        });

        root.dataset.view = state.view;
        root.classList.toggle('cms-ecal--week', isWeek);
        root.classList.toggle('cms-ecal--month', !isWeek);
        periodLabel.textContent = isWeek
            ? sprintf(t.weekLabel, isoWeek(range.start), formatDate.format(range.start), formatDate.format(range.end))
            : formatMonthTitle.format(monthStart);

        root.querySelectorAll('[data-ecal-view]').forEach(function (button) {
            button.setAttribute('aria-pressed', button.getAttribute('data-ecal-view') === state.view ? 'true' : 'false');
            button.classList.toggle('btn-primary', button.getAttribute('data-ecal-view') === state.view);
        });

        const fragment = document.createDocumentFragment();
        const weekdays = element('div', 'cms-ecal__weekdays');
        weekdays.setAttribute('aria-hidden', 'true');
        for (let i = 0; i < 7; i++) {
            weekdays.appendChild(element('div', '', formatWeekdayShort.format(addDays(range.start, i))));
        }
        fragment.appendChild(weekdays);

        const days = element('ol', 'cms-ecal__days');
        days.setAttribute('aria-label', periodLabel.textContent);
        for (let i = 0; i < range.days; i++) {
            const date = addDays(range.start, i);
            const key = ymd(date);
            const dayEvents = eventsByDate[key] || [];
            const day = element('li', 'cms-ecal__day');
            day.dataset.date = key;
            if (!isWeek && date.getUTCMonth() !== monthStart.getUTCMonth()) {
                day.classList.add('is-outside');
            }
            if (key === state.today) {
                day.classList.add('is-today');
            }
            if (dayEvents.length === 0) {
                day.setAttribute('aria-hidden', 'true');
            }

            const head = element('div', 'cms-ecal__day-head');
            const number = element('span', 'cms-ecal__day-num', isWeek ? formatDayHead.format(date) : String(date.getUTCDate()));
            number.setAttribute('aria-hidden', 'true');
            head.appendChild(number);
            head.appendChild(element('span', 'visually-hidden', formatDayLong.format(date)));
            day.appendChild(head);

            if (dayEvents.length > 0) {
                const list = element('ul', 'cms-ecal__events');
                dayEvents.forEach(function (event) {
                    list.appendChild(renderEvent(event, isWeek));
                });
                day.appendChild(list);
            }
            days.appendChild(day);
        }
        fragment.appendChild(days);

        grid.textContent = '';
        grid.appendChild(fragment);
    }

    // ── Verschieben ────────────────────────────────────────────────────

    function requestMove(event, date, viaDrop) {
        if (!event || !parseYmd(date)) {
            return;
        }
        if (date === event.date) {
            toast('info', t.moveSameDay);
            return;
        }
        if (!viaDrop) {
            postMove(event, date, false, false);
            return;
        }
        confirmAction({
            title: t.moveConfirmTitle,
            message: sprintf(t.moveConfirmMessage, event.title, formatYmd(date)),
            confirmText: t.moveConfirmButton,
            confirmClass: 'btn-primary',
            statusClass: 'bg-primary',
            onConfirm: function () {
                postMove(event, date, false, false);
            }
        });
    }

    function clearDropTargets() {
        grid.querySelectorAll('.is-drop-target').forEach(function (node) {
            node.classList.remove('is-drop-target');
        });
    }

    grid.addEventListener('dragstart', function (domEvent) {
        const item = domEvent.target instanceof Element ? domEvent.target.closest('.cms-ecal__event.is-movable') : null;
        if (!item) {
            return;
        }
        state.dragUid = item.dataset.uid || '';
        item.classList.add('is-dragging');
        if (domEvent.dataTransfer) {
            domEvent.dataTransfer.effectAllowed = 'move';
            domEvent.dataTransfer.setData('text/plain', state.dragUid);
        }
    });

    grid.addEventListener('dragover', function (domEvent) {
        const day = state.dragUid && domEvent.target instanceof Element ? domEvent.target.closest('.cms-ecal__day') : null;
        if (!day) {
            return;
        }
        domEvent.preventDefault();
        if (domEvent.dataTransfer) {
            domEvent.dataTransfer.dropEffect = 'move';
        }
        if (!day.classList.contains('is-drop-target')) {
            clearDropTargets();
            day.classList.add('is-drop-target');
        }
    });

    grid.addEventListener('dragleave', function (domEvent) {
        const day = domEvent.target instanceof Element ? domEvent.target.closest('.cms-ecal__day') : null;
        if (day && !(domEvent.relatedTarget instanceof Node && day.contains(domEvent.relatedTarget))) {
            day.classList.remove('is-drop-target');
        }
    });

    grid.addEventListener('drop', function (domEvent) {
        const day = state.dragUid && domEvent.target instanceof Element ? domEvent.target.closest('.cms-ecal__day') : null;
        if (!day) {
            return;
        }
        domEvent.preventDefault();
        const event = state.byUid[state.dragUid];
        clearDropTargets();
        requestMove(event, day.dataset.date || '', true);
    });

    grid.addEventListener('dragend', function () {
        state.dragUid = '';
        clearDropTargets();
        grid.querySelectorAll('.is-dragging').forEach(function (node) {
            node.classList.remove('is-dragging');
        });
    });

    // ── Popover (Tastatur-Alternative) ─────────────────────────────────

    function openPopover(event, trigger) {
        if (!popover || !popoverDate) {
            return;
        }
        state.popoverEvent = event;
        state.popoverTrigger = trigger;
        popoverItem.textContent = event.title + ' · ' + formatYmd(event.date);
        popoverDate.value = event.date;
        popover.hidden = false;

        const rect = trigger.getBoundingClientRect();
        const width = popover.offsetWidth || 288;
        const left = Math.max(8, Math.min(window.scrollX + rect.left, window.scrollX + document.documentElement.clientWidth - width - 8));
        popover.style.left = left + 'px';
        popover.style.top = (window.scrollY + rect.bottom + 6) + 'px';
        popoverDate.focus();
    }

    function closePopover(restoreFocus) {
        if (!popover || popover.hidden) {
            return;
        }
        popover.hidden = true;
        const trigger = state.popoverTrigger;
        state.popoverEvent = null;
        state.popoverTrigger = null;
        if (restoreFocus && trigger && trigger.isConnected) {
            trigger.focus();
        }
    }

    grid.addEventListener('click', function (domEvent) {
        const button = domEvent.target instanceof Element ? domEvent.target.closest('[data-ecal-move]') : null;
        if (!button) {
            return;
        }
        const event = state.byUid[button.getAttribute('data-ecal-move') || ''];
        if (event) {
            openPopover(event, button);
        }
    });

    if (popoverForm) {
        popoverForm.addEventListener('submit', function (domEvent) {
            domEvent.preventDefault();
            const event = state.popoverEvent;
            const date = popoverDate.value;
            if (!parseYmd(date)) {
                popoverDate.focus();
                return;
            }
            closePopover(true);
            requestMove(event, date, false);
        });
        const cancel = popoverForm.querySelector('[data-ecal-popover-cancel]');
        if (cancel) {
            cancel.addEventListener('click', function () {
                closePopover(true);
            });
        }
    }

    document.addEventListener('keydown', function (domEvent) {
        if (domEvent.key === 'Escape' && popover && !popover.hidden) {
            domEvent.preventDefault();
            closePopover(true);
        }
    });

    document.addEventListener('mousedown', function (domEvent) {
        if (popover && !popover.hidden && domEvent.target instanceof Node && !popover.contains(domEvent.target)
            && !(state.popoverTrigger && state.popoverTrigger.contains(domEvent.target))) {
            closePopover(false);
        }
    });

    // ── Navigation und Filter ──────────────────────────────────────────

    function navigate(direction) {
        if (direction === 'today') {
            state.anchor = parseYmd(state.today) || todayUtc();
        } else {
            const step = direction === 'prev' ? -1 : 1;
            state.anchor = state.view === 'week' ? addDays(state.anchor, step * 7) : addMonths(state.anchor, step);
        }
        closePopover(false);
        syncUrl();
        render();
        loadEvents();
    }

    root.querySelectorAll('[data-ecal-nav]').forEach(function (button) {
        button.hidden = false;
        button.addEventListener('click', function () {
            navigate(button.getAttribute('data-ecal-nav') || 'today');
        });
    });

    root.querySelectorAll('[data-ecal-view]').forEach(function (button) {
        button.hidden = false;
        button.addEventListener('click', function () {
            const view = button.getAttribute('data-ecal-view') === 'week' ? 'week' : 'month';
            if (view === state.view) {
                return;
            }
            state.view = view;
            closePopover(false);
            syncUrl();
            render();
            loadEvents();
        });
    });

    if (filterForm) {
        const submit = filterForm.querySelector('[data-ecal-filter-submit]');
        if (submit) {
            submit.hidden = true;
        }
        filterForm.addEventListener('change', function () {
            readFilterForm();
            syncUrl();
            loadEvents();
        });
        filterForm.addEventListener('submit', function (domEvent) {
            domEvent.preventDefault();
            readFilterForm();
            syncUrl();
            loadEvents();
        });
    }

    render();
    loadEvents();
})();
