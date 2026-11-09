/**
 * 365CMS Admin – Seitenleiste.
 *
 * - Gruppen und Plugin-Einträge auf-/zuklappen (aria-expanded, .is-collapsed);
 *   manuelle Zustände werden pro Benutzer gespeichert (POST admin-ui action=prefs).
 * - Einklappen zur Icon-Leiste (html[data-sidebar="collapsed"], ≥ 992px), serverseitig gespeichert.
 * - Icon-Leiste: Tooltips bei Hover und Tastaturfokus, Untereinträge als Flyout.
 * - Tastatur: Pfeil hoch/runter, Pos1/Ende innerhalb einer Gruppe bzw. eines Flyouts,
 *   Pfeil rechts/links öffnet/schließt Einträge mit Untereinträgen, Escape schließt Flyouts.
 */

import { focusableWithin, moveFocus } from 'cms-admin/dom.js';

const DESKTOP_QUERY = '(min-width: 992px)';
const SAVE_DELAY = 500;
const TOOLTIP_TRIGGER = '[data-cms-tip], [data-cms-sidebar-toggle]';

export function initSidebar(sidebar, client) {
    const root = document.documentElement;
    const nav = sidebar.querySelector('[data-cms-nav]');
    const railToggle = sidebar.querySelector('[data-cms-sidebar-toggle]');
    const tooltip = sidebar.querySelector('[data-cms-tooltip]');
    const desktop = window.matchMedia(DESKTOP_QUERY);

    const isRail = () => desktop.matches && root.dataset.sidebar === 'collapsed';
    const panelOf = (toggle) => document.getElementById(toggle.getAttribute('aria-controls') || '');

    /* ── Persistenz (gebündelt, verzögert) ─────────────────────────── */

    const pendingGroups = new Map();
    let pendingCollapsed = null;
    let saveTimer = 0;

    function flush({ keepalive = false } = {}) {
        window.clearTimeout(saveTimer);
        saveTimer = 0;
        if (pendingGroups.size === 0 && pendingCollapsed === null) {
            return;
        }

        const data = {};
        if (pendingGroups.size > 0) {
            data.groups = JSON.stringify(Object.fromEntries(pendingGroups));
        }
        if (pendingCollapsed !== null) {
            data.sidebar_collapsed = pendingCollapsed ? '1' : '0';
        }
        pendingGroups.clear();
        pendingCollapsed = null;

        client.post('prefs', data, { keepalive });
    }

    function scheduleSave() {
        window.clearTimeout(saveTimer);
        saveTimer = window.setTimeout(flush, SAVE_DELAY);
    }

    window.addEventListener('pagehide', () => flush({ keepalive: true }));
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'hidden') {
            flush({ keepalive: true });
        }
    });

    /* ── Inline auf-/zuklappen ─────────────────────────────────────── */

    function setInlineExpanded(toggle, expanded, { persist = true } = {}) {
        const panel = panelOf(toggle);
        if (!panel) {
            return;
        }

        panel.classList.toggle('is-collapsed', !expanded);
        toggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');

        const id = toggle.dataset.cmsToggle || '';
        if (persist && id !== '') {
            pendingGroups.set(id, expanded);
            scheduleSave();
        }
    }

    const isInlineExpanded = (toggle) => {
        const panel = panelOf(toggle);
        return !!panel && !panel.classList.contains('is-collapsed');
    };

    /** aria-expanded der Einträge spiegelt in der Icon-Leiste das Flyout, sonst den Inline-Zustand. */
    function syncEntryState() {
        nav?.querySelectorAll('.cms-nav__toggle[data-cms-toggle]').forEach((toggle) => {
            const panel = panelOf(toggle);
            const expanded = isRail()
                ? !!panel && panel.classList.contains('is-flyout-open')
                : isInlineExpanded(toggle);
            toggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
        });
    }

    /* ── Tooltips (Icon-Leiste bzw. Einklapp-Schalter) ─────────────── */

    let tipOwner = null;
    let tipTimer = 0;

    function tooltipText(element) {
        return (element.dataset.cmsTip || element.getAttribute('aria-label') || '').trim();
    }

    function wantsTooltip(element) {
        if (!tooltip || !desktop.matches) {
            return false;
        }
        if (element.matches('[data-cms-sidebar-toggle]')) {
            return true;
        }

        return isRail() && element.getAttribute('aria-expanded') !== 'true';
    }

    function showTooltip(element) {
        if (!wantsTooltip(element)) {
            return;
        }
        const text = tooltipText(element);
        if (text === '') {
            return;
        }

        window.clearTimeout(tipTimer);
        tipOwner = element;
        tooltip.textContent = text;
        tooltip.hidden = false;

        const rect = element.getBoundingClientRect();
        const tipRect = tooltip.getBoundingClientRect();
        const top = Math.min(
            Math.max(8, rect.top + (rect.height - tipRect.height) / 2),
            window.innerHeight - tipRect.height - 8
        );
        const left = Math.max(rect.right, sidebar.getBoundingClientRect().right) + 8;
        tooltip.style.left = Math.round(left) + 'px';
        tooltip.style.top = Math.round(top) + 'px';
    }

    function hideTooltip({ immediate = false } = {}) {
        window.clearTimeout(tipTimer);
        const hide = () => {
            tipOwner = null;
            if (tooltip) {
                tooltip.hidden = true;
            }
        };
        if (immediate) {
            hide();
        } else {
            tipTimer = window.setTimeout(hide, 150);
        }
    }

    sidebar.addEventListener('pointerover', (event) => {
        if (event.pointerType === 'touch') {
            return;
        }
        const trigger = event.target instanceof Element ? event.target.closest(TOOLTIP_TRIGGER) : null;
        if (trigger && sidebar.contains(trigger) && trigger !== tipOwner) {
            showTooltip(trigger);
        } else if (trigger && trigger === tipOwner) {
            window.clearTimeout(tipTimer);
        }
    });

    sidebar.addEventListener('pointerout', (event) => {
        if (!tipOwner || !(event.target instanceof Element) || !tipOwner.contains(event.target)) {
            return;
        }
        if (event.relatedTarget instanceof Node && (tipOwner.contains(event.relatedTarget) || tooltip.contains(event.relatedTarget))) {
            return;
        }
        hideTooltip();
    });

    // Tooltip bleibt sichtbar, solange der Zeiger darüber liegt (WCAG 1.4.13)
    tooltip?.addEventListener('pointerenter', () => window.clearTimeout(tipTimer));
    tooltip?.addEventListener('pointerleave', () => hideTooltip());

    sidebar.addEventListener('focusin', (event) => {
        const trigger = event.target instanceof Element ? event.target.closest(TOOLTIP_TRIGGER) : null;
        if (trigger && trigger.matches(':focus-visible')) {
            showTooltip(trigger);
        } else {
            hideTooltip({ immediate: true });
        }
    });

    sidebar.addEventListener('focusout', () => hideTooltip({ immediate: true }));

    /* ── Flyouts (Icon-Leiste) ─────────────────────────────────────── */

    let flyout = null;

    function positionFlyout() {
        if (!flyout) {
            return;
        }
        const rect = flyout.toggle.getBoundingClientRect();
        const height = flyout.panel.offsetHeight;
        const top = Math.max(8, Math.min(rect.top - 6, window.innerHeight - height - 8));
        flyout.panel.style.top = Math.round(top) + 'px';
    }

    function handleFlyoutPointerDown(event) {
        if (flyout && !flyout.panel.contains(event.target) && !flyout.toggle.contains(event.target)) {
            closeFlyout();
        }
    }

    function openFlyout(toggle, { focus = null } = {}) {
        const panel = panelOf(toggle);
        if (!panel) {
            return;
        }
        if (flyout && flyout.panel === panel) {
            if (focus) {
                focusFlyoutItem(focus);
            }
            return;
        }

        closeFlyout();
        hideTooltip({ immediate: true });
        panel.classList.add('is-flyout-open');
        toggle.setAttribute('aria-expanded', 'true');
        flyout = { toggle, panel };
        positionFlyout();
        document.addEventListener('pointerdown', handleFlyoutPointerDown, true);

        if (focus) {
            focusFlyoutItem(focus);
        }
    }

    function focusFlyoutItem(which) {
        const items = focusableWithin(flyout?.panel);
        const target = which === 'last' ? items[items.length - 1] : items[0];
        target?.focus();
    }

    function closeFlyout({ restoreFocus = false } = {}) {
        if (!flyout) {
            return;
        }

        const { toggle, panel } = flyout;
        flyout = null;
        panel.classList.remove('is-flyout-open');
        panel.style.removeProperty('top');
        toggle.setAttribute('aria-expanded', !isRail() && isInlineExpanded(toggle) ? 'true' : 'false');
        document.removeEventListener('pointerdown', handleFlyoutPointerDown, true);

        if (restoreFocus) {
            toggle.focus();
        }
    }

    /* ── Klicks ────────────────────────────────────────────────────── */

    nav?.addEventListener('click', (event) => {
        const toggle = event.target instanceof Element ? event.target.closest('[data-cms-toggle]') : null;
        if (!toggle || !nav.contains(toggle)) {
            return;
        }

        event.preventDefault();
        if (toggle.classList.contains('cms-nav__toggle') && isRail()) {
            if (flyout && flyout.toggle === toggle) {
                closeFlyout();
            } else {
                openFlyout(toggle, { focus: event.detail === 0 ? 'first' : null });
            }
            return;
        }

        setInlineExpanded(toggle, !isInlineExpanded(toggle));
    });

    function setRail(collapsed) {
        closeFlyout();
        hideTooltip({ immediate: true });
        root.dataset.sidebar = collapsed ? 'collapsed' : 'expanded';
        railToggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
        railToggle.setAttribute('aria-label', collapsed ? 'Seitenleiste ausklappen' : 'Seitenleiste einklappen');
        syncEntryState();

        pendingCollapsed = collapsed;
        scheduleSave();

        if (railToggle.matches(':focus-visible')) {
            showTooltip(railToggle);
        }
    }

    railToggle?.addEventListener('click', () => setRail(root.dataset.sidebar !== 'collapsed'));

    /* ── Tastatur ──────────────────────────────────────────────────── */

    nav?.addEventListener('keydown', (event) => {
        const current = event.target;
        if (!(current instanceof HTMLElement)) {
            return;
        }

        const inFlyout = !!flyout && flyout.panel.contains(current);

        if (event.key === 'Escape') {
            hideTooltip({ immediate: true });
            if (flyout && (inFlyout || flyout.toggle === current)) {
                event.preventDefault();
                event.stopPropagation();
                closeFlyout({ restoreFocus: true });
            }
            return;
        }

        if (['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) {
            if (inFlyout) {
                moveFocus(event, focusableWithin(flyout.panel));
                return;
            }
            const group = current.closest('.cms-nav__group');
            if (group) {
                moveFocus(event, focusableWithin(group, { exclude: '.is-flyout-open' }));
            }
            return;
        }

        if (event.key === 'ArrowRight' && current.matches('.cms-nav__toggle')) {
            event.preventDefault();
            if (isRail()) {
                openFlyout(current, { focus: 'first' });
            } else if (!isInlineExpanded(current)) {
                setInlineExpanded(current, true);
            } else {
                focusableWithin(panelOf(current))[0]?.focus();
            }
            return;
        }

        if (event.key === 'ArrowLeft') {
            if (inFlyout) {
                event.preventDefault();
                closeFlyout({ restoreFocus: true });
            } else if (current.matches('.cms-nav__toggle') && !isRail() && isInlineExpanded(current)) {
                event.preventDefault();
                setInlineExpanded(current, false);
            } else if (current.matches('.cms-nav__sublink') && !isRail()) {
                const parentToggle = current.closest('.cms-nav__item--parent')?.querySelector(':scope > .cms-nav__toggle');
                if (parentToggle) {
                    event.preventDefault();
                    parentToggle.focus();
                }
            }
        }
    });

    sidebar.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && tooltip && !tooltip.hidden) {
            hideTooltip({ immediate: true });
        }
    });

    nav?.addEventListener('focusout', (event) => {
        if (flyout && event.relatedTarget instanceof Node
            && !flyout.panel.contains(event.relatedTarget) && !flyout.toggle.contains(event.relatedTarget)) {
            closeFlyout();
        }
    });

    /* ── Umgebung ──────────────────────────────────────────────────── */

    const handleModeChange = () => {
        closeFlyout();
        hideTooltip({ immediate: true });
        syncEntryState();
    };

    if (typeof desktop.addEventListener === 'function') {
        desktop.addEventListener('change', handleModeChange);
    }

    nav?.addEventListener('scroll', () => {
        hideTooltip({ immediate: true });
        positionFlyout();
    }, { passive: true });

    window.addEventListener('resize', () => {
        hideTooltip({ immediate: true });
        positionFlyout();
    }, { passive: true });

    syncEntryState();
}
