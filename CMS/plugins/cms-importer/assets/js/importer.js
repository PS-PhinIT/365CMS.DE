/**
 * CMS WordPress Importer – Admin JS
 * Upload, Dry-Run, Import, Tabs, Ordner-Import und Preview-Rendering
 */
(function () {
    'use strict';

    var cleanupModal = document.getElementById('js-cleanup-modal');
    var cleanupActionInput = document.getElementById('js-cleanup-action');
    var cleanupModalTitle = document.getElementById('js-cleanup-modal-title');
    var cleanupModalText = document.getElementById('js-cleanup-modal-text');
    var cleanupSubmit = document.getElementById('js-cleanup-submit');
    var cleanupForm = document.getElementById('js-cleanup-form');
    var cleanupResetCheckbox = document.getElementById('js-cleanup-reset-sequences');

    initCleanupModal();

    document.querySelectorAll('.ci-tab[data-tab]').forEach(function (tab) {
        tab.addEventListener('click', function () {
            activateTab(tab.dataset.tab || 'upload');
        });
    });

    var form = document.getElementById('js-import-form');
    var fileInput = document.getElementById('wxr_file');
    var nameEl = document.getElementById('js-filename');
    var previewBtn = document.getElementById('js-preview-btn');
    var submitBtn = document.getElementById('js-submit-btn');
    var previewText = document.getElementById('js-preview-text');
    var previewSpin = document.getElementById('js-preview-spin');
    var submitText = document.getElementById('js-btn-text');
    var submitSpin = document.getElementById('js-btn-spin');
    var noticeEl = document.getElementById('js-import-notice');
    var progressEl = document.getElementById('js-progress');
    var progFill = document.getElementById('js-prog-fill');
    var progLabel = document.getElementById('js-prog-label');
    var btnSelect = document.getElementById('js-btn-select');
    var btnUpload = document.getElementById('js-btn-upload');
    var uploadStatus = document.getElementById('js-upload-status');
    var uploadedFile = document.getElementById('js-uploaded-file');
    var assignedAuthorSelect = document.getElementById('js-assigned-author-id');
    var authorDisplayNameInput = document.getElementById('js-author-display-name');
    var importOnlyEnCheckbox = document.querySelector('[data-shared-en-filter-source]');
    var previewPanel = document.getElementById('js-preview-panel');
    var previewSummary = document.getElementById('js-preview-summary');
    var previewReasons = document.getElementById('js-preview-reasons');
    var previewTableWrap = document.getElementById('js-preview-table-wrap');
    var previewTableBody = document.getElementById('js-preview-table-body');
    var previewNote = document.getElementById('js-preview-note');

    if (!form || !fileInput) {
        return;
    }

    syncSharedAuthorFields();

    if (assignedAuthorSelect) {
        assignedAuthorSelect.addEventListener('change', syncSharedAuthorFields);
    }

    if (authorDisplayNameInput) {
        authorDisplayNameInput.addEventListener('input', syncSharedAuthorFields);
    }

    if (importOnlyEnCheckbox) {
        importOnlyEnCheckbox.addEventListener('change', syncSharedAuthorFields);
    }

    if (btnSelect) {
        btnSelect.addEventListener('click', function () {
            fileInput.click();
        });
    }

    fileInput.addEventListener('change', function () {
        var file = fileInput.files[0] || null;
        hidePreview();

        if (!file) {
            if (nameEl) { nameEl.textContent = ''; }
            if (btnUpload) { btnUpload.disabled = true; }
            if (previewBtn) { previewBtn.disabled = true; }
            if (submitBtn) { submitBtn.disabled = true; }
            setStepState(2, 'pending');
            setStepState(3, 'pending');
            return;
        }

        if (nameEl) {
            nameEl.textContent = '✓ ' + file.name + ' (' + fmtBytes(file.size) + ')';
        }
        if (btnUpload) { btnUpload.disabled = false; }
        if (previewBtn) { previewBtn.disabled = true; }
        if (submitBtn) { submitBtn.disabled = true; }
        if (uploadedFile) { uploadedFile.value = ''; }
        if (uploadStatus) {
            uploadStatus.textContent = '';
            uploadStatus.className = 'ci-wizard-step__status';
        }
        setStepState(2, 'active');
        setStepState(3, 'pending');
    });

    if (btnUpload) {
        btnUpload.addEventListener('click', function () {
            var file = fileInput.files[0];
            if (!file) {
                return;
            }

            if (!/\.(xml|json)$/i.test(file.name)) {
                showNotice('Nur WordPress-WXR (.xml) oder Rank-Math-JSON (.json) sind erlaubt.', 'error');
                return;
            }

            if (file.size > 52428800) {
                showNotice('Datei zu groß (max. 50 MB). Gewählt: ' + fmtBytes(file.size), 'error');
                return;
            }

            hidePreview();
            btnUpload.disabled = true;
            if (uploadStatus) {
                uploadStatus.textContent = '⏳ Hochladen…';
                uploadStatus.className = 'ci-wizard-step__status ci-wizard-step__status--busy';
            }
            showProgress('Hochladen…', 0);

            var fd = new FormData(form);
            var xhr = new XMLHttpRequest();
            xhr.open('POST', window.location.pathname, true);
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

            xhr.upload.addEventListener('progress', function (event) {
                if (event.lengthComputable) {
                    showProgress('Hochladen… ' + fmtBytes(event.loaded) + ' / ' + fmtBytes(event.total), Math.round((event.loaded / event.total) * 100));
                }
            });

            xhr.addEventListener('load', function () {
                hideProgress();

                if (xhr.status !== 200) {
                    btnUpload.disabled = false;
                    resetUploadStatus();
                    showNotice('Server-Fehler: HTTP ' + xhr.status, 'error');
                    return;
                }

                var response = parseJson(xhr.responseText);
                if (!response) {
                    btnUpload.disabled = false;
                    resetUploadStatus();
                    showNotice('Ungültige Server-Antwort.', 'error');
                    return;
                }

                if (!response.success) {
                    btnUpload.disabled = false;
                    resetUploadStatus();
                    showNotice(response.error || 'Upload fehlgeschlagen.', 'error');
                    return;
                }

                if (uploadedFile) { uploadedFile.value = response.filename || ''; }
                if (uploadStatus) {
                    uploadStatus.textContent = '✓ ' + (response.filename || '') + (response.size ? ' (' + response.size + ')' : '');
                    uploadStatus.className = 'ci-wizard-step__status ci-wizard-step__status--ok';
                }

                if (previewBtn) { previewBtn.disabled = false; }
                if (submitBtn) { submitBtn.disabled = false; }
                setStepState(2, 'done');
                setStepState(3, 'active');
            });

            xhr.addEventListener('error', function () {
                btnUpload.disabled = false;
                hideProgress();
                resetUploadStatus();
                showNotice('Netzwerkfehler beim Upload.', 'error');
            });

            xhr.send(fd);
        });
    }

    if (previewBtn) {
        previewBtn.addEventListener('click', function () {
            runUploadedAction('preview');
        });
    }

    if (submitBtn) {
        submitBtn.addEventListener('click', function () {
            runUploadedAction('import');
        });
    }

    document.querySelectorAll('.js-folder-import-form').forEach(function (folderForm) {
        folderForm.addEventListener('submit', function (event) {
            event.preventDefault();
            runFolderAction(folderForm, 'import');
        });

        var folderPreviewBtn = folderForm.querySelector('.js-folder-preview-btn');
        if (folderPreviewBtn) {
            folderPreviewBtn.addEventListener('click', function () {
                runFolderAction(folderForm, 'preview');
            });
        }
    });

    function runUploadedAction(mode) {
        var filename = uploadedFile ? uploadedFile.value : '';
        if (!filename) {
            showNotice('Bitte zunächst eine Datei hochladen.', 'error');
            return;
        }

        var fd = new FormData();
        var nonceEl = form.querySelector('[name="_nonce"]');
        fd.append('_nonce', nonceEl ? nonceEl.value : '');
        fd.append('cms_action', mode === 'preview' ? 'cms_importer_preview' : 'cms_importer_folder_import');
        fd.append('import_file', filename);
        fd.append('import_source', 'uploads');
        appendCheckedOptions(fd, form);
        appendSharedAuthorFields(fd);

        setPrimaryBusy(mode, true);
        showProgress(mode === 'preview' ? 'Dry Run wird erstellt…' : 'Importiere…', 10);
        sendActionRequest(fd, mode, noticeEl, function () {
            if (mode === 'preview') {
                setPrimaryBusy(mode, false);
            }
        });
    }

    function runFolderAction(folderForm, mode) {
        syncSharedAuthorFields();

        var importBtn = folderForm.querySelector('.js-folder-import-btn');
        var previewBtnLocal = folderForm.querySelector('.js-folder-preview-btn');
        var notice = document.getElementById('js-folder-notice');

        if (importBtn) { importBtn.disabled = true; }
        if (previewBtnLocal) { previewBtnLocal.disabled = true; }
        if (mode === 'import' && importBtn) { importBtn.textContent = '↻ Importiere…'; }
        if (mode === 'preview' && previewBtnLocal) { previewBtnLocal.textContent = '⏳ Vorschau…'; }

        var fd = new FormData(folderForm);
        fd.set('cms_action', mode === 'preview' ? 'cms_importer_preview' : 'cms_importer_folder_import');
        appendSharedAuthorFields(fd);

        sendActionRequest(fd, mode, notice, function () {
            if (importBtn) {
                importBtn.disabled = false;
                importBtn.textContent = '▶ Importieren';
            }
            if (previewBtnLocal) {
                previewBtnLocal.disabled = false;
                previewBtnLocal.textContent = '👁 Vorschau';
            }
        });
    }

    function sendActionRequest(formData, mode, noticeTarget, onDone) {
        var xhr = new XMLHttpRequest();
        xhr.open('POST', window.location.pathname, true);
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

        xhr.addEventListener('load', function () {
            hideProgress();

            if (xhr.status !== 200) {
                showScopedNotice(noticeTarget, 'Server-Fehler: HTTP ' + xhr.status, 'error');
                if (mode === 'import') { setPrimaryBusy(mode, false); }
                if (typeof onDone === 'function') { onDone(); }
                return;
            }

            var response = parseJson(xhr.responseText);
            if (!response) {
                showScopedNotice(noticeTarget, 'Ungültige Server-Antwort.', 'error');
                if (mode === 'import') { setPrimaryBusy(mode, false); }
                if (typeof onDone === 'function') { onDone(); }
                return;
            }

            if (!response.success) {
                showScopedNotice(noticeTarget, response.error || (mode === 'preview' ? 'Vorschau fehlgeschlagen.' : 'Import fehlgeschlagen.'), 'error');
                if (mode === 'import') { setPrimaryBusy(mode, false); }
                if (typeof onDone === 'function') { onDone(); }
                return;
            }

            var result = response.result || {};
            var message = response.message || (mode === 'preview' ? 'Vorschau erstellt.' : 'Import abgeschlossen.');
            var noticeType = mode === 'preview' ? 'success' : ((result.errors || 0) > 0 ? 'warning' : 'success');
            showScopedNotice(noticeTarget, message, noticeType, result.meta_report_download_url || '', result.meta_report_markdown_url || '');

            if (mode === 'preview') {
                renderPreview(result);
                if (previewPanel) {
                    previewPanel.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            } else {
                hidePreview();
                if (result.total !== undefined) {
                    updateStats(result);
                }
                setTimeout(function () {
                    window.location.reload();
                }, 1800);
            }

            if (mode === 'import') {
                setPrimaryBusy(mode, false);
            }
            if (typeof onDone === 'function') { onDone(); }
        });

        xhr.addEventListener('error', function () {
            hideProgress();
            showScopedNotice(noticeTarget, mode === 'preview' ? 'Netzwerkfehler bei der Vorschau.' : 'Netzwerkfehler beim Import.', 'error');
            if (mode === 'import') { setPrimaryBusy(mode, false); }
            if (typeof onDone === 'function') { onDone(); }
        });

        xhr.send(formData);
    }

    function appendCheckedOptions(targetFormData, sourceForm) {
        sourceForm.querySelectorAll('input[type="checkbox"]').forEach(function (checkbox) {
            if (checkbox.checked) {
                targetFormData.append(checkbox.name, checkbox.value);
            }
        });
    }

    function appendSharedAuthorFields(targetFormData) {
        if (!targetFormData) {
            return;
        }

        var assignedAuthorId = assignedAuthorSelect ? String(assignedAuthorSelect.value || '').trim() : '';
        var authorDisplayName = authorDisplayNameInput ? String(authorDisplayNameInput.value || '').trim() : '';

        if (assignedAuthorId !== '') {
            targetFormData.set('assigned_author_id', assignedAuthorId);
        }

        if (authorDisplayName !== '') {
            targetFormData.set('author_display_name', authorDisplayName);
        }
    }

    function syncSharedAuthorFields() {
        var assignedAuthorId = assignedAuthorSelect ? String(assignedAuthorSelect.value || '') : '';
        var authorDisplayName = authorDisplayNameInput ? String(authorDisplayNameInput.value || '') : '';
        var importOnlyEn = importOnlyEnCheckbox && importOnlyEnCheckbox.checked ? '1' : '0';

        document.querySelectorAll('[data-shared-author-id-target]').forEach(function (input) {
            input.value = assignedAuthorId;
        });

        document.querySelectorAll('[data-shared-author-display-target]').forEach(function (input) {
            input.value = authorDisplayName;
        });

        document.querySelectorAll('[data-shared-en-filter-target]').forEach(function (input) {
            input.value = importOnlyEn;
        });
    }

    function setPrimaryBusy(mode, busy) {
        if (previewBtn) { previewBtn.disabled = busy; }
        if (submitBtn) { submitBtn.disabled = busy; }
        if (previewText) { previewText.hidden = busy && mode === 'preview'; }
        if (previewSpin) { previewSpin.hidden = !(busy && mode === 'preview'); }
        if (submitText) { submitText.hidden = busy && mode === 'import'; }
        if (submitSpin) { submitSpin.hidden = !(busy && mode === 'import'); }
    }

    function setStepState(stepNumber, state) {
        var steps = {
            1: document.getElementById('ci-step-1'),
            2: document.getElementById('ci-step-2'),
            3: document.getElementById('ci-step-3')
        };
        var separators = {
            1: document.getElementById('ci-sep-1'),
            2: document.getElementById('ci-sep-2')
        };

        var step = steps[stepNumber];
        if (!step) {
            return;
        }

        step.className = 'ci-wizard-step' +
            (state === 'active' ? ' ci-wizard-step--active' : state === 'done' ? ' ci-wizard-step--done' : '');

        var separator = separators[stepNumber];
        if (separator) {
            separator.className = 'ci-wizard-sep' + (state === 'done' ? ' ci-wizard-sep--done' : '');
        }
    }

    function activateTab(target) {
        document.querySelectorAll('.ci-tab').forEach(function (tab) {
            tab.classList.toggle('ci-tab--active', tab.dataset.tab === target);
        });
        document.querySelectorAll('.ci-tab-panel').forEach(function (panel) {
            panel.classList.toggle('ci-tab-panel--active', panel.id === 'tab-' + target);
        });
    }

    function showProgress(label, percent) {
        if (progressEl) { progressEl.hidden = false; }
        if (progFill) { progFill.style.width = percent + '%'; }
        if (progLabel) { progLabel.textContent = label; }
    }

    function hideProgress() {
        if (progressEl) { progressEl.hidden = true; }
        if (progFill) { progFill.style.width = '0%'; }
    }

    function resetUploadStatus() {
        if (!uploadStatus) {
            return;
        }
        uploadStatus.textContent = '';
        uploadStatus.className = 'ci-wizard-step__status';
    }

    function showNotice(msg, type, reportUrl, markdownUrl) {
        if (!noticeEl) {
            return;
        }
        var safeType = normalizeNoticeType(type);
        noticeEl.className = 'ci-notice ci-notice--' + safeType;
        renderNoticeContent(noticeEl, getSafeNoticeMessage(safeType));
        logNoticeDebug(msg, safeType, reportUrl, markdownUrl);
        noticeEl.hidden = false;
        noticeEl.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    function showFolderNotice(msg, type, element, reportUrl, markdownUrl) {
        if (!element) {
            return;
        }
        var safeType = normalizeNoticeType(type);
        element.className = 'ci-notice ci-notice--' + safeType;
        renderNoticeContent(element, getSafeNoticeMessage(safeType));
        logNoticeDebug(msg, safeType, reportUrl, markdownUrl);
        element.hidden = false;
    }

    function showScopedNotice(target, msg, type, reportUrl, markdownUrl) {
        if (target === noticeEl) {
            showNotice(msg, type, reportUrl, markdownUrl);
            return;
        }

        showFolderNotice(msg, type, target, reportUrl, markdownUrl);
    }

    function renderNoticeContent(element, msg) {
        if (!element) {
            return;
        }

        element.replaceChildren();
        element.appendChild(document.createTextNode(String(msg || '')));
    }

    function getSafeNoticeMessage(type) {
        switch (String(type || '')) {
            case 'success':
                return 'Vorgang erfolgreich abgeschlossen.';
            case 'warning':
                return 'Vorgang abgeschlossen, aber mit Hinweisen.';
            case 'error':
                return 'Der Vorgang konnte nicht abgeschlossen werden.';
            default:
                return 'Status aktualisiert.';
        }
    }

    function normalizeNoticeType(type) {
        switch (String(type || '')) {
            case 'success':
            case 'warning':
            case 'error':
                return String(type);
            default:
                return 'success';
        }
    }

    function logNoticeDebug(msg, type, reportUrl, markdownUrl) {
        var text = String(msg || '').trim();
        var report = String(reportUrl || '').trim();
        var markdown = String(markdownUrl || '').trim();
        if (!text && !report && !markdown) {
            return;
        }

        if (window.console && typeof window.console.warn === 'function') {
            window.console.warn('[cms-importer][' + String(type || 'info') + ']', {
                message: text,
                reportUrl: report,
                markdownUrl: markdown
            });
        }
    }

    function updateStats(result) {
        var box = document.getElementById('js-stats-box');
        if (!box) {
            return;
        }

        box.hidden = false;
        var values = box.querySelectorAll('.ci-stat__val');
        var data = [
            result.total || 0,
            result.imported || 0,
            result.skipped || 0,
            result.errors || 0,
            result.images_downloaded || 0,
            result.meta_keys || 0
        ];
        values.forEach(function (value, index) {
            if (data[index] !== undefined) {
                value.textContent = data[index];
            }
        });
    }

    function renderPreview(result) {
        if (!previewPanel || !previewSummary || !previewReasons || !previewTableWrap || !previewTableBody || !previewNote) {
            return;
        }

        previewPanel.hidden = false;
        previewSummary.replaceChildren(
            buildPreviewStat('Gesamt', result.total || 0, ''),
            buildPreviewStat('Würde importieren', result.would_import || 0, 'ci-preview-stat--ok'),
            buildPreviewStat('Würde überspringen', result.would_skip || 0, 'ci-preview-stat--warn'),
            buildPreviewStat('Anhänge erkannt', result.attachments || 0, ''),
            buildPreviewStat('Kommentare', result.comments_detected || 0, ''),
            buildPreviewStat('SEO-Settings', ((result.preview_counts && result.preview_counts.settings) || 0), ''),
            buildPreviewStat('Bildkandidaten', result.images_detected || 0, 'ci-preview-stat--img'),
            buildPreviewStat('Meta-Keys offen', result.meta_keys || 0, 'ci-preview-stat--meta')
        );

        renderPreviewReasons(result.skip_reasons || {});
        renderPreviewItems(result.items || []);

        var notes = [];
        if ((result.comments_detected || 0) > 0) {
            notes.push((result.comments_would_import || 0) + ' Kommentare würden übernommen, ' + (result.comments_would_skip || 0) + ' übersprungen');
        }
        if ((result.table_shortcodes_found || 0) > 0) {
            notes.push((result.table_shortcodes_found || 0) + ' WordPress-Tabellen-Shortcodes gefunden, ' + (result.table_shortcodes_resolved || 0) + ' davon aktuell auflösbar');
        }
        if (((result.preview_counts && result.preview_counts.settings) || 0) > 0) {
            notes.push((result.preview_counts.settings || 0) + ' SEO-Settings-Bundle würde in die globalen 365CMS-SEO-Einstellungen geschrieben');
        }
        if (((result.preview_counts && result.preview_counts.redirects) || 0) > 0) {
            notes.push((result.preview_counts.redirects || 0) + ' Redirect-Regeln würden verarbeitet');
        }
        if (result.items_truncated) {
            notes.push('Es werden nur die ersten ' + (result.items_shown || 0) + ' von ' + (result.items_total || 0) + ' Elementen gezeigt');
        }
        previewNote.textContent = notes.join(' | ');
        previewNote.hidden = notes.length === 0;
    }

    function renderPreviewReasons(reasons) {
        var normalizedReasons = {};
        Object.keys(reasons || {}).forEach(function (key) {
            var normalizedKey = (key || '').trim() || 'Unbekannter Überspring-Grund';
            normalizedReasons[normalizedKey] = (normalizedReasons[normalizedKey] || 0) + Number(reasons[key] || 0);
        });

        var keys = Object.keys(normalizedReasons);
        if (!keys.length) {
            previewReasons.hidden = true;
            previewReasons.replaceChildren();
            return;
        }

        var title = createTextElement('h4', 'ci-preview-reasons__title', 'Überspring-Gründe');
        var list = document.createElement('ul');
        list.className = 'ci-preview-reasons__list';

        keys.forEach(function (key) {
            var item = document.createElement('li');
            item.appendChild(createTextElement('span', '', key));
            item.appendChild(createTextElement('strong', '', normalizedReasons[key]));
            list.appendChild(item);
        });
        previewReasons.replaceChildren(title, list);
        previewReasons.hidden = false;
    }

    function renderPreviewItems(items) {
        if (!items.length) {
            previewTableBody.replaceChildren();
            previewTableWrap.hidden = true;
            return;
        }

        var fragment = document.createDocumentFragment();
        items.forEach(function (item) {
            var tagText = Array.isArray(item.tags) ? item.tags.join(', ') : '';
            var reasonText = ((item.reason || '').trim() || (item.action === 'skip' ? 'Unbekannter Überspring-Grund' : (item.target_hint || '')));
            var sourceMetaParts = [item.source_label || item.source_type || ''];
            var detectedLocale = ((item.detected_locale || '').trim() || '').toLowerCase();
            var sourceWpId = Number(item.source_wp_id || 0);
            if (sourceWpId > 0) {
                sourceMetaParts.push('WP-ID ' + sourceWpId);
            }
            if ((item.source_status || '').trim() !== '') {
                sourceMetaParts.push('Status ' + (item.source_status || ''));
            }
            if (detectedLocale) {
                sourceMetaParts.push('Sprache ' + detectedLocale.toUpperCase());
            }
            var hideTargetForLocaleSkip = item.action === 'skip' && reasonText === 'Nur /en/-Inhalte ausgewählt';
            var targetSlugText = hideTargetForLocaleSkip ? '—' : (item.target_slug || '—');
            var targetInfoText = hideTargetForLocaleSkip
                ? (item.target_hint || 'Kein Importziel – vom /en/-Filter ausgeschlossen')
                : (item.target_url || item.target_hint || '');
            var details = [];
            if (item.category) { details.push('Kategorie: ' + item.category); }
            if (tagText) { details.push('Tags: ' + tagText); }
            if (item.author_label) { details.push('365CMS-Autor: ' + item.author_label); }
            if (item.author_display_name) { details.push('Anzeigename im Artikel: ' + item.author_display_name); }
            if (item.image_candidates) { details.push('Bildkandidaten: ' + item.image_candidates); }
            if (item.featured_image) { details.push('Featured: ' + item.featured_image); }
            if (item.table_shortcodes_found) { details.push('Tabellen-Shortcodes: ' + item.table_shortcodes_found + ' / auflösbar: ' + (item.table_shortcodes_resolved || 0)); }
            if (item.table_rows || item.table_columns) { details.push('Tabellenstruktur: ' + (item.table_columns || 0) + ' Spalten, ' + (item.table_rows || 0) + ' Zeilen'); }
            if (item.comments_total) { details.push('Kommentare: ' + item.comments_total + ' / importierbar: ' + (item.comments_importable || 0) + ' / übersprungen: ' + (item.comments_skipped || 0)); }
            if (item.unknown_meta_count) { details.push('Unbekannte Meta-Felder: ' + item.unknown_meta_count); }
            if (Array.isArray(item.table_targets) && item.table_targets.length) { details.push('Kurzcode-Ziele: ' + item.table_targets.join(', ')); }
            if (item.source_comparison) { details.push('Vergleich: ' + item.source_comparison); }
            if (item.redirect_type) { details.push('HTTP-Status: ' + item.redirect_type); }
            if (item.redirect_state) { details.push('Status: ' + item.redirect_state); }
            if (item.redirect_hits) { details.push('Hits: ' + item.redirect_hits); }
            if (item.last_hit_at) { details.push('Letzter Treffer: ' + item.last_hit_at); }
            if (item.settings_keys_count) { details.push('Settings-Schlüssel: ' + item.settings_keys_count); }
            if (Array.isArray(item.settings_labels) && item.settings_labels.length) { details.push('Settings-Felder: ' + item.settings_labels.join(', ')); }

            var row = document.createElement('tr');

            var sourceCell = document.createElement('td');
            sourceCell.appendChild(createTextElement('strong', '', item.source_title || '(ohne Titel)'));
            sourceCell.appendChild(createTextElement('div', 'ci-preview-meta', sourceMetaParts.join(' · ')));

            var targetCell = document.createElement('td');
            targetCell.appendChild(createTextElement('strong', '', item.target_type || ''));
            targetCell.appendChild(createTextElement('div', 'ci-preview-meta', 'Slug: ' + targetSlugText));
            targetCell.appendChild(createTextElement('div', 'ci-preview-meta', targetInfoText));

            var actionCell = document.createElement('td');
            var pill = createTextElement('span', 'ci-preview-pill ' + (item.action === 'import' ? 'ci-preview-pill--ok' : 'ci-preview-pill--warn'), item.action === 'import' ? 'Würde importieren' : 'Würde überspringen');
            actionCell.appendChild(pill);
            actionCell.appendChild(createTextElement('div', 'ci-preview-meta', reasonText));

            var detailCell = document.createElement('td');
            if (details.length) {
                var list = document.createElement('ul');
                list.className = 'ci-preview-details';
                details.forEach(function (detail) {
                    list.appendChild(createTextElement('li', '', detail));
                });
                detailCell.appendChild(list);
            } else {
                detailCell.appendChild(createTextElement('span', 'ci-muted', 'Keine Zusatzdetails'));
            }

            row.appendChild(sourceCell);
            row.appendChild(targetCell);
            row.appendChild(actionCell);
            row.appendChild(detailCell);
            fragment.appendChild(row);
        });

        previewTableBody.replaceChildren(fragment);
        previewTableWrap.hidden = false;
    }

    function hidePreview() {
        if (previewPanel) { previewPanel.hidden = true; }
        if (previewSummary) { previewSummary.replaceChildren(); }
        if (previewReasons) { previewReasons.replaceChildren(); previewReasons.hidden = true; }
        if (previewTableBody) { previewTableBody.replaceChildren(); }
        if (previewTableWrap) { previewTableWrap.hidden = true; }
        if (previewNote) { previewNote.textContent = ''; previewNote.hidden = true; }
    }

    function buildPreviewStat(label, value, extraClass) {
        var item = document.createElement('div');
        item.className = 'ci-preview-stat' + (extraClass ? ' ' + extraClass : '');
        item.appendChild(createTextElement('span', 'ci-preview-stat__value', value));
        item.appendChild(createTextElement('span', 'ci-preview-stat__label', label));
        return item;
    }

    function createTextElement(tagName, className, value) {
        var element = document.createElement(tagName);
        if (className) {
            element.className = className;
        }
        element.textContent = String(value === undefined || value === null ? '' : value);
        return element;
    }

    function parseJson(text) {
        try {
            return JSON.parse(text);
        } catch (_) {
            return null;
        }
    }

    function fmtBytes(bytes) {
        if (bytes < 1024) { return bytes + ' B'; }
        if (bytes < 1048576) { return (bytes / 1024).toFixed(1) + ' KB'; }
        return (bytes / 1048576).toFixed(1) + ' MB';
    }

    function initCleanupModal() {
        if (!cleanupModal || !cleanupActionInput || !cleanupModalTitle || !cleanupModalText || !cleanupSubmit) {
            return;
        }

        document.querySelectorAll('.js-cleanup-trigger').forEach(function (trigger) {
            trigger.addEventListener('click', function () {
                cleanupActionInput.value = trigger.getAttribute('data-cleanup-action') || '';
                cleanupModalTitle.textContent = trigger.getAttribute('data-cleanup-title') || 'Bereinigung bestätigen';
                cleanupModalText.textContent = trigger.getAttribute('data-cleanup-body') || 'Diese Aktion kann nicht rückgängig gemacht werden.';
                if (cleanupResetCheckbox) {
                    cleanupResetCheckbox.checked = false;
                }
                cleanupSubmit.textContent = trigger.getAttribute('data-cleanup-action') === 'cleanup_history'
                    ? 'Verlauf jetzt löschen'
                    : 'Bereinigung jetzt ausführen';
                openCleanupModal();
            });
        });

        cleanupModal.querySelectorAll('[data-close-cleanup-modal]').forEach(function (button) {
            button.addEventListener('click', closeCleanupModal);
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && !cleanupModal.hidden) {
                closeCleanupModal();
            }
        });

        if (cleanupForm) {
            cleanupForm.addEventListener('submit', function () {
                cleanupSubmit.disabled = true;
                cleanupSubmit.textContent = 'Wird ausgeführt…';
            });
        }
    }

    function openCleanupModal() {
        cleanupModal.hidden = false;
        document.body.classList.add('ci-modal-open');
    }

    function closeCleanupModal() {
        cleanupModal.hidden = true;
        document.body.classList.remove('ci-modal-open');
        cleanupSubmit.disabled = false;
        if (cleanupResetCheckbox) {
            cleanupResetCheckbox.checked = false;
        }
    }
})();
