/**
 * Suchindex verwalten: Neuaufbau starten, unterbrochenen Lauf fortsetzen oder abbrechen.
 *
 * Erwartet ein Element [data-search-index] mit JSON-Konfiguration
 * { endpoint, token, activeJob, runnerLabels, labels } und nutzt window.cmsBatchRunner.
 */
(function () {
    'use strict';

    function toast(type, message) {
        if (message && typeof window.cmsToast === 'function') {
            window.cmsToast(type, message);
        }
    }

    function init() {
        var root = document.querySelector('[data-search-index]');
        if (!root || !window.cmsBatchRunner) {
            return;
        }

        var config;
        try {
            config = JSON.parse(root.getAttribute('data-search-index') || '{}');
        } catch (error) {
            return;
        }

        var labels = config.labels || {};
        var startBtn = root.querySelector('[data-search-index-start]');
        var cancelBtn = root.querySelector('[data-search-index-cancel]');
        var notice = root.querySelector('[data-search-index-notice]');
        var latest = root.querySelector('[data-search-index-latest]');
        var progress = root.querySelector('[data-search-index-progress]');
        var activeJob = config.activeJob && config.activeJob.id ? config.activeJob : null;

        if (!startBtn || !progress || !config.endpoint || !config.token) {
            return;
        }

        function post(action, jobId) {
            var body = new FormData();
            body.append('action', action);
            body.append('csrf_token', config.token);
            if (jobId) {
                body.append('job_id', String(jobId));
            }

            return fetch(config.endpoint, {
                method: 'POST',
                body: body,
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
            }).then(function (response) {
                return response.json().catch(function () {
                    return { success: false, error: 'HTTP ' + response.status };
                });
            });
        }

        function showIdle() {
            activeJob = null;
            startBtn.hidden = false;
            startBtn.disabled = false;
            startBtn.textContent = labels.start || startBtn.textContent;
        }

        function run(job) {
            [notice, latest, cancelBtn].forEach(function (node) {
                if (node) {
                    node.hidden = true;
                }
            });
            startBtn.hidden = true;
            progress.hidden = false;

            window.cmsBatchRunner.create({
                container: progress,
                endpoint: config.endpoint,
                token: config.token,
                stepAction: 'step',
                cancelAction: 'cancel',
                labels: config.runnerLabels || {},
                onDone: function () {
                    toast('success', labels.done);
                    window.setTimeout(function () {
                        window.location.reload();
                    }, 1500);
                },
                onError: function () {
                    showIdle();
                }
            }).start(job.id, job);
        }

        startBtn.addEventListener('click', function () {
            var resuming = activeJob !== null;
            startBtn.disabled = true;
            post('start').then(function (payload) {
                if (!payload || payload.success !== true || !payload.job) {
                    startBtn.disabled = false;
                    toast('danger', payload && payload.error ? String(payload.error) : labels.error);
                    return;
                }
                if (payload.resumed && !resuming) {
                    toast('info', labels.resumed);
                }
                run(payload.job);
            }).catch(function () {
                startBtn.disabled = false;
                toast('danger', labels.error);
            });
        });

        if (cancelBtn && activeJob) {
            cancelBtn.addEventListener('click', function () {
                var doCancel = function () {
                    cancelBtn.disabled = true;
                    post('cancel', activeJob.id).then(function (payload) {
                        if (!payload || payload.success !== true) {
                            cancelBtn.disabled = false;
                            toast('danger', payload && payload.error ? String(payload.error) : labels.error);
                            return;
                        }
                        window.location.reload();
                    }).catch(function () {
                        cancelBtn.disabled = false;
                        toast('danger', labels.error);
                    });
                };

                if (typeof window.cmsConfirm === 'function') {
                    window.cmsConfirm({
                        title: labels.cancelTitle,
                        message: labels.cancelMessage,
                        confirmText: labels.cancel,
                        onConfirm: doCancel
                    });
                } else if (window.confirm(labels.cancelMessage || '')) {
                    doCancel();
                }
            });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
