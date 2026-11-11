/**
 * 365CMS – Einstellungen › Admin-Menü
 *
 * Übersicht (/admin/settings?tab=admin-menu): zeigt beim Wechsel der Profilauswahl die passende Vorschau.
 * Menü-Editor (…&view=editor): bearbeitet ein eigenes Layout im Browser – Ziehen und Ablegen, Pfeil-Buttons
 * bzw. Alt+Pfeiltasten, Eigenschaften-Bereich. Die Vorschau rendert der Server (POST action=preview, JSON),
 * gespeichert wird über das Formular (POST action=save). Geprüft wird serverseitig (LayoutValidator).
 *
 * DOM wird ausschließlich über die DOM-API aufgebaut. Einzige Ausnahme ist das serverseitig gerenderte und
 * escapte Vorschau-HTML; es läuft über die Trusted-Types-Default-Policy (DOMPurify, cms-csp-runtime.js).
 *
 * Nicht verwechseln mit admin-menu-editor.js (Menüs des Themes).
 */
(function () {
    'use strict';

    var FORMAT = '365cms-admin-menu';
    var FORMAT_VERSION = 1;
    var ITEM_ID_PATTERN = /^[A-Za-z0-9][A-Za-z0-9_.:-]{0,150}$/;

    // ── Gemeinsame Helfer ─────────────────────────────────────────────────

    function h(tag, attrs, children) {
        var node = document.createElement(tag);
        Object.keys(attrs || {}).forEach(function (key) {
            var value = attrs[key];
            if (value === null || value === undefined || value === false) {
                return;
            }
            if (key === 'class') {
                node.className = value;
            } else if (key === 'text') {
                node.textContent = String(value);
            } else if (value === true) {
                node.setAttribute(key, '');
            } else {
                node.setAttribute(key, String(value));
            }
        });
        (children || []).forEach(function (child) {
            if (child === null || child === undefined || child === false) {
                return;
            }
            node.append(child instanceof Node ? child : String(child));
        });

        return node;
    }

    function toast(type, message) {
        if (typeof window.cmsToast === 'function') {
            window.cmsToast(type, message);
        }
    }

    function confirmAction(options) {
        if (typeof window.cmsConfirm === 'function') {
            window.cmsConfirm(options);
            return;
        }
        if (window.confirm(options.message || options.title || 'Sind Sie sicher?')) {
            options.onConfirm();
        }
    }

    function quote(text) {
        return '„' + text + '“';
    }

    function plural(count, one, many) {
        return count === 1 ? '1 ' + one : count + ' ' + many;
    }

    // ── Übersicht: Vorschau zur gewählten Profil-Option ───────────────────

    function initOverview() {
        var form = document.querySelector('[data-am-profile-form]');
        if (!form) {
            return;
        }

        form.addEventListener('change', function (event) {
            var radio = event.target;
            if (!(radio instanceof HTMLInputElement) || !radio.hasAttribute('data-am-profile-radio') || !radio.checked) {
                return;
            }
            form.querySelectorAll('[data-am-preview]').forEach(function (figure) {
                figure.hidden = figure.getAttribute('data-am-preview') !== radio.value;
            });
        });
    }

    // ── Menü-Editor ───────────────────────────────────────────────────────

    function initEditor() {
        var form = document.getElementById('cms-am-save-form');
        var dataNode = document.getElementById('cms-am-editor-data');
        if (!form || !dataNode) {
            return;
        }

        var data;
        try {
            data = JSON.parse(dataNode.textContent || '{}');
        } catch (error) {
            return;
        }

        var el = {
            tree: form.querySelector('[data-am-tree]'),
            available: form.querySelector('[data-am-available]'),
            availableCount: form.querySelector('[data-am-available-count]'),
            layoutCount: form.querySelector('[data-am-layout-count]'),
            expandAll: form.querySelector('[data-am-expand-all]'),
            search: form.querySelector('[data-am-search]'),
            target: form.querySelector('[data-am-target]'),
            base: form.querySelector('[data-am-base]'),
            fallback: form.querySelector('[data-am-fallback]'),
            root: form.querySelector('[data-am-root]'),
            inspector: form.querySelector('[data-am-inspector]'),
            preview: form.querySelector('[data-am-preview]'),
            previewState: form.querySelector('[data-am-preview-state]'),
            previewWarnings: form.querySelector('[data-am-preview-warnings]'),
            start: form.querySelector('[data-am-start]'),
            addGroup: form.querySelector('[data-am-add-group]'),
            addSeparator: form.querySelector('[data-am-add-separator]'),
            exportButton: form.querySelector('[data-am-export]'),
            importInput: form.querySelector('[data-am-import]'),
            dirty: document.querySelector('[data-am-dirty]'),
            discard: form.querySelector('[data-am-discard]'),
            save: form.querySelector('[data-am-save]'),
            layoutField: form.querySelector('[data-am-layout-field]'),
            origin: form.querySelector('[data-am-origin]'),
            status: document.querySelector('[data-am-status]'),
            iconList: document.querySelector('[data-am-icon-list]'),
            resetForm: document.querySelector('[data-am-reset-form]')
        };
        if (!el.tree || !el.available || !el.inspector || !el.preview || !el.layoutField) {
            return;
        }

        // Klebende Werkzeugleiste: ihre Höhe braucht die ebenfalls klebende Seitenspalte (CSS-Variable, CSSOM – CSP-konform).
        var toolbar = form.querySelector('.cms-am-toolbar');
        if (toolbar && typeof window.ResizeObserver === 'function') {
            new window.ResizeObserver(function () {
                form.style.setProperty('--cms-am-toolbar-height', Math.ceil(toolbar.getBoundingClientRect().height) + 'px');
            }).observe(toolbar);
        }

        var limits = {
            nodes: Number(data.limits && data.limits.nodes) || 200,
            label: Number(data.limits && data.limits.label) || 60,
            bytes: Number(data.limits && data.limits.bytes) || 262144
        };
        var iconPattern = new RegExp(data.patterns && data.patterns.icon ? data.patterns.icon : '^[a-z0-9][a-z0-9-]{0,59}$');
        var items = Array.isArray(data.items) ? data.items : [];
        var itemById = new Map(items.map(function (item) { return [item.id, item]; }));
        var lockedIds = new Set(Array.isArray(data.locked) ? data.locked : []);
        var reasons = data.reasons && typeof data.reasons === 'object' ? data.reasons : {};

        var state = null;
        var selectedKey = null;
        var baselineJson = '';
        var importSnapshot = null;
        var importWarnings = [];
        var dirty = false;
        var submitting = false;
        var drag = null;
        var dropMarked = null;
        var preview = { timer: 0, controller: null, seq: 0 };

        // Aufgeklappte Gruppen: reiner Ansichtszustand (nicht Teil des Layouts), je Bereich in der Sitzung gemerkt.
        var expanded = new Set();
        var expandedStorageKey = 'cms-am-expanded:' + String(data.scope || '');
        var expandHover = { group: null, timer: 0 };
        try {
            var storedExpanded = JSON.parse(window.sessionStorage.getItem(expandedStorageKey) || '[]');
            if (Array.isArray(storedExpanded)) {
                storedExpanded.forEach(function (id) {
                    if (typeof id === 'string') {
                        expanded.add(id);
                    }
                });
            }
        } catch (error) {
            // Ohne Sitzungsspeicher starten alle Gruppen eingeklappt.
        }

        // ── Zustand ───────────────────────────────────────────────────────

        function stringMap(source, valid) {
            var out = {};
            if (!source || typeof source !== 'object' || Array.isArray(source)) {
                return out;
            }
            Object.keys(source).forEach(function (id) {
                var value = source[id];
                if (ITEM_ID_PATTERN.test(id) && typeof value === 'string' && value.trim() !== '' && (!valid || valid(value))) {
                    out[id] = value;
                }
            });

            return out;
        }

        function normalise(layout) {
            var source = layout && typeof layout === 'object' ? layout : {};
            var next = {
                base: source.base === 'structured' ? 'structured' : 'classic',
                fallback_group: typeof source.fallback_group === 'string' ? source.fallback_group : '',
                root_group: typeof source.root_group === 'string' ? source.root_group : '',
                nodes: [],
                labels: stringMap(source.labels),
                icons: stringMap(source.icons, function (value) { return iconPattern.test(value); }),
                hidden: {}
            };

            (Array.isArray(source.nodes) ? source.nodes : []).forEach(function (node) {
                if (typeof node === 'string') {
                    node = { type: 'item', id: node };
                }
                if (!node || typeof node !== 'object') {
                    return;
                }
                var type = node.type || 'item';
                if (type === 'item' && typeof node.id === 'string' && node.id !== '') {
                    next.nodes.push({ type: 'item', id: node.id });
                } else if (type === 'separator') {
                    next.nodes.push({ type: 'separator', id: String(node.id || ''), label: String(node.label || '') });
                } else if (type === 'group') {
                    next.nodes.push({
                        type: 'group',
                        id: String(node.id || ''),
                        label: String(node.label || ''),
                        icon: typeof node.icon === 'string' && iconPattern.test(node.icon) ? node.icon : 'folder',
                        display: node.display === 'tiles' ? 'tiles' : 'menu',
                        items: (Array.isArray(node.items) ? node.items : []).map(function (child) {
                            if (typeof child === 'string') {
                                return child;
                            }
                            return child && typeof child.id === 'string' ? child.id : '';
                        }).filter(Boolean)
                    });
                }
            });

            (Array.isArray(source.hidden) ? source.hidden : []).forEach(function (id) {
                if (typeof id === 'string' && ITEM_ID_PATTERN.test(id) && !isLocked(id)) {
                    next.hidden[id] = true;
                }
            });

            return next;
        }

        function sortedMap(map) {
            var out = {};
            Object.keys(map).sort().forEach(function (key) {
                out[key] = map[key];
            });

            return out;
        }

        function serialise(source) {
            return {
                format: FORMAT,
                version: FORMAT_VERSION,
                base: source.base,
                fallback_group: source.fallback_group,
                root_group: source.root_group,
                nodes: source.nodes.map(function (node) {
                    if (node.type === 'item') {
                        return { type: 'item', id: node.id };
                    }
                    if (node.type === 'separator') {
                        return { type: 'separator', id: node.id, label: node.label };
                    }
                    return { type: 'group', id: node.id, label: node.label, icon: node.icon, display: node.display, items: node.items.slice() };
                }),
                labels: sortedMap(source.labels),
                icons: sortedMap(source.icons),
                hidden: Object.keys(source.hidden).sort()
            };
        }

        function stateJson() {
            return JSON.stringify(serialise(state));
        }

        function isLocked(id) {
            var item = itemById.get(id);

            return lockedIds.has(id) || Boolean(item && item.locked);
        }

        function groups() {
            return state.nodes.filter(function (node) { return node.type === 'group'; });
        }

        function findGroup(id) {
            return groups().find(function (node) { return node.id === id; }) || null;
        }

        function countNodes() {
            return state.nodes.reduce(function (sum, node) {
                return sum + 1 + (node.type === 'group' ? node.items.length : 0);
            }, 0);
        }

        function placement() {
            var map = new Map();
            state.nodes.forEach(function (node, index) {
                if (node.type === 'item') {
                    map.set(node.id, { group: null, index: index });
                } else if (node.type === 'group') {
                    node.items.forEach(function (id, childIndex) {
                        map.set(id, { group: node, index: childIndex });
                    });
                }
            });

            return map;
        }

        function splitKey(key) {
            var colon = key.indexOf(':');

            return { kind: key.slice(0, colon), id: key.slice(colon + 1) };
        }

        /** Fundstelle eines Schlüssels (item:<id> bzw. node:<id>) im Layout. */
        function locate(key) {
            var parts = splitKey(key);
            for (var i = 0; i < state.nodes.length; i++) {
                var node = state.nodes[i];
                if (parts.kind === 'node' && node.type !== 'item' && node.id === parts.id) {
                    return { kind: 'node', id: parts.id, group: null, index: i, node: node };
                }
                if (parts.kind === 'item') {
                    if (node.type === 'item' && node.id === parts.id) {
                        return { kind: 'item', id: parts.id, group: null, index: i, node: node };
                    }
                    if (node.type === 'group') {
                        var childIndex = node.items.indexOf(parts.id);
                        if (childIndex !== -1) {
                            return { kind: 'item', id: parts.id, group: node, index: childIndex, node: null };
                        }
                    }
                }
            }

            return null;
        }

        function itemLabel(id) {
            var item = itemById.get(id);

            return state.labels[id] || (item ? item.label : id);
        }

        function keyLabel(key) {
            var parts = splitKey(key);
            if (parts.kind !== 'node') {
                return itemLabel(parts.id);
            }
            var loc = locate(key);
            if (!loc) {
                return parts.id;
            }

            return loc.node.type === 'group' ? (loc.node.label || loc.node.id) : (loc.node.label || 'Abschnitt');
        }

        function itemIcon(id) {
            if (state.icons[id]) {
                return { type: 'tabler', name: state.icons[id] };
            }
            var item = itemById.get(id);
            if (item && item.icon && typeof item.icon === 'object') {
                return item.icon;
            }

            return { type: 'tabler', name: String(id).indexOf('plugin:') === 0 ? 'puzzle' : 'point' };
        }

        /** Einfügeposition vor dem Entfernen; liegt das Ziel in derselben Liste hinter der Quelle, wird verrechnet. */
        function moveTo(key, target) {
            var entry;
            if (key.indexOf('avail:') === 0) {
                var newId = key.slice(6);
                if (placement().has(newId)) {
                    return false;
                }
                if (countNodes() >= limits.nodes) {
                    toast('warning', 'Das Layout darf höchstens ' + limits.nodes + ' Elemente enthalten.');
                    return false;
                }
                entry = { type: 'item', id: newId };
            } else {
                var loc = locate(key);
                if (!loc || (loc.kind === 'node' && target.group !== null)) {
                    return false;
                }
                var sameList = (loc.group === null && target.group === null) || (loc.group !== null && loc.group.id === target.group);
                var index = target.index;
                if (sameList && loc.index < index) {
                    index -= 1;
                }
                if (sameList && loc.index === index) {
                    return false;
                }
                if (loc.group) {
                    loc.group.items.splice(loc.index, 1);
                    entry = { type: 'item', id: loc.id };
                } else {
                    entry = state.nodes.splice(loc.index, 1)[0];
                }
                target = { group: target.group, index: index };
            }

            if (target.group !== null) {
                var group = findGroup(target.group);
                if (!group || entry.type !== 'item') {
                    return false;
                }
                group.items.splice(Math.max(0, Math.min(target.index, group.items.length)), 0, entry.id);
            } else {
                state.nodes.splice(Math.max(0, Math.min(target.index, state.nodes.length)), 0, entry);
            }

            return true;
        }

        /** Ziel für „nach oben/unten“: Einträge wandern dabei in benachbarte Gruppen hinein bzw. aus ihnen heraus. */
        function stepTarget(key, direction) {
            var loc = locate(key);
            if (!loc) {
                return null;
            }
            if (loc.kind === 'node') {
                var swap = loc.index + direction;
                if (swap < 0 || swap >= state.nodes.length) {
                    return null;
                }
                return { group: null, index: direction < 0 ? swap : swap + 1 };
            }
            if (loc.group) {
                var groupIndex = state.nodes.indexOf(loc.group);
                if (direction < 0) {
                    return loc.index > 0 ? { group: loc.group.id, index: loc.index - 1 } : { group: null, index: groupIndex };
                }
                return loc.index < loc.group.items.length - 1 ? { group: loc.group.id, index: loc.index + 2 } : { group: null, index: groupIndex + 1 };
            }
            if (direction < 0) {
                if (loc.index === 0) {
                    return null;
                }
                var previous = state.nodes[loc.index - 1];
                return previous.type === 'group' ? { group: previous.id, index: previous.items.length } : { group: null, index: loc.index - 1 };
            }
            if (loc.index >= state.nodes.length - 1) {
                return null;
            }
            var next = state.nodes[loc.index + 1];

            return next.type === 'group' ? { group: next.id, index: 0 } : { group: null, index: loc.index + 2 };
        }

        function positionText(key) {
            var loc = locate(key);
            if (!loc) {
                return '';
            }
            if (loc.group) {
                return 'Gruppe ' + quote(loc.group.label || loc.group.id) + ', Position ' + (loc.index + 1) + ' von ' + loc.group.items.length;
            }

            return 'erste Ebene, Position ' + (loc.index + 1) + ' von ' + state.nodes.length;
        }

        function uniqueNodeId(prefix) {
            var used = new Set(state.nodes.filter(function (node) { return node.type !== 'item'; }).map(function (node) { return node.id; }));
            var number = 1;
            while (used.has(prefix + '-' + number)) {
                number++;
            }

            return prefix + '-' + number;
        }

        function insertIndexAfterSelection() {
            var loc = selectedKey ? locate(selectedKey) : null;
            if (!loc) {
                return state.nodes.length;
            }

            return (loc.group ? state.nodes.indexOf(loc.group) : loc.index) + 1;
        }

        /** Auffanggruppe und Start-Kachelseite müssen auf vorhandene (Kachel-)Gruppen zeigen. */
        function sanitise() {
            var fallback = state.fallback_group !== '' ? findGroup(state.fallback_group) : null;
            if (state.fallback_group !== '' && !fallback) {
                state.fallback_group = '';
            }
            var root = state.root_group !== '' ? findGroup(state.root_group) : null;
            if (state.root_group !== '' && (!root || root.display !== 'tiles')) {
                state.root_group = '';
            }
            if (selectedKey && selectedKey.indexOf('avail:') !== 0 && !locate(selectedKey)) {
                selectedKey = null;
            }
        }

        // ── Darstellung ───────────────────────────────────────────────────

        function iconNode(icon, extraClass) {
            var wrap = h('span', { class: 'cms-am-icon' + (extraClass ? ' ' + extraClass : ''), 'aria-hidden': 'true' });
            var spec = typeof icon === 'string' ? { type: 'tabler', name: icon } : (icon || {});
            if (spec.type === 'image' && typeof spec.src === 'string' && /^(data:image\/|https?:\/\/|\/)/i.test(spec.src)) {
                wrap.append(h('img', { src: spec.src, alt: '', width: 18, height: 18, loading: 'lazy', decoding: 'async' }));
            } else if (spec.type === 'text' && typeof spec.text === 'string') {
                wrap.append(h('span', { class: 'cms-am-icon__text', text: spec.text }));
            } else {
                var name = typeof spec.name === 'string' && /^[a-z0-9-]+$/.test(spec.name) ? spec.name : 'point';
                wrap.append(h('i', { class: 'ti ti-' + name }));
            }

            return wrap;
        }

        function chip(text, variant, title) {
            return h('span', { class: 'cms-am-chip cms-am-chip--' + variant, title: title || null, text: text });
        }

        function iconButton(action, icon, label, disabled, title) {
            return h('button', {
                type: 'button',
                class: 'cms-am-icon-btn',
                'data-am-act': action,
                'aria-label': label,
                title: title || label,
                disabled: Boolean(disabled)
            }, [h('i', { class: 'ti ' + icon, 'aria-hidden': 'true' })]);
        }

        function itemChips(id) {
            var item = itemById.get(id);
            var chips = [];
            if (state.hidden[id]) {
                chips.push(chip('Ausgeblendet', 'muted', 'Erscheint nicht in der Seitenleiste.'));
            }
            if (isLocked(id)) {
                chips.push(chip('Gesperrt', 'locked', 'Bleibt immer erreichbar und kann weder entfernt noch ausgeblendet werden.'));
            }
            if (!item) {
                chips.push(id.indexOf('plugin:') === 0
                    ? chip('Plugin inaktiv', 'warning', 'Das Plugin ist derzeit nicht aktiv oder dieser Site nicht zugewiesen. Der Platz bleibt erhalten.')
                    : chip('Unbekannt', 'danger', 'Dieser Eintrag existiert nicht und wird beim Speichern entfernt.'));
            } else {
                if (item.reason && reasons[item.reason]) {
                    chips.push(chip(reasons[item.reason], 'warning', 'Derzeit nicht sichtbar: ' + reasons[item.reason] + '.'));
                }
                if (item.external) {
                    chips.push(chip('Link', 'info', 'Verweist auf eine Seite außerhalb des Adminbereichs.'));
                }
                if (item.plugin) {
                    chips.push(chip('Plugin', 'plugin', item.area_label || null));
                }
            }

            return chips;
        }

        function rowActions(key, label, removable, removeLabel, removeTitle) {
            return h('span', { class: 'cms-am-row__actions' }, [
                iconButton('up', 'ti-arrow-up', quote(label) + ' nach oben', !stepTarget(key, -1)),
                iconButton('down', 'ti-arrow-down', quote(label) + ' nach unten', !stepTarget(key, 1)),
                iconButton('remove', 'ti-x', removeLabel, !removable, removeTitle)
            ]);
        }

        function rowShell(key, extraClass, icon, label, meta, chips, actions, toggle) {
            var selected = key === selectedKey;

            return h('div', {
                class: 'cms-am-row' + (extraClass ? ' ' + extraClass : '') + (selected ? ' is-selected' : ''),
                'data-am-key': key,
                draggable: 'true'
            }, [
                h('span', { class: 'cms-am-row__handle', 'aria-hidden': 'true' }, [h('i', { class: 'ti ti-grip-vertical' })]),
                toggle || null,
                icon,
                h('button', {
                    type: 'button',
                    class: 'cms-am-row__main',
                    'data-am-act': 'select',
                    'aria-current': selected ? 'true' : null,
                    'aria-describedby': 'cms-am-tree-help'
                }, [
                    h('span', { class: 'cms-am-row__label', text: label }),
                    meta ? h('span', { class: 'cms-am-row__meta', text: meta }) : null
                ]),
                chips.length ? h('span', { class: 'cms-am-row__chips' }, chips) : null,
                actions
            ]);
        }

        function itemRow(id, isChild) {
            var key = 'item:' + id;
            var item = itemById.get(id);
            var label = itemLabel(id);
            var locked = isLocked(id);
            var meta = item ? (item.route || id) : id;
            if (item && state.labels[id] && state.labels[id] !== item.label) {
                meta = 'statt ' + quote(item.label) + ' · ' + meta;
            }
            var classes = 'cms-am-row--item' + (state.hidden[id] ? ' is-muted' : '') + (!item || item.reason ? ' is-unavailable' : '');

            return h('li', { class: 'cms-am-node' + (isChild ? ' cms-am-node--child' : ' cms-am-node--item'), 'data-am-node': key }, [
                rowShell(key, classes, iconNode(itemIcon(id), 'cms-am-row__icon'), label, meta, itemChips(id), rowActions(
                    key,
                    label,
                    !locked,
                    quote(label) + ' aus dem Layout entfernen',
                    locked ? 'Gesperrte Einträge können nicht entfernt werden.' : null
                ))
            ]);
        }

        function storeExpanded() {
            try {
                window.sessionStorage.setItem(expandedStorageKey, JSON.stringify(Array.from(expanded)));
            } catch (error) {
                // Ohne Sitzungsspeicher gilt der Zustand nur bis zum Neuladen.
            }
        }

        /** Gelöschte oder nicht mehr vorhandene Gruppen aus dem Aufklapp-Zustand entfernen. */
        function pruneExpanded() {
            var ids = new Set(groups().map(function (group) { return group.id; }));
            var stale = Array.from(expanded).filter(function (id) { return !ids.has(id); });
            if (stale.length === 0) {
                return;
            }
            stale.forEach(function (id) { expanded.delete(id); });
            storeExpanded();
        }

        function childrenDomId(groupId) {
            return 'cms-am-children-' + groupId;
        }

        /** Auf-/Zuklappen ohne Neuaufbau des Baums – funktioniert so auch während eines Ziehvorgangs. */
        function applyExpanded(groupId) {
            var open = expanded.has(groupId);
            var row = findRow(el.tree, 'node:' + groupId);
            var list = document.getElementById(childrenDomId(groupId));
            var button = row ? row.querySelector('[data-am-act="toggle"]') : null;
            if (list) {
                list.hidden = !open;
            }
            if (button) {
                button.setAttribute('aria-expanded', open ? 'true' : 'false');
                button.title = open ? 'Einklappen' : 'Aufklappen';
            }
        }

        function setExpanded(groupId, open) {
            if (open === expanded.has(groupId)) {
                return;
            }
            if (open) {
                expanded.add(groupId);
            } else {
                expanded.delete(groupId);
            }
            storeExpanded();
            applyExpanded(groupId);
            updateExpandAll();
        }

        /** Klappt die Gruppe auf, in der ein Eintrag nach dem Verschieben oder Hinzufügen liegt. */
        function reveal(key) {
            var loc = locate(key);
            if (loc && loc.group && !expanded.has(loc.group.id)) {
                expanded.add(loc.group.id);
                storeExpanded();
            }
        }

        function updateExpandAll() {
            if (!el.expandAll) {
                return;
            }
            var all = groups();
            var allOpen = all.length > 0 && all.every(function (group) { return expanded.has(group.id); });
            el.expandAll.hidden = all.length === 0;
            var labelNode = el.expandAll.querySelector('[data-am-expand-all-label]');
            if (labelNode) {
                labelNode.textContent = allOpen ? 'Alle einklappen' : 'Alle aufklappen';
            }
            var icon = el.expandAll.querySelector('i');
            if (icon) {
                icon.className = 'ti ' + (allOpen ? 'ti-chevrons-up' : 'ti-chevrons-down') + ' me-1';
            }
        }

        function visibleRows() {
            return Array.prototype.slice.call(el.tree.querySelectorAll('.cms-am-row')).filter(function (row) {
                return !row.closest('[hidden]');
            });
        }

        function groupNode(group) {
            var key = 'node:' + group.id;
            var label = group.label || group.id;
            var open = expanded.has(group.id);
            var chips = [];
            if (group.display === 'tiles') {
                chips.push(chip('Kacheln', 'info', 'Ein Link in der Seitenleiste; die Einträge erscheinen als Kacheln auf /admin/configuration?group=' + group.id + '.'));
            }
            if (state.fallback_group === group.id) {
                chips.push(chip('Auffanggruppe', 'accent', 'Nicht platzierte Plugin- und Core-Einträge erscheinen hier.'));
            }
            if (state.root_group === group.id) {
                chips.push(chip('Start-Kachelseite', 'accent', 'Wird unter /admin/configuration ohne Gruppenangabe gezeigt.'));
            }
            var children = h('ol', {
                class: 'cms-am-children',
                id: childrenDomId(group.id),
                'aria-label': 'Einträge in ' + quote(label),
                hidden: !open
            });
            group.items.forEach(function (id) {
                children.append(itemRow(id, true));
            });
            children.append(h('li', {
                class: 'cms-am-dropend' + (group.items.length === 0 ? ' is-empty' : ''),
                'data-am-dropend': group.id,
                text: group.items.length === 0 ? 'Leere Gruppe – Einträge hierher ziehen' : 'Ans Ende von ' + quote(label) + ' ziehen'
            }));
            var toggle = h('button', {
                type: 'button',
                class: 'cms-am-icon-btn cms-am-row__toggle',
                'data-am-act': 'toggle',
                'aria-expanded': open ? 'true' : 'false',
                'aria-controls': childrenDomId(group.id),
                'aria-label': 'Einträge von ' + quote(label),
                title: open ? 'Einklappen' : 'Aufklappen'
            }, [h('i', { class: 'ti ti-chevron-right', 'aria-hidden': 'true' })]);

            return h('li', { class: 'cms-am-node cms-am-node--group', 'data-am-node': key }, [
                rowShell(
                    key,
                    'cms-am-row--group',
                    iconNode(group.icon, 'cms-am-row__icon'),
                    label,
                    (group.display === 'tiles' ? 'Kachelgruppe' : 'Gruppe') + ' · ' + plural(group.items.length, 'Eintrag', 'Einträge'),
                    chips,
                    rowActions(key, label, true, 'Gruppe ' + quote(label) + ' löschen', null),
                    toggle
                ),
                children
            ]);
        }

        function separatorNode(separator) {
            var key = 'node:' + separator.id;
            var label = separator.label || 'Abschnitt ohne Überschrift';

            return h('li', { class: 'cms-am-node cms-am-node--separator', 'data-am-node': key }, [
                rowShell(
                    key,
                    'cms-am-row--separator',
                    iconNode('separator-horizontal', 'cms-am-row__icon'),
                    label,
                    separator.label ? 'Abschnitt' : 'Trennlinie',
                    [],
                    rowActions(key, label, true, quote(label) + ' entfernen', null)
                )
            ]);
        }

        function focusInfo(container) {
            var active = document.activeElement;
            if (!(active instanceof HTMLElement) || !container.contains(active)) {
                return null;
            }
            var row = active.closest('[data-am-key]');

            return row ? { key: row.getAttribute('data-am-key'), act: active.getAttribute('data-am-act') } : null;
        }

        function findRow(container, key) {
            var rows = container.querySelectorAll('[data-am-key]');
            for (var i = 0; i < rows.length; i++) {
                if (rows[i].getAttribute('data-am-key') === key) {
                    return rows[i];
                }
            }

            return null;
        }

        function focusRow(container, key, act) {
            var row = findRow(container, key);
            if (!row) {
                return false;
            }
            var button = act ? row.querySelector('[data-am-act="' + act + '"]') : null;
            if (!button || button.disabled) {
                button = row.querySelector('[data-am-act="select"]') || row.querySelector('button:not([disabled])');
            }
            if (button) {
                button.focus();
                return true;
            }

            return false;
        }

        function renderTree() {
            var restore = focusInfo(el.tree);
            var fragment = document.createDocumentFragment();
            state.nodes.forEach(function (node) {
                if (node.type === 'group') {
                    fragment.append(groupNode(node));
                } else if (node.type === 'separator') {
                    fragment.append(separatorNode(node));
                } else {
                    fragment.append(itemRow(node.id, false));
                }
            });
            fragment.append(h('li', {
                class: 'cms-am-dropend cms-am-dropend--root' + (state.nodes.length === 0 ? ' is-empty' : ''),
                'data-am-dropend': '',
                text: state.nodes.length === 0 ? 'Das Layout ist leer – Einträge aus der Liste hierher ziehen oder mit + hinzufügen.' : 'Ans Ende der ersten Ebene ziehen'
            }));
            el.tree.replaceChildren(fragment);
            if (restore) {
                focusRow(el.tree, restore.key, restore.act);
            }
            pruneExpanded();
            updateExpandAll();
        }

        function searchText(value) {
            return String(value || '').toLocaleLowerCase('de-DE').normalize('NFKD').replace(/[\u0300-\u036f]/g, '');
        }

        function renderAvailable() {
            var restore = focusInfo(el.available);
            var placed = placement();
            var query = searchText(el.search ? el.search.value : '').trim();
            var areas = new Map();
            var total = 0;
            var shown = 0;

            items.forEach(function (item) {
                if (placed.has(item.id)) {
                    return;
                }
                total++;
                if (query !== '' && searchText(itemLabel(item.id) + ' ' + item.label + ' ' + item.id + ' ' + (item.route || '') + ' ' + (item.area_label || '')).indexOf(query) === -1) {
                    return;
                }
                shown++;
                if (!areas.has(item.area)) {
                    areas.set(item.area, { label: item.area_label || 'Weitere', items: [] });
                }
                areas.get(item.area).items.push(item);
            });

            var fragment = document.createDocumentFragment();
            areas.forEach(function (area) {
                var list = h('ul', { class: 'cms-am-avail-list' });
                area.items.forEach(function (item) {
                    var label = itemLabel(item.id);
                    var hidden = Boolean(state.hidden[item.id]);
                    var locked = isLocked(item.id);
                    var chips = [];
                    if (hidden) {
                        chips.push(chip('Ausgeblendet', 'muted', 'Wird nicht automatisch eingeordnet.'));
                    }
                    if (item.reason && reasons[item.reason]) {
                        chips.push(chip(reasons[item.reason], 'warning', 'Derzeit nicht sichtbar: ' + reasons[item.reason] + '.'));
                    }
                    if (item.external) {
                        chips.push(chip('Link', 'info', 'Verweist auf eine Seite außerhalb des Adminbereichs.'));
                    }
                    list.append(h('li', {
                        class: 'cms-am-avail' + (hidden ? ' is-muted' : '') + (item.reason ? ' is-unavailable' : ''),
                        'data-am-key': 'avail:' + item.id,
                        draggable: 'true'
                    }, [
                        h('span', { class: 'cms-am-row__handle', 'aria-hidden': 'true' }, [h('i', { class: 'ti ti-grip-vertical' })]),
                        iconNode(itemIcon(item.id), 'cms-am-row__icon'),
                        h('span', { class: 'cms-am-avail__text' }, [
                            h('span', { class: 'cms-am-row__label', text: label }),
                            h('span', { class: 'cms-am-row__meta', text: item.route || item.id })
                        ]),
                        chips.length ? h('span', { class: 'cms-am-row__chips' }, chips) : null,
                        h('span', { class: 'cms-am-row__actions' }, [
                            h('button', {
                                type: 'button',
                                class: 'cms-am-icon-btn',
                                'data-am-act': 'hide',
                                'aria-label': quote(label) + ' ausblenden',
                                'aria-pressed': hidden ? 'true' : 'false',
                                title: hidden ? 'Ausgeblendet – erscheint nicht im Menü. Klicken zum Einblenden.' : 'Wird automatisch eingeordnet. Klicken zum Ausblenden.',
                                disabled: locked
                            }, [h('i', { class: 'ti ' + (hidden ? 'ti-eye-off' : 'ti-eye'), 'aria-hidden': 'true' })]),
                            iconButton('add', 'ti-plus', quote(label) + ' hinzufügen', false, 'Hinzufügen (Ziel: „Hinzufügen zu“)')
                        ])
                    ]));
                });
                fragment.append(h('section', { class: 'cms-am-area' }, [
                    h('h4', { class: 'cms-am-area__title' }, [area.label, h('span', { class: 'cms-am-area__count', text: String(area.items.length) })]),
                    list
                ]));
            });
            if (total === 0) {
                fragment.append(h('p', { class: 'cms-am-empty', text: 'Alle Einträge sind im Layout platziert.' }));
            } else if (shown === 0) {
                fragment.append(h('p', { class: 'cms-am-empty', text: 'Keine Treffer für diese Suche.' }));
            }

            el.available.replaceChildren(fragment);
            if (el.availableCount) {
                el.availableCount.textContent = total + ' nicht platziert';
            }
            if (restore) {
                focusRow(el.available, restore.key, restore.act);
            }
        }

        function fillSelect(select, options, value) {
            if (!select) {
                return;
            }
            var fragment = document.createDocumentFragment();
            options.forEach(function (option) {
                fragment.append(h('option', { value: option[0], text: option[1] }));
            });
            select.replaceChildren(fragment);
            select.value = options.some(function (option) { return option[0] === value; }) ? value : '';
        }

        function renderSelects() {
            var groupOptions = groups().map(function (group) { return [group.id, group.label || group.id]; });
            var current = el.target ? el.target.value : '';
            fillSelect(el.target, [['', 'Erste Ebene (ans Ende)']].concat(groupOptions.map(function (option) {
                return [option[0], 'Gruppe ' + quote(option[1])];
            })), current);
            if (el.base) {
                el.base.value = state.base;
            }
            fillSelect(el.fallback, [['', 'Keine – eigene Gruppen je Plugin']].concat(groupOptions), state.fallback_group);
            fillSelect(el.root, [['', 'Keine – erste Kachelgruppe']].concat(groups().filter(function (group) {
                return group.display === 'tiles';
            }).map(function (group) { return [group.id, group.label || group.id]; })), state.root_group);
        }

        function field(id, label, control, hint, extra) {
            return h('div', { class: 'mb-3' }, [
                h('label', { class: 'form-label', for: id, text: label }),
                control,
                extra || null,
                hint ? h('div', { class: 'form-hint', id: id + '-hint', text: hint }) : null
            ]);
        }

        function textInput(id, fieldName, value, options) {
            return h('input', {
                type: 'text',
                class: 'form-control',
                id: id,
                value: value,
                placeholder: options.placeholder || null,
                maxlength: options.maxlength || null,
                list: options.list || null,
                spellcheck: options.spellcheck === false ? 'false' : null,
                autocomplete: 'off',
                'data-am-field': fieldName,
                'aria-describedby': options.hint ? id + '-hint' : null
            });
        }

        function iconField(id, value, placeholder) {
            return field(id, 'Icon', textInput(id, 'icon', value, {
                placeholder: placeholder,
                maxlength: 63,
                list: 'cms-am-icon-list',
                spellcheck: false,
                hint: true
            }), 'Tabler-Icon-Name, z. B. „file-text“ (tabler.io/icons). Leer = Standard.', h('div', {
                class: 'invalid-feedback',
                text: 'Nur Kleinbuchstaben, Ziffern und Bindestriche.'
            }));
        }

        function renderInspector() {
            var body = [];
            var loc = selectedKey ? locate(selectedKey) : null;

            if (!loc) {
                body.push(h('p', { class: 'cms-am-hint mb-0', text: 'Einen Eintrag, eine Gruppe oder einen Abschnitt im Layout auswählen, um Bezeichnung, Icon und Sichtbarkeit zu bearbeiten.' }));
                el.inspector.replaceChildren.apply(el.inspector, body);
                return;
            }

            if (loc.kind === 'item') {
                var id = loc.id;
                var item = itemById.get(id);
                var locked = isLocked(id);
                var defaultLabel = item ? item.label : id;
                var defaultIcon = item && item.icon && item.icon.type === 'tabler' ? item.icon.name : '';
                var chips = itemChips(id);
                body.push(
                    h('div', { class: 'cms-am-inspector__head' }, [
                        iconNode(itemIcon(id), 'cms-am-inspector__icon'),
                        h('div', { class: 'cms-am-inspector__heading' }, [
                            h('div', { class: 'cms-am-inspector__title', 'data-am-inspector-title': '', text: itemLabel(id) }),
                            h('code', { class: 'cms-am-inspector__id', text: id })
                        ])
                    ]),
                    item && item.route ? h('p', { class: 'cms-am-inspector__route' }, ['Adresse: ', h('code', { text: item.route })]) : null,
                    chips.length ? h('div', { class: 'cms-am-row__chips mb-3' }, chips) : null,
                    field('cms-am-insp-label', 'Bezeichnung', textInput('cms-am-insp-label', 'label', state.labels[id] || '', {
                        placeholder: defaultLabel,
                        maxlength: limits.label,
                        hint: true
                    }), 'Leer lassen für die Standardbezeichnung ' + quote(defaultLabel) + '.'),
                    iconField('cms-am-insp-icon', state.icons[id] || '', defaultIcon),
                    h('div', { class: 'mb-3' }, [
                        h('label', { class: 'form-check form-switch' }, [
                            h('input', {
                                type: 'checkbox',
                                class: 'form-check-input',
                                'data-am-field': 'hidden',
                                checked: Boolean(state.hidden[id]),
                                disabled: locked,
                                'aria-describedby': 'cms-am-insp-hidden-hint'
                            }),
                            h('span', { class: 'form-check-label', text: 'Im Menü ausblenden' })
                        ]),
                        h('div', {
                            class: 'form-hint',
                            id: 'cms-am-insp-hidden-hint',
                            text: locked ? 'Gesperrte Einträge bleiben immer sichtbar.' : 'Die Seite bleibt erreichbar, erscheint aber nicht in der Seitenleiste.'
                        })
                    ]),
                    field('cms-am-insp-position', 'Platz', (function () {
                        var select = h('select', { class: 'form-select', id: 'cms-am-insp-position', 'data-am-field': 'position' });
                        fillSelect(select, [['', 'Erste Ebene']].concat(groups().map(function (group) {
                            return [group.id, 'Gruppe ' + quote(group.label || group.id)];
                        })), loc.group ? loc.group.id : '');
                        return select;
                    }()), null),
                    h('button', {
                        type: 'button',
                        class: 'btn btn-outline-danger w-100',
                        'data-am-act': 'remove-selected',
                        disabled: locked,
                        title: locked ? 'Gesperrte Einträge können nicht entfernt werden.' : null
                    }, [h('i', { class: 'ti ti-x me-1', 'aria-hidden': 'true' }), 'Aus dem Layout entfernen']),
                    locked ? null : h('p', { class: 'cms-am-hint mt-2 mb-0', text: 'Entfernte Einträge ordnet das CMS automatisch ein. Zum Verbergen „Im Menü ausblenden“ nutzen.' })
                );
            } else if (loc.node.type === 'group') {
                var group = loc.node;
                body.push(
                    h('div', { class: 'cms-am-inspector__head' }, [
                        iconNode(group.icon, 'cms-am-inspector__icon'),
                        h('div', { class: 'cms-am-inspector__heading' }, [
                            h('div', { class: 'cms-am-inspector__title', 'data-am-inspector-title': '', text: group.label || group.id }),
                            h('code', { class: 'cms-am-inspector__id', text: group.id })
                        ])
                    ]),
                    field('cms-am-insp-label', 'Bezeichnung', textInput('cms-am-insp-label', 'label', group.label, {
                        maxlength: limits.label,
                        hint: true
                    }), 'Pflichtfeld, höchstens ' + limits.label + ' Zeichen.', h('div', { class: 'invalid-feedback', text: 'Bitte eine Bezeichnung eingeben – bis dahin bleibt die bisherige erhalten.' })),
                    iconField('cms-am-insp-icon', group.icon === 'folder' ? '' : group.icon, 'folder'),
                    h('fieldset', { class: 'mb-3' }, [
                        h('legend', { class: 'form-label', text: 'Darstellung' }),
                        h('label', { class: 'form-check' }, [
                            h('input', { type: 'radio', class: 'form-check-input', name: 'cms-am-insp-display', value: 'menu', 'data-am-field': 'display', checked: group.display !== 'tiles' }),
                            h('span', { class: 'form-check-label', text: 'Aufklappbare Gruppe in der Seitenleiste' })
                        ]),
                        h('label', { class: 'form-check mb-0' }, [
                            h('input', { type: 'radio', class: 'form-check-input', name: 'cms-am-insp-display', value: 'tiles', 'data-am-field': 'display', checked: group.display === 'tiles' }),
                            h('span', { class: 'form-check-label', text: 'Kachelseite (ein Link, Einträge als Kacheln)' })
                        ])
                    ]),
                    h('p', { class: 'cms-am-hint', text: plural(group.items.length, 'Eintrag', 'Einträge') + '. Löschen gibt die Einträge wieder frei; gesperrte bleiben auf der ersten Ebene.' }),
                    h('button', { type: 'button', class: 'btn btn-outline-danger w-100', 'data-am-act': 'remove-selected' }, [
                        h('i', { class: 'ti ti-trash me-1', 'aria-hidden': 'true' }), 'Gruppe löschen'
                    ])
                );
            } else {
                var separator = loc.node;
                body.push(
                    h('div', { class: 'cms-am-inspector__head' }, [
                        iconNode('separator-horizontal', 'cms-am-inspector__icon'),
                        h('div', { class: 'cms-am-inspector__heading' }, [
                            h('div', { class: 'cms-am-inspector__title', 'data-am-inspector-title': '', text: separator.label || 'Abschnitt ohne Überschrift' }),
                            h('code', { class: 'cms-am-inspector__id', text: separator.id })
                        ])
                    ]),
                    field('cms-am-insp-label', 'Überschrift', textInput('cms-am-insp-label', 'label', separator.label, {
                        maxlength: limits.label,
                        hint: true
                    }), 'Mit Überschrift entsteht ein aufklappbarer Abschnitt, ohne Überschrift eine Trennlinie.'),
                    h('button', { type: 'button', class: 'btn btn-outline-danger w-100', 'data-am-act': 'remove-selected' }, [
                        h('i', { class: 'ti ti-x me-1', 'aria-hidden': 'true' }), 'Abschnitt entfernen'
                    ])
                );
            }

            el.inspector.replaceChildren.apply(el.inspector, body.filter(Boolean));
        }

        function updateSelection() {
            el.tree.querySelectorAll('.cms-am-row').forEach(function (row) {
                var selected = row.getAttribute('data-am-key') === selectedKey;
                row.classList.toggle('is-selected', selected);
                var main = row.querySelector('[data-am-act="select"]');
                if (main) {
                    if (selected) {
                        main.setAttribute('aria-current', 'true');
                    } else {
                        main.removeAttribute('aria-current');
                    }
                }
            });
        }

        function updateMeta() {
            if (el.layoutCount) {
                var count = countNodes();
                el.layoutCount.textContent = count + ' / ' + limits.nodes + ' Elemente';
                el.layoutCount.classList.toggle('is-limit', count >= limits.nodes);
            }
            dirty = stateJson() !== baselineJson;
            if (el.dirty) {
                el.dirty.hidden = !dirty;
                el.dirty.classList.toggle('is-visible', dirty);
            }
            if (el.discard) {
                el.discard.disabled = !dirty;
            }
            form.classList.toggle('is-dirty', dirty);
        }

        /** Nach jeder Änderung: alles neu zeichnen (Inspektor optional), Zähler, Änderungsstatus, Vorschau. */
        function changed(options) {
            sanitise();
            renderTree();
            renderAvailable();
            renderSelects();
            if (!options || options.inspector !== false) {
                renderInspector();
            }
            updateMeta();
            schedulePreview(false);
        }

        function announce(message) {
            if (!el.status) {
                return;
            }
            el.status.textContent = '';
            window.setTimeout(function () {
                el.status.textContent = message;
            }, 40);
        }

        function loadState(next, message) {
            state = next;
            selectedKey = null;
            importSnapshot = null;
            importWarnings = [];
            changed();
            if (message) {
                announce(message);
            }
        }

        // ── Vorschau ──────────────────────────────────────────────────────

        function setPreviewState(kind, text) {
            if (!el.previewState) {
                return;
            }
            el.previewState.textContent = text;
            el.previewState.className = 'cms-am-preview-state' + (kind ? ' is-' + kind : '');
        }

        function setPreviewMessages(messages, isError) {
            if (!el.previewWarnings) {
                return;
            }
            var list = (Array.isArray(messages) ? messages : []).filter(function (message) {
                return typeof message === 'string' && message !== '';
            });
            el.previewWarnings.replaceChildren.apply(el.previewWarnings, list.slice(0, 25).map(function (message) {
                return h('li', { text: message });
            }));
            el.previewWarnings.hidden = list.length === 0;
            el.previewWarnings.classList.toggle('is-error', Boolean(isError));
        }

        function postPreview(layoutJson, signal) {
            var body = new FormData();
            body.append('action', 'preview');
            body.append('csrf_token', String(data.previewToken || ''));
            body.append('scope', String(data.scope || ''));
            body.append('layout', layoutJson);

            return fetch(String(data.endpoint || ''), {
                method: 'POST',
                body: body,
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                signal: signal
            }).then(function (response) {
                var type = response.headers.get('Content-Type') || '';
                if (type.indexOf('application/json') === -1) {
                    return { status: response.status, json: { success: false, message: 'Keine gültige Antwort – ist die Sitzung abgelaufen? Bitte die Seite neu laden.' } };
                }
                return response.json().then(function (json) {
                    return { status: response.status, json: json && typeof json === 'object' ? json : {} };
                });
            });
        }

        function schedulePreview(immediate) {
            window.clearTimeout(preview.timer);
            setPreviewState('pending', 'Aktualisiere …');
            preview.timer = window.setTimeout(requestPreview, immediate ? 0 : 400);
        }

        function requestPreview() {
            if (preview.controller) {
                preview.controller.abort();
            }
            preview.controller = typeof AbortController === 'function' ? new AbortController() : null;
            var seq = ++preview.seq;

            postPreview(stateJson(), preview.controller ? preview.controller.signal : undefined).then(function (result) {
                if (seq !== preview.seq) {
                    return;
                }
                var json = result.json;
                if (result.status === 200 && json.success) {
                    try {
                        el.preview.innerHTML = String(json.html || '');
                    } catch (error) {
                        el.preview.replaceChildren(h('p', { class: 'cms-am-preview__error', text: 'Die Vorschau konnte nicht angezeigt werden.' }));
                    }
                    var topLevel = Number(json.top_level) || 0;
                    setPreviewState('ok', plural(topLevel, 'Hauptpunkt', 'Hauptpunkte'));
                    // Hinweise aus dem Import (z. B. übersprungene Einträge) bleiben bis zum nächsten Laden sichtbar.
                    var current = Array.isArray(json.warnings) ? json.warnings : [];
                    var fromImport = importWarnings.filter(function (message) {
                        return current.indexOf(message) === -1;
                    }).map(function (message) {
                        return 'Import: ' + message;
                    });
                    setPreviewMessages(fromImport.concat(current), false);
                    return;
                }
                setPreviewState('error', result.status === 422 ? 'Layout ungültig' : 'Nicht verfügbar');
                var messages = [].concat(Array.isArray(json.errors) ? json.errors : [], Array.isArray(json.warnings) ? json.warnings : []);
                setPreviewMessages(messages.length ? messages : [json.message || 'Die Vorschau ist nicht verfügbar (HTTP ' + result.status + ').'], true);
            }).catch(function (error) {
                if (error && error.name === 'AbortError') {
                    return;
                }
                if (seq === preview.seq) {
                    setPreviewState('error', 'Nicht verfügbar');
                    setPreviewMessages(['Die Vorschau konnte nicht geladen werden. Bitte die Verbindung prüfen.'], true);
                }
            });
        }

        // ── Aktionen ──────────────────────────────────────────────────────

        function selectKey(key) {
            selectedKey = key;
            updateSelection();
            renderInspector();
        }

        function stepMove(key, direction, focusAct) {
            var target = stepTarget(key, direction);
            if (!target || !moveTo(key, target)) {
                return;
            }
            reveal(key);
            changed();
            focusRow(el.tree, key, focusAct);
            announce(quote(keyLabel(key)) + ' verschoben: ' + positionText(key) + '.');
        }

        function removeKey(key) {
            var loc = locate(key);
            if (!loc) {
                return false;
            }
            var label = keyLabel(key);
            if (loc.kind === 'item') {
                if (isLocked(loc.id)) {
                    announce(quote(label) + ' ist gesperrt und kann nicht entfernt werden.');
                    return false;
                }
                if (loc.group) {
                    loc.group.items.splice(loc.index, 1);
                } else {
                    state.nodes.splice(loc.index, 1);
                }
                announce(quote(label) + ' entfernt. Ohne „Ausblenden“ ordnet das CMS den Eintrag automatisch ein.');
            } else if (loc.node.type === 'group') {
                var keep = loc.node.items.filter(isLocked);
                var released = loc.node.items.length - keep.length;
                state.nodes.splice.apply(state.nodes, [loc.index, 1].concat(keep.map(function (id) {
                    return { type: 'item', id: id };
                })));
                announce('Gruppe ' + quote(label) + ' gelöscht. ' + plural(released, 'Eintrag ist', 'Einträge sind') + ' wieder verfügbar'
                    + (keep.length ? ', ' + plural(keep.length, 'gesperrter Eintrag steht', 'gesperrte Einträge stehen') + ' jetzt auf der ersten Ebene.' : '.'));
            } else {
                state.nodes.splice(loc.index, 1);
                announce(quote(label) + ' entfernt.');
            }
            if (selectedKey === key) {
                selectedKey = null;
            }
            changed();

            return true;
        }

        /** Nach dem Entfernen den Fokus auf die nächstliegende sichtbare Zeile setzen, sonst auf die Liste. */
        function removeWithFocus(key) {
            var index = visibleRows().findIndex(function (row) { return row.getAttribute('data-am-key') === key; });
            if (!removeKey(key)) {
                return;
            }
            var after = visibleRows();
            var next = after[Math.min(Math.max(index, 0), after.length - 1)];
            if (next) {
                focusRow(el.tree, next.getAttribute('data-am-key'), 'select');
            } else if (el.search) {
                el.search.focus();
            }
        }

        function addItem(id) {
            var targetGroup = el.target && el.target.value !== '' ? findGroup(el.target.value) : null;
            var target = targetGroup ? { group: targetGroup.id, index: targetGroup.items.length } : { group: null, index: state.nodes.length };
            if (!moveTo('avail:' + id, target)) {
                return false;
            }
            reveal('item:' + id);
            changed();
            announce(quote(itemLabel(id)) + ' hinzugefügt: ' + positionText('item:' + id) + '.');

            return true;
        }

        function toggleHidden(id, force) {
            if (isLocked(id)) {
                return;
            }
            var hide = typeof force === 'boolean' ? force : !state.hidden[id];
            if (hide) {
                state.hidden[id] = true;
            } else {
                delete state.hidden[id];
            }
            announce(quote(itemLabel(id)) + (hide ? ' wird ausgeblendet.' : ' wird wieder angezeigt.'));
        }

        function addNode(type) {
            if (countNodes() >= limits.nodes) {
                toast('warning', 'Das Layout darf höchstens ' + limits.nodes + ' Elemente enthalten.');
                return;
            }
            var node = type === 'group'
                ? { type: 'group', id: uniqueNodeId('group'), label: 'Neue Gruppe', icon: 'folder', display: 'menu', items: [] }
                : { type: 'separator', id: uniqueNodeId('sep'), label: '' };
            state.nodes.splice(insertIndexAfterSelection(), 0, node);
            selectedKey = 'node:' + node.id;
            if (type === 'group') {
                expanded.add(node.id);
                storeExpanded();
            }
            changed();
            announce((type === 'group' ? 'Gruppe' : 'Abschnitt') + ' angelegt: ' + positionText(selectedKey) + '.');
            var input = el.inspector.querySelector('#cms-am-insp-label');
            if (input) {
                input.focus();
                input.select();
            }
            var row = findRow(el.tree, selectedKey);
            if (row && typeof row.scrollIntoView === 'function') {
                row.scrollIntoView({ block: 'nearest' });
            }
        }

        // Baum: Auswahl, Verschieben, Entfernen
        el.tree.addEventListener('click', function (event) {
            var target = event.target instanceof Element ? event.target : null;
            var row = target ? target.closest('[data-am-key]') : null;
            if (!row) {
                return;
            }
            var key = row.getAttribute('data-am-key');
            var button = target.closest('[data-am-act]');
            var act = button && !button.disabled ? button.getAttribute('data-am-act') : (button ? '' : 'select');
            if (act === 'select') {
                selectKey(key);
            } else if (act === 'toggle') {
                var groupId = splitKey(key).id;
                setExpanded(groupId, !expanded.has(groupId));
            } else if (act === 'up' || act === 'down') {
                stepMove(key, act === 'up' ? -1 : 1, act);
            } else if (act === 'remove') {
                removeWithFocus(key);
            }
        });

        el.tree.addEventListener('keydown', function (event) {
            if (!event.altKey || (event.key !== 'ArrowUp' && event.key !== 'ArrowDown')) {
                return;
            }
            var row = event.target instanceof Element ? event.target.closest('[data-am-key]') : null;
            if (!row) {
                return;
            }
            event.preventDefault();
            stepMove(row.getAttribute('data-am-key'), event.key === 'ArrowUp' ? -1 : 1, 'select');
        });

        // Verfügbare Einträge: hinzufügen, ausblenden, suchen
        el.available.addEventListener('click', function (event) {
            var button = event.target instanceof Element ? event.target.closest('[data-am-act]') : null;
            var row = button ? button.closest('[data-am-key]') : null;
            if (!button || button.disabled || !row) {
                return;
            }
            var id = row.getAttribute('data-am-key').slice(6);
            if (button.getAttribute('data-am-act') === 'add') {
                var buttons = Array.prototype.slice.call(el.available.querySelectorAll('[data-am-act="add"]'));
                var index = buttons.indexOf(button);
                if (addItem(id)) {
                    var after = el.available.querySelectorAll('[data-am-act="add"]');
                    var next = after[Math.min(index, after.length - 1)];
                    if (next) {
                        next.focus();
                    } else if (el.search) {
                        el.search.focus();
                    }
                }
            } else if (button.getAttribute('data-am-act') === 'hide') {
                toggleHidden(id);
                changed({ inspector: false });
            }
        });

        if (el.search) {
            el.search.addEventListener('input', renderAvailable);
        }

        // Werkzeugleiste
        if (el.addGroup) {
            el.addGroup.addEventListener('click', function () { addNode('group'); });
        }
        if (el.addSeparator) {
            el.addSeparator.addEventListener('click', function () { addNode('separator'); });
        }
        if (el.expandAll) {
            el.expandAll.addEventListener('click', function () {
                var all = groups();
                var open = !all.every(function (group) { return expanded.has(group.id); });
                all.forEach(function (group) {
                    if (open) {
                        expanded.add(group.id);
                    } else {
                        expanded.delete(group.id);
                    }
                    applyExpanded(group.id);
                });
                storeExpanded();
                updateExpandAll();
                announce(open ? 'Alle Gruppen aufgeklappt.' : 'Alle Gruppen eingeklappt.');
            });
        }

        // Layout-Einstellungen
        if (el.base) {
            el.base.addEventListener('change', function () {
                state.base = el.base.value === 'structured' ? 'structured' : 'classic';
                changed({ inspector: false });
            });
        }
        if (el.fallback) {
            el.fallback.addEventListener('change', function () {
                state.fallback_group = el.fallback.value;
                changed({ inspector: false });
            });
        }
        if (el.root) {
            el.root.addEventListener('change', function () {
                state.root_group = el.root.value;
                changed({ inspector: false });
            });
        }

        // Eigenschaften
        function updateInspectorHead(title, icon) {
            var titleNode = el.inspector.querySelector('[data-am-inspector-title]');
            if (titleNode && title !== null) {
                titleNode.textContent = title;
            }
            var iconHolder = el.inspector.querySelector('.cms-am-inspector__icon');
            if (iconHolder && icon !== null) {
                iconHolder.replaceWith(iconNode(icon, 'cms-am-inspector__icon'));
            }
        }

        el.inspector.addEventListener('input', function (event) {
            var input = event.target;
            var loc = selectedKey ? locate(selectedKey) : null;
            if (!(input instanceof HTMLInputElement) || !loc) {
                return;
            }
            var fieldName = input.getAttribute('data-am-field');

            if (fieldName === 'label') {
                var value = input.value.replace(/[\u0000-\u001F\u007F]+/g, ' ').replace(/\s+/g, ' ').trim().slice(0, limits.label);
                if (loc.kind === 'item') {
                    var item = itemById.get(loc.id);
                    if (value === '' || (item && value === item.label)) {
                        delete state.labels[loc.id];
                    } else {
                        state.labels[loc.id] = value;
                    }
                    updateInspectorHead(itemLabel(loc.id), null);
                } else if (loc.node.type === 'group') {
                    input.classList.toggle('is-invalid', value === '');
                    if (value === '') {
                        return;
                    }
                    loc.node.label = value;
                    updateInspectorHead(value, null);
                } else {
                    loc.node.label = value;
                    updateInspectorHead(value || 'Abschnitt ohne Überschrift', null);
                }
                changed({ inspector: false });
                return;
            }

            if (fieldName === 'icon') {
                var icon = input.value.trim().toLowerCase().replace(/^ti-/, '');
                var valid = icon === '' || iconPattern.test(icon);
                input.classList.toggle('is-invalid', !valid);
                if (!valid) {
                    return;
                }
                if (loc.kind === 'item') {
                    if (icon === '') {
                        delete state.icons[loc.id];
                    } else {
                        state.icons[loc.id] = icon;
                    }
                    updateInspectorHead(null, itemIcon(loc.id));
                } else if (loc.node.type === 'group') {
                    loc.node.icon = icon || 'folder';
                    updateInspectorHead(null, loc.node.icon);
                }
                changed({ inspector: false });
            }
        });

        el.inspector.addEventListener('change', function (event) {
            var input = event.target;
            var loc = selectedKey ? locate(selectedKey) : null;
            if (!loc || !(input instanceof HTMLElement)) {
                return;
            }
            var fieldName = input.getAttribute('data-am-field');

            if (fieldName === 'hidden' && input instanceof HTMLInputElement && loc.kind === 'item') {
                toggleHidden(loc.id, input.checked);
                changed({ inspector: false });
            } else if (fieldName === 'display' && input instanceof HTMLInputElement && loc.kind === 'node' && loc.node.type === 'group') {
                loc.node.display = input.value === 'tiles' ? 'tiles' : 'menu';
                changed({ inspector: false });
                announce(quote(loc.node.label) + (loc.node.display === 'tiles' ? ' wird als Kachelseite angezeigt.' : ' wird als aufklappbare Gruppe angezeigt.'));
            } else if (fieldName === 'position' && input instanceof HTMLSelectElement && loc.kind === 'item') {
                var groupId = input.value;
                if ((loc.group ? loc.group.id : '') === groupId) {
                    return;
                }
                var group = groupId !== '' ? findGroup(groupId) : null;
                var key = selectedKey;
                if (moveTo(key, group ? { group: group.id, index: group.items.length } : { group: null, index: state.nodes.length })) {
                    reveal(key);
                    changed();
                    var select = el.inspector.querySelector('[data-am-field="position"]');
                    if (select) {
                        select.focus();
                    }
                    announce(quote(keyLabel(key)) + ' verschoben: ' + positionText(key) + '.');
                }
            }
        });

        el.inspector.addEventListener('click', function (event) {
            var button = event.target instanceof Element ? event.target.closest('[data-am-act="remove-selected"]') : null;
            if (button && !button.disabled && selectedKey) {
                removeWithFocus(selectedKey);
            }
        });

        // ── Ziehen und Ablegen ────────────────────────────────────────────

        function isItemKey(key) {
            return key.indexOf('item:') === 0 || key.indexOf('avail:') === 0;
        }

        function computeDrop(event) {
            var target = event.target instanceof Element ? event.target : null;
            if (!target || !drag) {
                return null;
            }

            var availableZone = target.closest('[data-am-dropzone="available"]');
            if (availableZone) {
                if (drag.key.indexOf('avail:') === 0) {
                    return null;
                }
                var dragged = locate(drag.key);
                if (!dragged || (dragged.kind === 'item' && isLocked(dragged.id)) || (dragged.kind === 'node' && dragged.node.type === 'group')) {
                    return null;
                }
                return { remove: true, el: availableZone, mode: 'remove' };
            }
            if (!el.tree.contains(target)) {
                return null;
            }

            var dragIsItem = isItemKey(drag.key);
            var end = target.closest('[data-am-dropend]');
            if (end) {
                var endGroupId = end.getAttribute('data-am-dropend');
                if (endGroupId === '') {
                    return { group: null, index: state.nodes.length, el: end, mode: 'into' };
                }
                var endGroup = findGroup(endGroupId);
                if (!endGroup) {
                    return null;
                }
                if (dragIsItem) {
                    return { group: endGroup.id, index: endGroup.items.length, el: end, mode: 'into' };
                }
                return { group: null, index: state.nodes.indexOf(endGroup) + 1, el: end.closest('[data-am-node]'), mode: 'after' };
            }

            var row = target.closest('.cms-am-row');
            if (!row) {
                return null;
            }
            var key = row.getAttribute('data-am-key');
            if (key === drag.key) {
                return null;
            }
            var loc = locate(key);
            if (!loc) {
                return null;
            }
            var rect = row.getBoundingClientRect();
            var ratio = (event.clientY - rect.top) / Math.max(1, rect.height);
            var nodeEl = row.closest('[data-am-node]');

            if (loc.kind === 'node' && loc.node.type === 'group') {
                if (dragIsItem) {
                    return ratio < 0.3
                        ? { group: null, index: loc.index, el: nodeEl, mode: 'before' }
                        : { group: loc.id, index: 0, el: row, mode: 'into' };
                }
                return ratio < 0.5
                    ? { group: null, index: loc.index, el: nodeEl, mode: 'before' }
                    : { group: null, index: loc.index + 1, el: nodeEl, mode: 'after' };
            }
            if (loc.group) {
                if (dragIsItem) {
                    return ratio < 0.5
                        ? { group: loc.group.id, index: loc.index, el: nodeEl, mode: 'before' }
                        : { group: loc.group.id, index: loc.index + 1, el: nodeEl, mode: 'after' };
                }
                var groupIndex = state.nodes.indexOf(loc.group);
                var groupEl = nodeEl ? nodeEl.parentElement.closest('[data-am-node]') : null;
                return loc.index < loc.group.items.length / 2
                    ? { group: null, index: groupIndex, el: groupEl, mode: 'before' }
                    : { group: null, index: groupIndex + 1, el: groupEl, mode: 'after' };
            }

            return ratio < 0.5
                ? { group: null, index: loc.index, el: nodeEl, mode: 'before' }
                : { group: null, index: loc.index + 1, el: nodeEl, mode: 'after' };
        }

        function markDrop(drop) {
            if (dropMarked && (!drop || dropMarked.el !== drop.el || dropMarked.mode !== drop.mode)) {
                dropMarked.el.classList.remove('is-drop-before', 'is-drop-after', 'is-drop-into', 'is-drop-remove');
                dropMarked = null;
            }
            if (drop && drop.el && !dropMarked) {
                drop.el.classList.add('is-drop-' + drop.mode);
                dropMarked = drop;
            }
        }

        function clearExpandHover() {
            if (expandHover.timer) {
                window.clearTimeout(expandHover.timer);
            }
            expandHover.group = null;
            expandHover.timer = 0;
        }

        /** Hält man einen Eintrag kurz über einer eingeklappten Gruppe, klappt sie auf (Ablegen an genauer Position). */
        function trackExpandHover(drop) {
            var groupId = drop && drop.mode === 'into' && drop.group && !expanded.has(drop.group) ? drop.group : null;
            if (groupId === expandHover.group) {
                return;
            }
            clearExpandHover();
            if (groupId) {
                expandHover.group = groupId;
                expandHover.timer = window.setTimeout(function () {
                    if (drag && expandHover.group === groupId) {
                        setExpanded(groupId, true);
                    }
                    expandHover.group = null;
                    expandHover.timer = 0;
                }, 700);
            }
        }

        function endDrag() {
            markDrop(null);
            clearExpandHover();
            if (drag && drag.source) {
                drag.source.classList.remove('is-dragging');
            }
            drag = null;
            form.classList.remove('is-dragging');
        }

        form.addEventListener('dragstart', function (event) {
            var source = event.target instanceof Element ? event.target.closest('[data-am-key][draggable="true"]') : null;
            if (!source || !event.dataTransfer) {
                return;
            }
            drag = { key: source.getAttribute('data-am-key'), source: source };
            event.dataTransfer.effectAllowed = 'move';
            try {
                event.dataTransfer.setData('text/plain', drag.key);
            } catch (error) {
                // Ältere Browser: Daten sind nur für Firefox nötig.
            }
            window.requestAnimationFrame(function () {
                if (drag) {
                    source.classList.add('is-dragging');
                    form.classList.add('is-dragging');
                }
            });
        });

        form.addEventListener('dragover', function (event) {
            if (!drag) {
                return;
            }
            var drop = computeDrop(event);
            trackExpandHover(drop);
            if (!drop || !drop.el) {
                markDrop(null);
                if (event.dataTransfer) {
                    event.dataTransfer.dropEffect = 'none';
                }
                return;
            }
            event.preventDefault();
            if (event.dataTransfer) {
                event.dataTransfer.dropEffect = 'move';
            }
            markDrop(drop);
        });

        form.addEventListener('dragleave', function (event) {
            if (drag && (!event.relatedTarget || !form.contains(event.relatedTarget))) {
                markDrop(null);
                clearExpandHover();
            }
        });

        form.addEventListener('drop', function (event) {
            if (!drag) {
                return;
            }
            event.preventDefault();
            var drop = computeDrop(event);
            var key = drag.key;
            endDrag();
            if (!drop) {
                return;
            }
            if (drop.remove) {
                removeKey(key);
                return;
            }
            var finalKey = key.indexOf('avail:') === 0 ? 'item:' + key.slice(6) : key;
            if (moveTo(key, drop)) {
                reveal(finalKey);
                changed();
                announce(quote(keyLabel(finalKey)) + (key.indexOf('avail:') === 0 ? ' hinzugefügt: ' : ' verschoben: ') + positionText(finalKey) + '.');
            }
        });

        form.addEventListener('dragend', endDrag);

        // ── Import, Export, Ausgangslage, Verwerfen ───────────────────────

        function today() {
            var now = new Date();
            var pad = function (number) { return String(number).padStart(2, '0'); };

            return now.getFullYear() + '-' + pad(now.getMonth() + 1) + '-' + pad(now.getDate());
        }

        function guardDirty(title, confirmText, action) {
            if (!dirty) {
                action();
                return;
            }
            confirmAction({
                title: title,
                message: 'Nicht gespeicherte Änderungen gehen dabei verloren.',
                confirmText: confirmText,
                confirmClass: 'btn-primary',
                statusClass: 'bg-warning',
                onConfirm: action
            });
        }

        if (el.exportButton) {
            el.exportButton.addEventListener('click', function () {
                var payload = {
                    format: FORMAT,
                    format_version: FORMAT_VERSION,
                    exported_at: new Date().toISOString(),
                    cms_version: String(data.cmsVersion || ''),
                    scope: data.scope ? 'site' : 'global',
                    site_key: String(data.scope || ''),
                    layout: serialise(state)
                };
                var url = URL.createObjectURL(new Blob([JSON.stringify(payload, null, 4) + '\n'], { type: 'application/json' }));
                var link = h('a', { href: url, download: String(data.exportName || '365cms-admin-menu') + '-' + today() + '.json', hidden: true });
                document.body.append(link);
                link.click();
                link.remove();
                window.setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
                announce('Der aktuelle Stand wurde als JSON-Datei exportiert.');
            });
        }

        if (el.importInput) {
            el.importInput.addEventListener('change', function () {
                var file = el.importInput.files && el.importInput.files[0];
                el.importInput.value = '';
                if (!file) {
                    return;
                }
                if (file.size > limits.bytes) {
                    toast('danger', 'Die Datei ist größer als ' + Math.floor(limits.bytes / 1024) + ' KB.');
                    return;
                }
                // Erst prüfen, dann (bei ungespeicherten Änderungen) nachfragen – ungültige Dateien ändern nichts.
                file.text().then(function (text) {
                    return postPreview(text);
                }).then(function (result) {
                    var json = result.json;
                    if (result.status !== 200 || !json.success) {
                        var errors = [].concat(Array.isArray(json.errors) ? json.errors : []);
                        toast('danger', (json.message || 'Die Datei konnte nicht importiert werden.') + (errors.length ? ' ' + errors.join(' ') : ''));
                        return;
                    }
                    guardDirty('Layout importieren?', 'Importieren', function () {
                        var warnings = (Array.isArray(json.warnings) ? json.warnings : []).filter(function (message) {
                            return typeof message === 'string' && message !== '';
                        });
                        loadState(normalise(json.layout), null);
                        importSnapshot = stateJson();
                        importWarnings = warnings;
                        toast(warnings.length ? 'warning' : 'success', 'Import geprüft' + (warnings.length ? ' – ' + plural(warnings.length, 'Hinweis', 'Hinweise') + ', siehe Hinweise unter der Vorschau' : '')
                            + '. Übernommen wird erst beim Speichern.');
                        announce('Import geladen. Übernommen wird erst beim Speichern.');
                    });
                }).catch(function () {
                    toast('danger', 'Die Datei konnte nicht gelesen oder geprüft werden.');
                });
            });
        }

        if (el.start) {
            el.start.addEventListener('change', function () {
                var choice = el.start.value;
                el.start.value = '';
                var layout = choice === 'stored' ? data.stored : (data.starts && data.starts[choice]) || null;
                if (!choice || !layout) {
                    return;
                }
                var names = { stored: 'gespeicherte Layout', classic: 'Profil „Klassisch“', structured: 'Profil „Strukturiert“', empty: 'leere Layout' };
                guardDirty('Ausgangslage laden?', 'Laden', function () {
                    loadState(normalise(layout), 'Geladen: ' + (names[choice] || choice) + '.');
                });
            });
        }

        var cleanLayout = data.stored || data.effective || (data.starts && data.starts.classic) || {};

        if (el.discard) {
            el.discard.addEventListener('click', function () {
                confirmAction({
                    title: 'Änderungen verwerfen?',
                    message: data.stored ? 'Der Editor lädt wieder das gespeicherte Layout.' : 'Der Editor lädt wieder das derzeit wirksame Menü.',
                    confirmText: 'Verwerfen',
                    confirmClass: 'btn-danger',
                    statusClass: 'bg-danger',
                    onConfirm: function () {
                        loadState(normalise(cleanLayout), 'Änderungen verworfen.');
                    }
                });
            });
        }

        // ── Speichern und Verlassen ───────────────────────────────────────

        // Enter in Such- und Eigenschaftsfeldern darf das Layout nicht versehentlich speichern.
        form.addEventListener('keydown', function (event) {
            var input = event.target;
            if (event.key === 'Enter' && input instanceof HTMLInputElement && input.type !== 'file' && input.type !== 'submit') {
                event.preventDefault();
            }
        });

        form.addEventListener('submit', function (event) {
            var json = stateJson();
            if (new Blob([json]).size > limits.bytes) {
                event.preventDefault();
                toast('danger', 'Das Layout ist größer als ' + Math.floor(limits.bytes / 1024) + ' KB und kann nicht gespeichert werden.');
                return;
            }
            el.layoutField.value = json;
            if (el.origin) {
                el.origin.value = importSnapshot !== null && importSnapshot === json ? 'import' : '';
            }
            submitting = true;
            if (el.save) {
                el.save.classList.add('btn-loading');
            }
        });

        // Nach den Formular-Handlern (z. B. Bestätigung aus admin.js): nur echte Absendungen zählen.
        document.addEventListener('submit', function (event) {
            if (event.target === el.resetForm && !event.defaultPrevented) {
                submitting = true;
            }
        });

        window.addEventListener('beforeunload', function (event) {
            if (!dirty || submitting) {
                return;
            }
            event.preventDefault();
            event.returnValue = '';
        });

        window.addEventListener('pageshow', function (event) {
            if (event.persisted) {
                submitting = false;
                if (el.save) {
                    el.save.classList.remove('btn-loading');
                }
            }
        });

        // ── Start ─────────────────────────────────────────────────────────

        if (el.iconList) {
            el.iconList.replaceChildren.apply(el.iconList, (Array.isArray(data.icons) ? data.icons : []).map(function (name) {
                return h('option', { value: name });
            }));
        }

        state = normalise(cleanLayout);
        baselineJson = stateJson();

        var startMessage = '';
        if (typeof data.draft === 'string' && data.draft !== '') {
            try {
                var draft = JSON.parse(data.draft);
                if (draft && typeof draft === 'object' && draft.layout && typeof draft.layout === 'object') {
                    draft = draft.layout;
                }
                state = normalise(draft);
                startMessage = 'Der zuletzt übermittelte, nicht gespeicherte Stand wurde wiederhergestellt.';
            } catch (error) {
                toast('warning', 'Der übermittelte Stand konnte nicht wiederhergestellt werden.');
            }
        } else if (data.start && data.starts && data.starts[data.start]) {
            state = normalise(data.starts[data.start]);
        }

        form.hidden = false;
        changed();
        schedulePreview(true);
        if (startMessage !== '') {
            announce(startMessage);
        }
    }

    function init() {
        initOverview();
        initEditor();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
}());
