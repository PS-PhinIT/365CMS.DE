/**
 * System → Import/Export: Export und Import starten und mit dem gemeinsamen Batch-Runner abarbeiten.
 * Ergebnis: Download-Link des Exports bzw. Bericht des Imports.
 */
(function () {
    'use strict';

    function toast(type, message) {
        if (message && typeof window.cmsToast === 'function') {
            window.cmsToast(type, message);
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        var root = document.getElementById('content-exchange-root');
        if (!root || !window.cmsBatchRunner) {
            return;
        }
        var config;
        try {
            config = JSON.parse(root.getAttribute('data-content-exchange') || '{}');
        } catch (error) {
            return;
        }
        var labels = config.labels || {};

        function post(data) {
            var body = new FormData();
            Object.keys(data).forEach(function (key) {
                var value = data[key];
                if (Array.isArray(value)) {
                    value.forEach(function (item) { body.append(key + '[]', item); });
                } else if (value !== undefined && value !== null) {
                    body.append(key, value);
                }
            });
            body.append('csrf_token', config.token);
            return fetch(config.endpoint, { method: 'POST', body: body, credentials: 'same-origin', headers: { Accept: 'application/json' } })
                .then(function (response) { return response.json(); });
        }

        function formData(form) {
            var data = {};
            new FormData(form).forEach(function (value, key) {
                if (key.slice(-2) === '[]') {
                    key = key.slice(0, -2);
                    (data[key] = data[key] || []).push(value);
                } else {
                    data[key] = value;
                }
            });
            return data;
        }

        function run(kind, job, container, onDone, button) {
            container.hidden = false;
            window.cmsBatchRunner.create({
                container: container,
                endpoint: config.endpoint,
                token: config.token,
                stepAction: 'step',
                cancelAction: 'cancel',
                extra: { kind: kind },
                onDone: onDone,
                onError: function () {
                    if (button) {
                        button.disabled = false;
                    }
                }
            }).start(job.id, job);
        }

        function result(jobId) {
            return post({ action: 'result', job_id: jobId });
        }

        // ── Export ──
        var exportForm = root.querySelector('[data-exchange-export]');
        if (exportForm) {
            var exportButton = exportForm.querySelector('[data-exchange-export-start]');
            var exportProgress = exportForm.querySelector('[data-exchange-export-progress]');
            var exportResult = exportForm.querySelector('[data-exchange-export-result]');
            var exportDone = function (job) {
                result(job.id).then(function (payload) {
                    exportButton.disabled = false;
                    if (!payload || !payload.success || !payload.download_url) {
                        return;
                    }
                    exportResult.hidden = false;
                    exportResult.textContent = '';
                    var text = document.createElement('div');
                    text.className = 'mb-2';
                    text.textContent = labels.exportDone || '';
                    var link = document.createElement('a');
                    link.className = 'btn btn-success';
                    link.href = payload.download_url;
                    link.textContent = labels.download || 'Download';
                    exportResult.appendChild(text);
                    exportResult.appendChild(link);
                    toast('success', labels.exportDone);
                });
            };
            exportForm.addEventListener('submit', function (event) {
                event.preventDefault();
                exportButton.disabled = true;
                exportResult.hidden = true;
                var data = formData(exportForm);
                data.action = 'start_export';
                post(data).then(function (payload) {
                    if (!payload || !payload.success) {
                        exportButton.disabled = false;
                        toast('danger', payload && payload.error ? payload.error : labels.error);
                        return;
                    }
                    if (payload.resumed) {
                        toast('warning', labels.exportResumed);
                    }
                    run('export', payload.job, exportProgress, exportDone, exportButton);
                }).catch(function () {
                    exportButton.disabled = false;
                    toast('danger', labels.error);
                });
            });
            if (config.activeExport && config.activeExport.id) {
                exportButton.disabled = true;
                run('export', config.activeExport, exportProgress, exportDone, exportButton);
            }
        }

        // ── Import ──
        var importForm = root.querySelector('[data-exchange-import]');
        if (importForm) {
            var importButton = importForm.querySelector('[data-exchange-import-start]');
            var importProgress = importForm.querySelector('[data-exchange-import-progress]');
            var importResult = importForm.querySelector('[data-exchange-import-result]');
            var statusLabel = { created: labels.statusCreated, updated: labels.statusUpdated, renamed: labels.statusRenamed, skipped: labels.statusSkipped, failed: labels.statusFailed };
            var importDone = function (job) {
                result(job.id).then(function (payload) {
                    if (!payload || !payload.success) {
                        return;
                    }
                    importResult.hidden = false;
                    importResult.textContent = '';
                    var heading = document.createElement('div');
                    heading.className = 'fw-bold mb-1';
                    heading.textContent = labels.importDone || '';
                    importResult.appendChild(heading);
                    var list = document.createElement('ul');
                    list.className = 'mb-0';
                    Object.keys(payload.report || {}).forEach(function (type) {
                        var counts = payload.report[type] || {};
                        var item = document.createElement('li');
                        item.textContent = (config.typeLabels[type] || type) + ': ' + Object.keys(counts).map(function (key) {
                            return (statusLabel[key] || key) + ' ' + counts[key];
                        }).join(', ');
                        list.appendChild(item);
                    });
                    importResult.appendChild(list);
                    if (importButton) {
                        importButton.hidden = true;
                    }
                    toast('success', labels.importDone);
                });
            };
            importForm.addEventListener('submit', function (event) {
                event.preventDefault();
                if (!importButton) {
                    return;
                }
                importButton.disabled = true;
                var data = formData(importForm);
                data.action = 'start_import';
                data.token = config.importToken;
                post(data).then(function (payload) {
                    if (!payload || !payload.success) {
                        importButton.disabled = false;
                        toast('danger', payload && payload.error ? payload.error : labels.error);
                        return;
                    }
                    run('import', payload.job, importProgress, importDone, importButton);
                }).catch(function () {
                    importButton.disabled = false;
                    toast('danger', labels.error);
                });
            });
            if (config.activeImport && config.activeImport.id && importButton) {
                importButton.disabled = true;
                run('import', config.activeImport, importProgress, importDone, importButton);
            }
        }
    });
})();
