/**
 * Formular-Baukasten (Admin → Inhalte → Formulare → Bearbeiten).
 *
 * Verwaltet die Feldliste eines Formulars im Browser: hinzufügen, bearbeiten, duplizieren, löschen und
 * sortieren (Ziehen und Ablegen, Pfeil-Schaltflächen, Alt + Pfeiltasten). Beim Speichern gehen die Felder als
 * JSON (`fields_json`) an den Server; maßgeblich ist dort `FormFieldTypes::normalizeFields()`.
 *
 * Konfiguration: <script type="application/json" id="cmsFormBuilderConfig"> (FormsModule::getEditData()).
 */
(function () {
    'use strict';

    var root = document.getElementById('cmsFormBuilder');
    var configElement = document.getElementById('cmsFormBuilderConfig');
    var form = document.querySelector('[data-form-builder-form]');
    if (!root || !configElement || !form) {
        return;
    }

    var config;
    try {
        config = JSON.parse(configElement.textContent || '{}');
    } catch (error) {
        return;
    }

    var t = config.i18n || {};
    var types = config.types || {};
    var staticTypes = config.staticTypes || [];
    var optionTypes = config.optionTypes || [];
    var maxFields = Number(config.maxFields) || 60;
    var fields = (Array.isArray(config.fields) ? config.fields : []).map(cloneField);
    var openIndex = -1;
    var dirty = false;
    var dragIndex = -1;
    var uid = 0;

    var list = root.querySelector('[data-fb-list]');
    var emptyHint = root.querySelector('[data-fb-empty]');
    var counter = root.querySelector('[data-fb-count]');
    var live = root.querySelector('[data-fb-live]');
    var typeSelect = document.getElementById('cmsFormBuilderType');
    var unsaved = form.querySelector('[data-fb-unsaved]');

    var hidden = document.createElement('input');
    hidden.type = 'hidden';
    hidden.name = 'fields_json';
    form.appendChild(hidden);

    var LENGTH_TYPES = ['text', 'tel', 'url', 'textarea', 'email', 'hidden'];
    var PATTERN_TYPES = ['text', 'tel', 'url', 'textarea', 'email'];
    var PLACEHOLDER_TYPES = ['text', 'email', 'tel', 'url', 'textarea', 'number', 'select'];
    var TYPE_ICONS = {
        text: 'forms', email: 'mail', tel: 'phone', url: 'link', textarea: 'align-left', number: 'number', date: 'calendar',
        select: 'selector', radio: 'circle-dot', checkbox: 'square-check', consent: 'shield-check', hidden: 'eye-off',
        file: 'paperclip', heading: 'heading', paragraph: 'text-caption'
    };

    // ── Hilfen ─────────────────────────────────────────────────────────

    function texts(value) {
        if (value && typeof value === 'object') {
            return { de: String(value.de || ''), en: String(value.en || '') };
        }
        return { de: String(value || ''), en: '' };
    }

    function cloneField(field) {
        var copy = JSON.parse(JSON.stringify(field || {}));
        copy.type = types[copy.type] ? copy.type : 'text';
        copy.name = String(copy.name || '');
        copy.label = texts(copy.label);
        copy.placeholder = texts(copy.placeholder);
        copy.help = texts(copy.help);
        copy.content = texts(copy.content);
        copy.required = !!copy.required;
        copy.width = String(copy.width || 'full');
        copy.role = String(copy.role || '');
        copy['default'] = String(copy['default'] || '');
        copy.css_class = String(copy.css_class || '');
        copy.privacy_url = String(copy.privacy_url || '');
        copy.level = Number(copy.level) || 3;
        copy.options = Array.isArray(copy.options) ? copy.options : [];
        copy.validation = copy.validation && typeof copy.validation === 'object' && !Array.isArray(copy.validation) ? copy.validation : {};
        return copy;
    }

    function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) {
            node.className = className;
        }
        if (text !== undefined && text !== null) {
            node.textContent = String(text);
        }
        return node;
    }

    function icon(name) {
        var node = el('i', 'ti ti-' + name);
        node.setAttribute('aria-hidden', 'true');
        return node;
    }

    function sprintf(template) {
        var args = Array.prototype.slice.call(arguments, 1);
        var index = 0;
        return String(template || '').replace(/%(?:(\d+)\$)?[sd]/g, function (match, position) {
            var value = position ? args[Number(position) - 1] : args[index++];
            return value === undefined || value === null ? '' : String(value);
        });
    }

    function announce(message) {
        if (live) {
            live.textContent = '';
            window.setTimeout(function () {
                live.textContent = message;
            }, 30);
        }
    }

    function isStatic(type) {
        return staticTypes.indexOf(type) !== -1;
    }

    function hasOptions(type) {
        return optionTypes.indexOf(type) !== -1;
    }

    function fieldTitle(field) {
        var source = isStatic(field.type) ? field.content : field.label;
        var title = (source.de || source.en || '').trim();
        if (!title && field.type === 'consent') {
            title = types.consent;
        }
        return title || t.untitled || '';
    }

    function slug(value) {
        var map = { 'ä': 'ae', 'ö': 'oe', 'ü': 'ue', 'ß': 'ss' };
        var name = String(value || '').toLowerCase().replace(/[äöüß]/g, function (char) {
            return map[char];
        }).replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '');
        if (name && !/^[a-z]/.test(name)) {
            name = 'feld_' + name;
        }
        return name.slice(0, 64);
    }

    function uniqueName(base, ignoreIndex) {
        var name = base || 'feld';
        var suffix = 2;
        var taken = function (candidate) {
            return fields.some(function (field, index) {
                return index !== ignoreIndex && field.name === candidate;
            });
        };
        while (taken(name)) {
            name = base.slice(0, 60) + '_' + suffix++;
        }
        return name;
    }

    function optionsToText(options) {
        return options.map(function (option) {
            var label = texts(option.label);
            var parts = [String(option.value || '')];
            if (label.de !== parts[0] || label.en) {
                parts.push(label.de);
            }
            if (label.en) {
                parts.push(label.en);
            }
            return parts.join(' | ');
        }).join('\n');
    }

    function textToOptions(text) {
        var options = [];
        String(text || '').split(/\r?\n/).forEach(function (line) {
            var parts = line.split('|').map(function (part) {
                return part.trim();
            });
            if (!parts[0] && !parts[1]) {
                return;
            }
            var value = parts[0] || parts[1];
            options.push({ value: value, label: { de: parts[1] || value, en: parts[2] || '' } });
        });
        return options.slice(0, Number(config.maxOptions) || 100);
    }

    function markDirty() {
        dirty = true;
        if (unsaved) {
            unsaved.hidden = false;
        }
    }

    function serialize() {
        hidden.value = JSON.stringify(fields);
    }

    // ── Aktionen ───────────────────────────────────────────────────────

    function addField(type) {
        if (fields.length >= maxFields) {
            window.cmsToast && window.cmsToast('warning', sprintf(t.maxFields, maxFields));
            return;
        }
        var field = cloneField({ type: type });
        if (type === 'consent') {
            field.required = true;
            field.name = uniqueName('privacy_consent', -1);
        } else if (type === 'email') {
            field.name = uniqueName('email', -1);
            field.label = { de: 'E-Mail', en: 'Email' };
        } else {
            field.name = uniqueName(type, -1);
        }
        if (type === 'file') {
            field.validation = { max_files: 1 };
        }
        field._autoName = !isStatic(type) && type !== 'consent' && type !== 'email';
        fields.push(field);
        openIndex = fields.length - 1;
        markDirty();
        render();
        announce(sprintf(t.added, types[type] || type));
        var first = list.querySelector('.cms-fb__item.is-open input, .cms-fb__item.is-open textarea');
        if (first) {
            first.focus();
        }
    }

    function moveField(from, to, focusSelector) {
        if (to < 0 || to >= fields.length || from === to) {
            return;
        }
        var moved = fields.splice(from, 1)[0];
        fields.splice(to, 0, moved);
        if (openIndex === from) {
            openIndex = to;
        } else if (openIndex !== -1) {
            if (from < openIndex && to >= openIndex) {
                openIndex--;
            } else if (from > openIndex && to <= openIndex) {
                openIndex++;
            }
        }
        markDirty();
        render();
        announce(sprintf(t.moved, fieldTitle(moved), to + 1, fields.length));
        var item = list.children[to];
        var target = item && focusSelector ? item.querySelector(focusSelector) : null;
        if (target) {
            target.focus();
        }
    }

    function removeField(index) {
        var field = fields[index];
        var run = function () {
            fields.splice(index, 1);
            openIndex = openIndex === index ? -1 : (openIndex > index ? openIndex - 1 : openIndex);
            markDirty();
            render();
            announce(sprintf(t.removed, fieldTitle(field)));
        };
        if (typeof window.cmsConfirm === 'function') {
            window.cmsConfirm({
                title: t.removeConfirmTitle,
                message: sprintf(t.removeConfirmMessage, fieldTitle(field)),
                confirmText: t.remove,
                confirmClass: 'btn-danger',
                onConfirm: run
            });
        } else {
            run();
        }
    }

    function duplicateField(index) {
        if (fields.length >= maxFields) {
            window.cmsToast && window.cmsToast('warning', sprintf(t.maxFields, maxFields));
            return;
        }
        var copy = cloneField(fields[index]);
        copy.name = uniqueName(copy.name, -1);
        copy.role = '';
        fields.splice(index + 1, 0, copy);
        openIndex = index + 1;
        markDirty();
        render();
    }

    // ── Editor eines Feldes ────────────────────────────────────────────

    function control(labelText, input, hint, columnClass) {
        var wrap = el('div', columnClass || 'col-md-6');
        var id = 'cmsFb' + (++uid);
        var label = el('label', 'form-label', labelText);
        label.htmlFor = id;
        input.id = id;
        wrap.appendChild(label);
        wrap.appendChild(input);
        if (hint) {
            var hintNode = el('div', 'form-hint', hint);
            hintNode.id = id + 'Hint';
            input.setAttribute('aria-describedby', hintNode.id);
            wrap.appendChild(hintNode);
        }
        return wrap;
    }

    function textInput(value, onChange, options) {
        options = options || {};
        var input = el(options.multiline ? 'textarea' : 'input', 'form-control');
        if (options.multiline) {
            input.rows = options.rows || 3;
        } else {
            input.type = options.type || 'text';
        }
        if (options.max) {
            input.maxLength = options.max;
        }
        if (options.lang) {
            input.lang = options.lang;
        }
        if (options.placeholder) {
            input.placeholder = options.placeholder;
        }
        ['min', 'max_value', 'step'].forEach(function (key) {
            if (options[key] !== undefined) {
                input.setAttribute(key === 'max_value' ? 'max' : key, options[key]);
            }
        });
        input.value = value === null || value === undefined ? '' : String(value);
        input.addEventListener('input', function () {
            onChange(input.value);
            markDirty();
        });
        return input;
    }

    function bilingual(container, labelText, source, options) {
        options = options || {};
        ['de', 'en'].forEach(function (locale) {
            var input = textInput(source[locale], function (value) {
                source[locale] = value;
                if (options.onChange) {
                    options.onChange(locale, value);
                }
            }, { multiline: options.multiline, max: options.max, lang: locale, rows: options.rows });
            container.appendChild(control(labelText + ' (' + (locale === 'de' ? t.langDe : t.langEn) + ')', input, locale === 'de' ? options.hint : ''));
        });
    }

    function select(value, choices, onChange) {
        var node = el('select', 'form-select');
        Object.keys(choices).forEach(function (key) {
            var option = el('option', '', choices[key]);
            option.value = key;
            option.selected = key === value;
            node.appendChild(option);
        });
        node.addEventListener('change', function () {
            onChange(node.value);
            markDirty();
        });
        return node;
    }

    function section(title) {
        var wrap = el('fieldset', 'cms-fb__section');
        wrap.appendChild(el('legend', 'cms-fb__legend', title));
        var row = el('div', 'row g-3');
        wrap.appendChild(row);
        return { wrap: wrap, row: row };
    }

    function numberOrNull(value) {
        return value === '' || isNaN(Number(value)) ? null : Number(value);
    }

    function buildEditor(field, index, refreshSummary) {
        var editor = el('div', 'cms-fb__editor');
        var type = field.type;
        var validation = field.validation;

        var basics = section(t.sectionBasics);
        basics.row.appendChild(control(t.type, select(type, types, function (value) {
            field.type = value;
            if (!hasOptions(value)) {
                field.options = [];
            }
            field.validation = value === 'file' ? { max_files: 1 } : {};
            if (isStatic(value) || value === 'consent' || value === 'hidden') {
                field.role = '';
            }
            if (value === 'consent') {
                field.required = true;
            }
            render();
        })));

        if (isStatic(type)) {
            bilingual(basics.row, t.content, field.content, {
                multiline: type === 'paragraph',
                max: type === 'heading' ? 200 : 2000,
                onChange: refreshSummary
            });
            if (type === 'heading') {
                basics.row.appendChild(control(t.headingLevel, select(String(field.level), { 2: 'H2', 3: 'H3', 4: 'H4' }, function (value) {
                    field.level = Number(value);
                })));
            }
        } else {
            var nameInput = textInput(field.name, function (value) {
                field.name = value;
                field._autoName = false;
                refreshSummary();
            }, { max: 64 });
            nameInput.pattern = '[a-z][a-z0-9_]{0,63}';
            nameInput.spellcheck = false;
            nameInput.addEventListener('blur', function () {
                field.name = uniqueName(slug(nameInput.value) || field.type, index);
                nameInput.value = field.name;
                refreshSummary();
            });
            basics.row.appendChild(control(t.name, nameInput, t.nameHint));
            bilingual(basics.row, t.label, field.label, {
                max: 300,
                hint: type === 'consent' ? t.consentHint : '',
                onChange: function (locale, value) {
                    if (locale === 'de' && field._autoName) {
                        field.name = uniqueName(slug(value) || field.type, index);
                        nameInput.value = field.name;
                    }
                    refreshSummary();
                }
            });

            if (type !== 'hidden') {
                var widths = { full: t.widthFull, half: t.widthHalf, third: t.widthThird, 'two-third': t.widthTwoThird };
                basics.row.appendChild(control(t.width, select(field.width, widths, function (value) {
                    field.width = value;
                })));
            }
            if (type !== 'consent' && type !== 'hidden') {
                var requiredWrap = el('div', 'col-md-6 d-flex align-items-end');
                var requiredLabel = el('label', 'form-check mb-2');
                var requiredInput = el('input', 'form-check-input');
                requiredInput.type = 'checkbox';
                requiredInput.checked = field.required;
                requiredInput.addEventListener('change', function () {
                    field.required = requiredInput.checked;
                    markDirty();
                    refreshSummary();
                });
                requiredLabel.appendChild(requiredInput);
                requiredLabel.appendChild(el('span', 'form-check-label', t.required));
                requiredWrap.appendChild(requiredLabel);
                basics.row.appendChild(requiredWrap);
            }
            if (hasOptions(type)) {
                var optionsInput = textInput(optionsToText(field.options), function (value) {
                    field.options = textToOptions(value);
                }, { multiline: true, rows: 5 });
                optionsInput.spellcheck = false;
                basics.row.appendChild(control(t.options, optionsInput, t.optionsHint, 'col-12'));
            }
            if (type === 'consent') {
                basics.row.appendChild(control(t.privacyUrl, textInput(field.privacy_url, function (value) {
                    field.privacy_url = value;
                }, { max: 500, placeholder: '/datenschutz' }), t.privacyUrlHint));
            }
        }
        editor.appendChild(basics.wrap);

        if (!isStatic(type) && type !== 'hidden' && type !== 'consent') {
            var textsSection = section(t.sectionTexts);
            if (PLACEHOLDER_TYPES.indexOf(type) !== -1) {
                bilingual(textsSection.row, t.placeholder, field.placeholder, { max: 200 });
            }
            bilingual(textsSection.row, t.help, field.help, { max: 500 });
            editor.appendChild(textsSection.wrap);
        }

        var rules = section(t.sectionValidation);
        if (LENGTH_TYPES.indexOf(type) !== -1 && type !== 'hidden') {
            rules.row.appendChild(control(t.minLength, textInput(validation.min_length, function (value) {
                validation.min_length = numberOrNull(value);
            }, { type: 'number', min: 0, max_value: 100000, step: 1 }), '', 'col-md-3'));
            rules.row.appendChild(control(t.maxLength, textInput(validation.max_length, function (value) {
                validation.max_length = numberOrNull(value);
            }, { type: 'number', min: 1, max_value: 100000, step: 1 }), '', 'col-md-3'));
        }
        if (PATTERN_TYPES.indexOf(type) !== -1) {
            var patternInput = textInput(validation.pattern, function (value) {
                validation.pattern = value;
            }, { max: 500 });
            patternInput.spellcheck = false;
            rules.row.appendChild(control(t.pattern, patternInput, t.patternHint));
            validation.pattern_message = texts(validation.pattern_message);
            bilingual(rules.row, t.patternMessage, validation.pattern_message, { max: 200 });
        }
        if (type === 'number') {
            [['min', t.min], ['max', t.max], ['step', t.step]].forEach(function (entry) {
                rules.row.appendChild(control(entry[1], textInput(validation[entry[0]], function (value) {
                    validation[entry[0]] = numberOrNull(value);
                }, { type: 'number', step: 'any' }), '', 'col-md-4'));
            });
        }
        if (type === 'date') {
            [['min_date', t.minDate], ['max_date', t.maxDate]].forEach(function (entry) {
                rules.row.appendChild(control(entry[1], textInput(validation[entry[0]], function (value) {
                    validation[entry[0]] = value;
                }, { type: 'date' })));
            });
        }
        if (type === 'file') {
            var accept = Array.isArray(validation.accept) ? validation.accept.join(', ') : '';
            rules.row.appendChild(control(t.accept, textInput(accept, function (value) {
                validation.accept = value.split(/[\s,;|]+/).filter(Boolean);
            }, { max: 300, placeholder: (config.uploadTypes || []).join(', ') }), sprintf(t.acceptHint, (config.uploadTypes || []).join(', '))));
            rules.row.appendChild(control(t.maxSizeKb, textInput(validation.max_size_kb, function (value) {
                validation.max_size_kb = numberOrNull(value);
            }, { type: 'number', min: 1, max_value: config.uploadMaxKb || 51200, step: 1, placeholder: String(config.uploadMaxKb || '') }), '', 'col-md-3'));
            rules.row.appendChild(control(t.maxFiles, textInput(validation.max_files || 1, function (value) {
                validation.max_files = numberOrNull(value) || 1;
            }, { type: 'number', min: 1, max_value: config.maxFiles || 10, step: 1 }), '', 'col-md-3'));
        }
        if (rules.row.children.length > 0) {
            editor.appendChild(rules.wrap);
        }

        if (!isStatic(type)) {
            var advanced = section(t.sectionAdvanced);
            if (['text', 'email', 'textarea', 'select', 'radio'].indexOf(type) !== -1) {
                var roles = { '': t.roleNone };
                if (type === 'text') {
                    roles.name = t.roleName;
                }
                if (type === 'email') {
                    roles.email = t.roleEmail;
                }
                if (type !== 'email' && type !== 'textarea') {
                    roles.subject = t.roleSubject;
                }
                if (type === 'textarea') {
                    roles.message = t.roleMessage;
                }
                advanced.row.appendChild(control(t.role, select(roles[field.role] !== undefined ? field.role : '', roles, function (value) {
                    fields.forEach(function (other) {
                        if (other !== field && value !== '' && other.role === value) {
                            other.role = '';
                        }
                    });
                    field.role = value;
                }), t.roleHint));
            }
            if (type !== 'file' && type !== 'consent') {
                advanced.row.appendChild(control(t.defaultValue, textInput(field['default'], function (value) {
                    field['default'] = value;
                }, { max: 500 })));
            }
            if (type !== 'hidden') {
                var cssInput = textInput(field.css_class, function (value) {
                    field.css_class = value;
                }, { max: 200 });
                cssInput.spellcheck = false;
                advanced.row.appendChild(control(t.cssClass, cssInput));
            }
            if (advanced.row.children.length > 0) {
                editor.appendChild(advanced.wrap);
            }
        }

        return editor;
    }

    // ── Liste ──────────────────────────────────────────────────────────

    function iconButton(iconName, label, onClick, extraClass) {
        var button = el('button', 'btn btn-sm btn-icon ' + (extraClass || ''));
        button.type = 'button';
        button.setAttribute('aria-label', label);
        button.title = label;
        button.appendChild(icon(iconName));
        button.addEventListener('click', onClick);
        return button;
    }

    function buildItem(field, index) {
        var item = el('li', 'cms-fb__item' + (index === openIndex ? ' is-open' : ''));
        item.draggable = index !== openIndex;
        item.dataset.index = String(index);

        var head = el('div', 'cms-fb__head');
        var handle = el('span', 'cms-fb__handle');
        handle.title = t.dragHandle;
        handle.appendChild(icon('grip-vertical'));
        head.appendChild(handle);

        var typeBadge = el('span', 'cms-fb__type');
        typeBadge.appendChild(icon(TYPE_ICONS[field.type] || 'forms'));
        typeBadge.appendChild(el('span', '', types[field.type] || field.type));
        head.appendChild(typeBadge);

        var summary = el('button', 'cms-fb__summary');
        summary.type = 'button';
        summary.setAttribute('aria-expanded', index === openIndex ? 'true' : 'false');
        var titleNode = el('span', 'cms-fb__title');
        var metaNode = el('span', 'cms-fb__meta');
        summary.appendChild(titleNode);
        summary.appendChild(metaNode);
        var refreshSummary = function () {
            var title = fieldTitle(field);
            titleNode.textContent = title;
            metaNode.textContent = isStatic(field.type) ? '' : field.name + (field.required ? ' · ' + t.requiredShort : '');
            summary.setAttribute('aria-label', (index === openIndex ? t.close : t.edit) + ': ' + title);
        };
        refreshSummary();
        summary.addEventListener('click', function () {
            openIndex = openIndex === index ? -1 : index;
            render();
            var next = list.children[index];
            var target = next ? next.querySelector('.cms-fb__summary') : null;
            if (target) {
                target.focus();
            }
        });
        summary.addEventListener('keydown', function (event) {
            if (event.altKey && (event.key === 'ArrowUp' || event.key === 'ArrowDown')) {
                event.preventDefault();
                moveField(index, index + (event.key === 'ArrowUp' ? -1 : 1), '.cms-fb__summary');
            }
        });
        head.appendChild(summary);

        var actions = el('div', 'cms-fb__actions');
        var up = iconButton('arrow-up', sprintf(t.moveUp, fieldTitle(field)), function () {
            moveField(index, index - 1, '[data-fb-up]');
        });
        up.setAttribute('data-fb-up', '');
        up.disabled = index === 0;
        var down = iconButton('arrow-down', sprintf(t.moveDown, fieldTitle(field)), function () {
            moveField(index, index + 1, '[data-fb-down]');
        });
        down.setAttribute('data-fb-down', '');
        down.disabled = index === fields.length - 1;
        actions.appendChild(up);
        actions.appendChild(down);
        actions.appendChild(iconButton('copy', t.duplicate + ': ' + fieldTitle(field), function () {
            duplicateField(index);
        }));
        actions.appendChild(iconButton('trash', t.remove + ': ' + fieldTitle(field), function () {
            removeField(index);
        }, 'text-danger'));
        head.appendChild(actions);
        item.appendChild(head);

        if (index === openIndex) {
            item.appendChild(buildEditor(field, index, refreshSummary));
        }

        item.addEventListener('dragstart', function (event) {
            dragIndex = index;
            item.classList.add('is-dragging');
            if (event.dataTransfer) {
                event.dataTransfer.effectAllowed = 'move';
                event.dataTransfer.setData('text/plain', String(index));
            }
        });
        item.addEventListener('dragend', function () {
            dragIndex = -1;
            Array.prototype.forEach.call(list.children, function (child) {
                child.classList.remove('is-dragging', 'is-drop-target');
            });
        });
        item.addEventListener('dragover', function (event) {
            if (dragIndex === -1 || dragIndex === index) {
                return;
            }
            event.preventDefault();
            item.classList.add('is-drop-target');
        });
        item.addEventListener('dragleave', function () {
            item.classList.remove('is-drop-target');
        });
        item.addEventListener('drop', function (event) {
            if (dragIndex === -1) {
                return;
            }
            event.preventDefault();
            var from = dragIndex;
            dragIndex = -1;
            moveField(from, index, '.cms-fb__summary');
        });

        return item;
    }

    function render() {
        uid = 0;
        list.textContent = '';
        fields.forEach(function (field, index) {
            list.appendChild(buildItem(field, index));
        });
        if (emptyHint) {
            emptyHint.hidden = fields.length > 0;
        }
        if (counter) {
            counter.textContent = fields.length + ' / ' + maxFields;
        }
        serialize();
    }

    // ── Start ──────────────────────────────────────────────────────────

    Object.keys(types).forEach(function (type) {
        var option = el('option', '', types[type]);
        option.value = type;
        typeSelect.appendChild(option);
    });
    root.querySelector('[data-fb-add]').addEventListener('click', function () {
        addField(typeSelect.value);
    });

    form.addEventListener('input', markDirty);
    form.addEventListener('change', markDirty);
    form.addEventListener('submit', function () {
        fields.forEach(function (field) {
            delete field._autoName;
        });
        serialize();
        dirty = false;
    });
    window.addEventListener('beforeunload', function (event) {
        if (dirty) {
            event.preventDefault();
            event.returnValue = '';
        }
    });

    root.hidden = false;
    render();
    dirty = false;
    if (unsaved) {
        unsaved.hidden = true;
    }
}());
