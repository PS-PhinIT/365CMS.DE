/**
 * Editor.js-Block „Download“ (`download`): bindet Downloads aus Medien → Downloads ein.
 *
 * Blockdaten enthalten nur Referenzen und Anzeigeoptionen, nie Dateipfade oder URLs:
 *   { downloadIds: [..], version: "latest" | "<version>", template: "compact" | "card" | "featured",
 *     options: { showDescription, showSize, showVersion, showDate, showHash, showChangelog, showCount,
 *                showProperties: [], buttonLabel, titleOverride } }
 *
 * Auswahl: Tab „Aus Downloads wählen“ (Suche, Kategorie, Dateityp) oder „Hochladen“ (legt einen vollständigen
 * Download an). Mehrfachauswahl nur mit dem Template „Kompakt“. Template und Sichtbarkeit stehen zusätzlich im
 * Block-Menü (Tunes). Die Vorschau rendert der Server (window.CMSBlockPreview) mit denselben Partials wie das
 * Frontend.
 */
(function () {
    'use strict';

    var API = '/admin/post-editor-api';
    var TEMPLATES = { compact: 'Kompakt', card: 'Karte', featured: 'Hervorgehoben' };
    var TOGGLES = {
        showDescription: 'Beschreibung',
        showVersion: 'Version',
        showDate: 'Datum',
        showSize: 'Größe',
        showCount: 'Download-Zähler',
        showHash: 'SHA-256',
        showChangelog: 'Changelog'
    };
    var DEFAULT_OPTIONS = {
        showDescription: true, showSize: true, showVersion: true, showDate: true, showHash: true,
        showChangelog: true, showCount: true, showProperties: [], buttonLabel: '', titleOverride: ''
    };
    var token = '';
    var uid = 0;

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

    function readJson(response) {
        return response.json().then(function (json) {
            return json || {};
        }, function () {
            return { success: false, error: 'Ungültige Antwort (' + response.status + ').' };
        });
    }

    function getToken() {
        if (token) {
            return Promise.resolve(token);
        }
        return fetch(API + '?action=token', { credentials: 'same-origin', headers: { Accept: 'application/json' } })
            .then(readJson)
            .then(function (json) {
                if (!json.success || typeof json.token !== 'string') {
                    throw new Error(json.error || 'Sicherheitstoken konnte nicht geladen werden.');
                }
                token = json.token;
                return token;
            });
    }

    function search(params) {
        var query = new URLSearchParams({ action: 'download_search' });
        Object.keys(params || {}).forEach(function (key) {
            if (params[key] !== '' && params[key] !== undefined && params[key] !== null) {
                query.set(key, String(params[key]));
            }
        });
        return fetch(API + '?' + query.toString(), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
            .then(readJson)
            .then(function (json) {
                if (!json.success) {
                    throw new Error(json.error || 'Downloads konnten nicht geladen werden.');
                }
                return json;
            });
    }

    function upload(file, title, version, retried) {
        return getToken().then(function (csrf) {
            var body = new FormData();
            body.append('action', 'download_upload');
            body.append('csrf_token', csrf);
            body.append('file', file);
            body.append('title', title || '');
            body.append('version', version || '');
            return fetch(API, { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json' }, body: body });
        }).then(readJson).then(function (json) {
            if (!json.success && json.code === 'csrf' && !retried) {
                token = '';
                return upload(file, title, version, true);
            }
            if (!json.success) {
                throw new Error(json.error || 'Upload fehlgeschlagen.');
            }
            return json.item;
        });
    }

    function normalize(data) {
        data = data || {};
        var options = Object.assign({}, DEFAULT_OPTIONS, data.options && typeof data.options === 'object' ? data.options : {});
        var ids = (Array.isArray(data.downloadIds) ? data.downloadIds : []).map(Number).filter(function (id) {
            return id > 0;
        });
        var template = TEMPLATES[data.template] ? data.template : '';
        return {
            downloadIds: ids,
            version: typeof data.version === 'string' && data.version !== '' ? data.version : 'latest',
            template: template,
            options: options
        };
    }

    // Die Vorschau nutzt die Frontend-Templates; deren Stylesheet einmalig im Editor laden.
    function ensurePreviewStyles() {
        if (document.querySelector('link[data-cms-dl-styles]')) {
            return;
        }
        var script = document.querySelector('script[src*="editor-blocks/download-block.js"]');
        var href = script ? script.src.replace(/js\/editor-blocks\/download-block\.js.*$/, 'css/downloads.css') : '/assets/css/downloads.css';
        var link = document.createElement('link');
        link.rel = 'stylesheet';
        link.href = href;
        link.setAttribute('data-cms-dl-styles', '');
        document.head.appendChild(link);
    }

    class CmsDownloadBlock {
        constructor(options) {
            options = options || {};
            this.api = options.api || null;
            this.readOnly = !!options.readOnly;
            this.data = normalize(options.data);
            this.config = options.config || {};
            if (!this.data.template) {
                this.data.template = TEMPLATES[this.config.defaultTemplate] ? this.config.defaultTemplate : 'card';
            }
            this.known = {};
            this.nodes = {};
            this.previewRequest = 0;
        }

        static get toolbox() {
            return {
                title: 'Download',
                icon: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2 -2v-2"/><path d="M7 11l5 5l5 -5"/><path d="M12 4l0 12"/></svg>'
            };
        }

        static get isReadOnlySupported() {
            return true;
        }

        static get sanitize() {
            return { downloadIds: false, version: false, template: false, options: false };
        }

        render() {
            ensurePreviewStyles();
            var wrapper = el('div', 'cms-editorjs-tool cms-editorjs-tool--download');
            var controls = el('div', 'cms-editorjs-download-block__controls');
            controls.dataset.cmsEditorUi = 'true';
            controls.dataset.mutationFree = 'true';
            var preview = el('div', 'cms-editorjs-download-block__preview');
            preview.setAttribute('aria-live', 'polite');
            // Server-Vorschau: Änderungen am Markup sind keine Inhaltsänderung des Blocks.
            preview.dataset.mutationFree = 'true';
            this.nodes = { wrapper: wrapper, controls: controls, preview: preview };

            if (!this.readOnly) {
                this.buildControls(controls);
                wrapper.appendChild(controls);
            }
            wrapper.appendChild(preview);
            preview.addEventListener('click', function (event) {
                if (event.target && event.target.closest && event.target.closest('a, button')) {
                    event.preventDefault();
                }
            });

            if (this.data.downloadIds.length > 0) {
                this.loadSelected();
            }
            this.refreshPreview();

            return wrapper;
        }

        buildControls(controls) {
            var self = this;
            var tabs = el('div', 'btn-group btn-group-sm mb-2');
            tabs.setAttribute('role', 'tablist');
            var pickTab = el('button', 'btn active', 'Aus Downloads wählen');
            var uploadTab = el('button', 'btn', 'Hochladen');
            [pickTab, uploadTab].forEach(function (tab) {
                tab.type = 'button';
                tab.setAttribute('role', 'tab');
                tabs.appendChild(tab);
            });
            var pickPane = el('div', 'cms-dl-block__pane');
            var uploadPane = el('div', 'cms-dl-block__pane');
            uploadPane.hidden = true;
            var setTab = function (pick) {
                pickPane.hidden = !pick;
                uploadPane.hidden = pick;
                pickTab.classList.toggle('active', pick);
                uploadTab.classList.toggle('active', !pick);
                pickTab.setAttribute('aria-selected', pick ? 'true' : 'false');
                uploadTab.setAttribute('aria-selected', pick ? 'false' : 'true');
            };
            pickTab.addEventListener('click', function () { setTab(true); });
            uploadTab.addEventListener('click', function () { setTab(false); });
            setTab(true);

            // Auswahl
            var filters = el('div', 'd-flex flex-wrap gap-2 mb-2');
            var q = el('input', 'form-control form-control-sm');
            q.type = 'search';
            q.placeholder = 'Downloads suchen …';
            q.setAttribute('aria-label', 'Downloads suchen');
            var category = el('select', 'form-select form-select-sm w-auto');
            category.setAttribute('aria-label', 'Kategorie');
            category.appendChild(new Option('Alle Kategorien', ''));
            var type = el('select', 'form-select form-select-sm w-auto');
            type.setAttribute('aria-label', 'Dateityp');
            type.appendChild(new Option('Alle Typen', ''));
            var access = el('select', 'form-select form-select-sm w-auto');
            access.setAttribute('aria-label', 'Zugriff');
            [['', 'Jeder Zugriff'], ['public', 'Öffentlich'], ['logged_in', 'Angemeldet'], ['roles', 'Rollen'], ['member', 'Mitglieder']].forEach(function (pair) {
                access.appendChild(new Option(pair[1], pair[0]));
            });
            filters.appendChild(q);
            filters.appendChild(category);
            filters.appendChild(type);
            filters.appendChild(access);
            var results = el('ul', 'list-group list-group-flush cms-dl-block__results');
            var status = el('div', 'form-hint');
            status.setAttribute('role', 'status');
            pickPane.appendChild(filters);
            pickPane.appendChild(results);
            pickPane.appendChild(status);

            var timer = 0;
            var runSearch = function () {
                status.textContent = 'Suche …';
                search({ q: q.value, category: category.value, type: type.value, access: access.value }).then(function (json) {
                    if (category.options.length === 1) {
                        (json.categories || []).forEach(function (c) { category.appendChild(new Option(c.name, String(c.id))); });
                        (json.file_types || []).forEach(function (t) { type.appendChild(new Option(String(t).toUpperCase(), t)); });
                    }
                    self.renderResults(results, json.items || []);
                    status.textContent = (json.items || []).length === 0 ? 'Keine Downloads gefunden.' : (json.total + ' Downloads');
                }).catch(function (error) {
                    status.textContent = error.message;
                });
            };
            q.addEventListener('input', function () {
                window.clearTimeout(timer);
                timer = window.setTimeout(runSearch, 300);
            });
            [category, type, access].forEach(function (select) {
                select.addEventListener('change', runSearch);
            });
            this.runSearch = runSearch;

            // Upload
            var uploadId = 'cmsDlUpload' + (++uid);
            var fileLabel = el('label', 'form-label', 'Datei');
            fileLabel.htmlFor = uploadId;
            var file = el('input', 'form-control form-control-sm mb-2');
            file.type = 'file';
            file.id = uploadId;
            var title = el('input', 'form-control form-control-sm mb-2');
            title.placeholder = 'Titel (optional, sonst aus dem Dateinamen)';
            title.setAttribute('aria-label', 'Titel');
            var version = el('input', 'form-control form-control-sm mb-2');
            version.placeholder = 'Version (optional, z. B. 1.0)';
            version.setAttribute('aria-label', 'Version');
            var send = el('button', 'btn btn-sm btn-primary', 'Hochladen und einfügen');
            send.type = 'button';
            var uploadStatus = el('div', 'form-hint');
            uploadStatus.setAttribute('role', 'status');
            send.addEventListener('click', function () {
                if (!file.files || !file.files[0]) {
                    uploadStatus.textContent = 'Bitte eine Datei wählen.';
                    return;
                }
                send.disabled = true;
                uploadStatus.textContent = 'Wird hochgeladen …';
                upload(file.files[0], title.value, version.value).then(function (item) {
                    send.disabled = false;
                    file.value = '';
                    title.value = '';
                    version.value = '';
                    uploadStatus.textContent = '„' + item.title + '“ wurde angelegt' + (item.status === 'draft' ? ' (Entwurf).' : '.') + ' ';
                    var link = el('a', '', 'In Downloads bearbeiten');
                    link.href = item.edit_url;
                    link.target = '_blank';
                    link.rel = 'noopener';
                    uploadStatus.appendChild(link);
                    self.known[item.id] = item;
                    self.select(item.id, false);
                }).catch(function (error) {
                    send.disabled = false;
                    uploadStatus.textContent = error.message;
                });
            });
            uploadPane.appendChild(fileLabel);
            uploadPane.appendChild(file);
            uploadPane.appendChild(title);
            uploadPane.appendChild(version);
            uploadPane.appendChild(send);
            uploadPane.appendChild(uploadStatus);

            // Auswahl, Version, Template
            var selected = el('div', 'cms-dl-block__selected mt-2');
            var settings = el('div', 'd-flex flex-wrap gap-2 align-items-center mt-2');
            var template = el('select', 'form-select form-select-sm w-auto');
            template.setAttribute('aria-label', 'Template');
            Object.keys(TEMPLATES).forEach(function (key) {
                var option = new Option('Template: ' + TEMPLATES[key], key);
                option.selected = key === self.data.template;
                template.appendChild(option);
            });
            template.addEventListener('change', function () {
                self.setTemplate(template.value);
            });
            var versionSelect = el('select', 'form-select form-select-sm w-auto');
            versionSelect.setAttribute('aria-label', 'Version');
            versionSelect.addEventListener('change', function () {
                self.data.version = versionSelect.value || 'latest';
                self.refreshPreview();
            });
            var button = el('input', 'form-control form-control-sm w-auto');
            button.placeholder = 'Button-Text (optional)';
            button.setAttribute('aria-label', 'Button-Text');
            button.maxLength = 80;
            button.value = this.data.options.buttonLabel || '';
            button.addEventListener('change', function () {
                self.data.options.buttonLabel = button.value;
                self.refreshPreview();
            });
            settings.appendChild(template);
            settings.appendChild(versionSelect);
            settings.appendChild(button);

            controls.appendChild(tabs);
            controls.appendChild(pickPane);
            controls.appendChild(uploadPane);
            controls.appendChild(selected);
            controls.appendChild(settings);
            this.nodes.selected = selected;
            this.nodes.template = template;
            this.nodes.versionSelect = versionSelect;

            this.renderSelected();
            runSearch();
        }

        renderResults(list, items) {
            var self = this;
            list.textContent = '';
            items.forEach(function (item) {
                self.known[item.id] = item;
                var row = el('li', 'list-group-item d-flex align-items-center gap-2 py-1');
                var input = el('input', 'form-check-input');
                var multi = self.data.template === 'compact';
                input.type = multi ? 'checkbox' : 'radio';
                input.name = 'cmsDlPick' + uid;
                input.checked = self.data.downloadIds.indexOf(item.id) !== -1;
                input.id = 'cmsDlPick' + uid + '_' + item.id;
                var label = el('label', 'flex-grow-1 mb-0');
                label.htmlFor = input.id;
                label.appendChild(el('strong', '', item.title));
                var meta = [item.version ? 'v' + item.version : 'ohne Version', item.size, item.type ? item.type.toUpperCase() : '', item.count + ' Downloads'];
                if (item.status !== 'published') {
                    meta.unshift(item.status === 'draft' ? 'Entwurf' : item.status);
                }
                label.appendChild(el('span', 'text-secondary small ms-2', meta.filter(Boolean).join(' · ')));
                input.addEventListener('change', function () {
                    if (input.checked) {
                        self.select(item.id, multi);
                    } else {
                        self.deselect(item.id);
                    }
                });
                row.appendChild(input);
                row.appendChild(label);
                list.appendChild(row);
            });
        }

        select(id, multi) {
            if (multi) {
                if (this.data.downloadIds.indexOf(id) === -1 && this.data.downloadIds.length < 50) {
                    this.data.downloadIds.push(id);
                }
            } else {
                this.data.downloadIds = [id];
            }
            if (this.data.downloadIds.length !== 1) {
                this.data.version = 'latest';
            }
            this.renderSelected();
            this.refreshPreview();
        }

        deselect(id) {
            this.data.downloadIds = this.data.downloadIds.filter(function (other) { return other !== id; });
            this.renderSelected();
            this.refreshPreview();
        }

        setTemplate(template) {
            this.data.template = TEMPLATES[template] ? template : 'card';
            if (this.data.template !== 'compact' && this.data.downloadIds.length > 1) {
                this.data.downloadIds = this.data.downloadIds.slice(0, 1);
            }
            if (this.nodes.template) {
                this.nodes.template.value = this.data.template;
            }
            this.renderSelected();
            if (this.runSearch) {
                this.runSearch();
            }
            this.refreshPreview();
        }

        loadSelected() {
            var self = this;
            search({ ids: this.data.downloadIds.join(',') }).then(function (json) {
                (json.items || []).forEach(function (item) { self.known[item.id] = item; });
                self.renderSelected();
            }).catch(function () {});
        }

        renderSelected() {
            var self = this;
            var box = this.nodes.selected;
            var versionSelect = this.nodes.versionSelect;
            if (!box) {
                return;
            }
            box.textContent = '';
            if (this.data.downloadIds.length === 0) {
                box.appendChild(el('span', 'text-secondary small', 'Noch kein Download ausgewählt.'));
            } else {
                box.appendChild(el('span', 'small me-1', 'Ausgewählt:'));
                this.data.downloadIds.forEach(function (id) {
                    var item = self.known[id];
                    var chip = el('span', 'badge bg-blue-lt me-1', item ? item.title : '#' + id);
                    box.appendChild(chip);
                });
            }
            if (versionSelect) {
                versionSelect.textContent = '';
                versionSelect.appendChild(new Option('Immer aktuelle Version', 'latest'));
                var single = this.data.downloadIds.length === 1 ? this.known[this.data.downloadIds[0]] : null;
                (single && Array.isArray(single.versions) ? single.versions : []).forEach(function (version) {
                    versionSelect.appendChild(new Option('Fest: Version ' + version, version));
                });
                versionSelect.value = this.data.version;
                if (versionSelect.value !== this.data.version) {
                    versionSelect.appendChild(new Option('Fest: Version ' + this.data.version, this.data.version));
                    versionSelect.value = this.data.version;
                }
                versionSelect.disabled = this.data.downloadIds.length !== 1;
            }
        }

        refreshPreview() {
            var self = this;
            var preview = this.nodes.preview;
            if (!preview) {
                return;
            }
            var request = ++this.previewRequest;
            if (this.data.downloadIds.length === 0) {
                preview.textContent = '';
                preview.appendChild(el('p', 'text-secondary mb-0', 'Bitte einen Download auswählen oder hochladen.'));
                return;
            }
            if (!window.CMSBlockPreview || typeof window.CMSBlockPreview.render !== 'function') {
                preview.textContent = 'Downloads: ' + this.data.downloadIds.join(', ');
                return;
            }
            preview.classList.add('is-loading');
            window.CMSBlockPreview.render('download', this.save(), {}).then(function (html) {
                if (request !== self.previewRequest) {
                    return;
                }
                preview.classList.remove('is-loading');
                // HTML stammt vom Server-Renderer des Blocks (gleiche Partials wie im Frontend, escaped).
                preview.innerHTML = html;
            }).catch(function (error) {
                if (request === self.previewRequest) {
                    preview.classList.remove('is-loading');
                    preview.textContent = (error && error.message) || 'Vorschau konnte nicht geladen werden.';
                }
            });
        }

        renderSettings() {
            var self = this;
            var items = Object.keys(TEMPLATES).map(function (key) {
                return {
                    icon: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="4" y="4" width="16" height="16" rx="2"/></svg>',
                    label: 'Template: ' + TEMPLATES[key],
                    isActive: self.data.template === key,
                    closeOnActivate: true,
                    onActivate: function () { self.setTemplate(key); }
                };
            });
            Object.keys(TOGGLES).forEach(function (key) {
                items.push({
                    icon: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12l5 5l10 -10"/></svg>',
                    label: TOGGLES[key] + ' anzeigen',
                    isActive: !!self.data.options[key],
                    toggle: true,
                    onActivate: function () {
                        self.data.options[key] = !self.data.options[key];
                        self.refreshPreview();
                    }
                });
            });
            return items;
        }

        save() {
            return {
                downloadIds: this.data.downloadIds.slice(0, this.data.template === 'compact' ? 50 : 1),
                version: this.data.downloadIds.length === 1 ? this.data.version : 'latest',
                template: this.data.template,
                options: Object.assign({}, this.data.options)
            };
        }

        validate(savedData) {
            return !!(savedData && Array.isArray(savedData.downloadIds) && savedData.downloadIds.length > 0);
        }
    }

    window.CmsDownloadBlock = CmsDownloadBlock;
    window.CMS_EDITORJS_BLOCKS = Array.isArray(window.CMS_EDITORJS_BLOCKS) ? window.CMS_EDITORJS_BLOCKS : [];
    window.CMS_EDITORJS_BLOCKS.push({ name: 'download', toolClass: CmsDownloadBlock, definition: {} });
}());
