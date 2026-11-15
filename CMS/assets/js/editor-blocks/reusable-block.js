/**
 * Editor.js-Block „Vorlagen“ (`reusableBlock`, 4.1.0): bindet eine Vorlage aus „EditorJS Block Vorlagen“ per
 * Verweis ein – geändert wird zentral, Beiträge und Seiten zeigen immer den aktuellen Stand.
 *
 * Blockdaten: { id: 12 } – nur der Verweis. Auswahl: GET /admin/post-editor-api?action=reusable_block_choices,
 * Vorschau rendert der Server (window.CMSBlockPreview, aktueller Stand des Blocks). „In normale Blöcke umwandeln“
 * holt die Blöcke über ?action=reusable_block_content und ersetzt den Verweis durch eine Kopie (die Verknüpfung endet).
 */
(function () {
    'use strict';

    var API = '/admin/post-editor-api';
    var choicesPromise = null;
    var uid = 0;

    function getJson(url) {
        return fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
            .then(function (response) {
                return response.json();
            })
            .then(function (json) {
                if (!json || !json.success) {
                    throw new Error((json && json.error) || 'Anfrage fehlgeschlagen.');
                }
                return json;
            });
    }

    function loadChoices() {
        if (!choicesPromise) {
            choicesPromise = getJson(API + '?action=reusable_block_choices').catch(function (error) {
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

    function toId(value) {
        var id = parseInt(value, 10);
        return isFinite(id) && id > 0 ? id : 0;
    }

    function editorLocale() {
        var input = document.querySelector('input[name="editor_locale"]');
        return input && input.value === 'en' ? 'en' : 'de';
    }

    class CmsReusableBlock {
        constructor(options) {
            options = options || {};
            var data = options.data || {};
            this.api = options.api || null;
            this.block = options.block || null;
            this.readOnly = !!options.readOnly;
            this.data = { id: toId(data.id) };
            this.nodes = {};
            this.previewRequest = 0;
            this.manageUrl = '';
        }

        static get toolbox() {
            return {
                title: 'Vorlagen',
                icon: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 4h-2a2 2 0 0 0 -2 2v2"/><path d="M16 4h2a2 2 0 0 1 2 2v2"/><path d="M8 20h-2a2 2 0 0 1 -2 -2v-2"/><path d="M16 20h2a2 2 0 0 0 2 -2v-2"/><path d="M9 12h6"/><path d="M12 9v6"/></svg>'
            };
        }

        static get isReadOnlySupported() {
            return true;
        }

        static get sanitize() {
            return { id: false };
        }

        render() {
            var self = this;
            var id = 'cmsReusableBlock' + (++uid);
            var wrapper = el('div', 'cms-editorjs-tool cms-editorjs-tool--reusable-block');
            var controls = el('div', 'cms-editorjs-reusable-block__controls');
            var label = el('label', 'form-label mb-1', 'Vorlage');
            var select = el('select', 'form-select form-select-sm');
            var actions = el('div', 'cms-editorjs-reusable-block__actions d-flex flex-wrap gap-2 mt-2');
            var editLink = el('a', 'btn btn-sm btn-outline-secondary', 'Vorlage bearbeiten');
            var convert = el('button', 'btn btn-sm btn-outline-secondary', 'In normale Blöcke umwandeln');
            var status = el('div', 'form-hint');
            var preview = el('div', 'cms-editorjs-reusable-block__preview');

            controls.dataset.cmsEditorUi = 'true';
            controls.dataset.mutationFree = 'true';
            label.htmlFor = id;
            select.id = id;
            select.disabled = true;
            select.appendChild(new Option('Vorlagen werden geladen …', ''));
            editLink.target = '_blank';
            editLink.rel = 'noopener';
            editLink.hidden = true;
            convert.type = 'button';
            convert.hidden = true;
            status.setAttribute('role', 'status');
            preview.setAttribute('aria-live', 'polite');
            preview.dataset.mutationFree = 'true';

            controls.appendChild(label);
            controls.appendChild(select);
            actions.appendChild(editLink);
            actions.appendChild(convert);
            controls.appendChild(actions);
            controls.appendChild(status);
            wrapper.appendChild(controls);
            wrapper.appendChild(preview);

            this.nodes = { select: select, status: status, preview: preview, editLink: editLink, convert: convert };

            select.addEventListener('change', function () {
                self.data.id = toId(select.value);
                self.updateActions();
                self.refreshPreview();
            });
            convert.addEventListener('click', function () {
                self.convertToBlocks();
            });
            preview.addEventListener('click', function (event) {
                if (event.target && event.target.closest && event.target.closest('a')) {
                    event.preventDefault();
                }
            });

            loadChoices().then(function (result) {
                var blocks = Array.isArray(result.blocks) ? result.blocks : [];
                self.manageUrl = typeof result.manage_url === 'string' ? result.manage_url : '';
                select.textContent = '';
                select.appendChild(new Option('– Vorlage wählen –', ''));
                var known = false;
                blocks.forEach(function (block) {
                    var option = new Option(block.title + ' (#' + block.id + ')', String(block.id));
                    option.selected = toId(block.id) === self.data.id;
                    known = known || option.selected;
                    select.appendChild(option);
                });
                if (self.data.id && !known) {
                    var missing = new Option('#' + self.data.id + ' (nicht mehr vorhanden)', String(self.data.id));
                    missing.selected = true;
                    select.appendChild(missing);
                }
                select.disabled = self.readOnly;
                status.textContent = blocks.length === 0 ? 'Es gibt noch keine Vorlagen – anlegen unter „EditorJS Block Vorlagen“.' : '';
                self.updateActions();
            }).catch(function (error) {
                select.textContent = '';
                select.appendChild(new Option(self.data.id ? '#' + self.data.id : '–', String(self.data.id || '')));
                status.textContent = (error && error.message) || 'Vorlagen konnten nicht geladen werden.';
                status.classList.add('text-danger');
            });

            this.refreshPreview();

            return wrapper;
        }

        updateActions() {
            var hasBlock = this.data.id > 0;
            this.nodes.editLink.hidden = !hasBlock || !this.manageUrl || this.readOnly;
            if (hasBlock && this.manageUrl) {
                this.nodes.editLink.href = this.manageUrl + '?action=edit&id=' + encodeURIComponent(String(this.data.id));
            }
            this.nodes.convert.hidden = !hasBlock || this.readOnly || !this.api;
        }

        refreshPreview() {
            var self = this;
            var preview = this.nodes.preview;
            if (!preview) {
                return;
            }
            var request = ++this.previewRequest;
            if (!this.data.id) {
                preview.textContent = '';
                preview.appendChild(el('p', 'text-secondary mb-0', 'Bitte eine Vorlage auswählen.'));
                return;
            }
            if (!window.CMSBlockPreview || typeof window.CMSBlockPreview.render !== 'function') {
                preview.textContent = 'Vorlage #' + this.data.id;
                return;
            }
            preview.classList.add('is-loading');
            window.CMSBlockPreview.render('reusableBlock', this.data, {}).then(function (html) {
                if (request !== self.previewRequest) {
                    return;
                }
                preview.classList.remove('is-loading');
                // HTML stammt vom Server-Renderer (Editor.js-Renderer mit bereinigten Blockdaten).
                preview.innerHTML = html;
            }).catch(function (error) {
                if (request !== self.previewRequest) {
                    return;
                }
                preview.classList.remove('is-loading');
                preview.textContent = (error && error.message) || 'Vorschau konnte nicht geladen werden.';
            });
        }

        convertToBlocks() {
            var self = this;
            if (!this.api || !this.data.id) {
                return;
            }
            if (!window.confirm('Die Vorlage durch eine Kopie ihrer Inhalte ersetzen? Spätere Änderungen an der Vorlage erscheinen hier dann nicht mehr.')) {
                return;
            }
            var url = API + '?action=reusable_block_content&id=' + encodeURIComponent(String(this.data.id)) + '&locale=' + editorLocale();
            getJson(url).then(function (result) {
                var blocks = Array.isArray(result.blocks) ? result.blocks : [];
                var api = self.api.blocks;
                var index = self.block && typeof api.getBlockIndex === 'function'
                    ? api.getBlockIndex(self.block.id)
                    : api.getCurrentBlockIndex();
                if (typeof index !== 'number' || index < 0) {
                    throw new Error('Position der Vorlage im Editor nicht gefunden.');
                }
                var inserted = 0;
                blocks.forEach(function (item) {
                    try {
                        api.insert(item.type, item.data || {}, {}, index + 1 + inserted, false);
                        inserted++;
                    } catch (error) {
                        // Unbekannter Blocktyp in diesem Editor: überspringen statt abbrechen.
                    }
                });
                api.delete(index);
                if (self.api.notifier) {
                    self.api.notifier.show({ message: inserted + ' Block/Blöcke eingefügt – die Verknüpfung ist aufgehoben.', style: 'success' });
                }
            }).catch(function (error) {
                self.nodes.status.textContent = (error && error.message) || 'Umwandeln fehlgeschlagen.';
                self.nodes.status.classList.add('text-danger');
            });
        }

        save() {
            return { id: this.data.id };
        }

        validate(savedData) {
            return !!(savedData && toId(savedData.id) > 0);
        }
    }

    window.CmsReusableBlock = CmsReusableBlock;
    window.CMS_EDITORJS_BLOCKS = Array.isArray(window.CMS_EDITORJS_BLOCKS) ? window.CMS_EDITORJS_BLOCKS : [];
    window.CMS_EDITORJS_BLOCKS.push({ name: 'reusableBlock', toolClass: CmsReusableBlock, definition: {} });
}());
