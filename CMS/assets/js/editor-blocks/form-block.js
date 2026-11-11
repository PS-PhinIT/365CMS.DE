/**
 * Editor.js-Block „Formular“ (`form`): bindet ein Formular aus Inhalte → Formulare ein.
 *
 * Blockdaten: { form: "<schlüssel>", showHeading: true, showIntro: true } – nur der Verweis, nie Felder oder HTML.
 * Die Auswahlliste kommt von GET /admin/post-editor-api?action=form_choices, die Vorschau rendert der Server
 * (window.CMSBlockPreview, gleicher FormRenderer wie im Frontend, Formular dort deaktiviert).
 */
(function () {
    'use strict';

    var ENDPOINT = '/admin/post-editor-api?action=form_choices';
    var choicesPromise = null;
    var uid = 0;

    function loadChoices() {
        if (!choicesPromise) {
            choicesPromise = fetch(ENDPOINT, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
                .then(function (response) {
                    return response.json();
                })
                .then(function (json) {
                    if (!json || !json.success || !Array.isArray(json.forms)) {
                        throw new Error((json && json.error) || 'Formulare konnten nicht geladen werden.');
                    }
                    return { forms: json.forms, manageUrl: typeof json.manage_url === 'string' ? json.manage_url : '' };
                })
                .catch(function (error) {
                    choicesPromise = null;
                    throw error;
                });
        }

        return choicesPromise;
    }

    function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) {
            node.className = className;
        }
        if (text !== undefined) {
            node.textContent = text;
        }
        return node;
    }

    function flag(value) {
        return !(value === false || value === 0 || value === '0' || value === 'false');
    }

    class CmsFormBlock {
        constructor(options) {
            options = options || {};
            var data = options.data || {};
            this.api = options.api || null;
            this.readOnly = !!options.readOnly;
            this.data = {
                form: typeof data.form === 'string' ? data.form : '',
                showHeading: flag(data.showHeading),
                showIntro: flag(data.showIntro)
            };
            this.nodes = {};
            this.previewRequest = 0;
        }

        static get toolbox() {
            return {
                title: 'Formular',
                icon: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3a3 3 0 0 0 -3 3v12a3 3 0 0 0 3 3"/><path d="M6 3a3 3 0 0 1 3 3v12a3 3 0 0 1 -3 3"/><path d="M13 7h7a1 1 0 0 1 1 1v8a1 1 0 0 1 -1 1h-7"/><path d="M5 7h-1a1 1 0 0 0 -1 1v8a1 1 0 0 0 1 1h1"/><path d="M17 12h.01"/><path d="M13 12h.01"/></svg>'
            };
        }

        static get isReadOnlySupported() {
            return true;
        }

        static get sanitize() {
            return { form: false, showHeading: false, showIntro: false };
        }

        render() {
            var self = this;
            var id = 'cmsFormBlock' + (++uid);
            var wrapper = el('div', 'cms-editorjs-tool cms-editorjs-tool--form');
            var controls = el('div', 'cms-editorjs-form-block__controls');
            var label = el('label', 'form-label mb-1', 'Formular');
            var select = el('select', 'form-select form-select-sm');
            var options = el('div', 'cms-editorjs-form-block__options');
            var status = el('div', 'form-hint');
            var preview = el('div', 'cms-editorjs-form-block__preview');

            controls.dataset.cmsEditorUi = 'true';
            controls.dataset.mutationFree = 'true';
            label.htmlFor = id;
            select.id = id;
            select.disabled = true;
            select.appendChild(new Option('Formulare werden geladen …', ''));
            status.setAttribute('role', 'status');
            preview.setAttribute('aria-live', 'polite');

            controls.appendChild(label);
            controls.appendChild(select);
            options.appendChild(this.checkbox('Überschrift anzeigen', 'showHeading'));
            options.appendChild(this.checkbox('Einleitung anzeigen', 'showIntro'));
            controls.appendChild(options);
            controls.appendChild(status);
            wrapper.appendChild(controls);
            wrapper.appendChild(preview);

            this.nodes = { wrapper: wrapper, select: select, status: status, preview: preview };

            select.addEventListener('change', function () {
                self.data.form = select.value;
                self.refreshPreview();
            });
            // Vorschau ist reine Anzeige: keine Absendung, keine Navigation aus dem Editor.
            preview.addEventListener('submit', function (event) {
                event.preventDefault();
            });
            preview.addEventListener('click', function (event) {
                if (event.target && event.target.closest && event.target.closest('a')) {
                    event.preventDefault();
                }
            });

            loadChoices().then(function (result) {
                select.textContent = '';
                select.appendChild(new Option('– Formular wählen –', ''));
                var known = false;
                result.forms.forEach(function (form) {
                    var option = new Option(form.title + ' (' + form.key + ')', form.key);
                    option.selected = form.key === self.data.form;
                    known = known || option.selected;
                    select.appendChild(option);
                });
                if (self.data.form && !known) {
                    var missing = new Option(self.data.form + ' (nicht verfügbar)', self.data.form);
                    missing.selected = true;
                    select.appendChild(missing);
                }
                select.disabled = self.readOnly;
                status.textContent = '';
                if (result.forms.length === 0) {
                    status.textContent = 'Es gibt noch kein aktives Formular. ';
                }
                if (result.manageUrl && !self.readOnly) {
                    var link = el('a', '', 'Formulare verwalten');
                    link.href = result.manageUrl;
                    link.target = '_blank';
                    link.rel = 'noopener';
                    status.appendChild(link);
                }
            }).catch(function (error) {
                select.textContent = '';
                select.appendChild(new Option(self.data.form || '–', self.data.form));
                status.textContent = (error && error.message) || 'Formulare konnten nicht geladen werden.';
                status.classList.add('text-danger');
            });

            this.refreshPreview();

            return wrapper;
        }

        checkbox(text, key) {
            var self = this;
            var label = el('label', 'form-check form-check-inline mb-0');
            var input = el('input', 'form-check-input');
            input.type = 'checkbox';
            input.checked = !!this.data[key];
            input.disabled = this.readOnly;
            input.addEventListener('change', function () {
                self.data[key] = input.checked;
                self.refreshPreview();
            });
            label.appendChild(input);
            label.appendChild(el('span', 'form-check-label', text));

            return label;
        }

        refreshPreview() {
            var self = this;
            var preview = this.nodes.preview;
            if (!preview) {
                return;
            }
            var request = ++this.previewRequest;
            if (!this.data.form) {
                preview.textContent = '';
                preview.appendChild(el('p', 'text-secondary mb-0', 'Bitte ein Formular auswählen.'));
                return;
            }
            if (!window.CMSBlockPreview || typeof window.CMSBlockPreview.render !== 'function') {
                preview.textContent = 'Formular: ' + this.data.form;
                return;
            }
            preview.classList.add('is-loading');
            window.CMSBlockPreview.render('form', this.data, {}).then(function (html) {
                if (request !== self.previewRequest) {
                    return;
                }
                preview.classList.remove('is-loading');
                // HTML stammt vom Server-Renderer des Blocks (vollständig escaped).
                preview.innerHTML = html;
                Array.prototype.forEach.call(preview.querySelectorAll('input, select, textarea, button'), function (control) {
                    control.disabled = true;
                });
            }).catch(function (error) {
                if (request !== self.previewRequest) {
                    return;
                }
                preview.classList.remove('is-loading');
                preview.textContent = (error && error.message) || 'Vorschau konnte nicht geladen werden.';
            });
        }

        save() {
            return { form: this.data.form, showHeading: !!this.data.showHeading, showIntro: !!this.data.showIntro };
        }

        validate(savedData) {
            return !!(savedData && typeof savedData.form === 'string' && savedData.form !== '');
        }
    }

    window.CmsFormBlock = CmsFormBlock;
    window.CMS_EDITORJS_BLOCKS = Array.isArray(window.CMS_EDITORJS_BLOCKS) ? window.CMS_EDITORJS_BLOCKS : [];
    window.CMS_EDITORJS_BLOCKS.push({ name: 'form', toolClass: CmsFormBlock, definition: {} });
}());
