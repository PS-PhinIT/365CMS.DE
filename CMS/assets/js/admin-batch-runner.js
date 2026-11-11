/**
 * Gemeinsamer Fortschritts-Runner für lange Admin-Aufgaben (BatchJobService).
 *
 * Server-Vertrag (POST, FormData): action=<stepAction>, job_id, csrf_token (+ extra Felder)
 * → JSON { success: true, job: { id, status, total, processed, failed, percent, message, errors[], busy } }
 * Fehler: { success: false, error: 'Text' } (HTTP 4xx/5xx erlaubt).
 *
 * Nutzung:
 *   const runner = window.cmsBatchRunner.create({
 *       container: document.querySelector('[data-batch-progress]'),
 *       endpoint: '/admin/search-index', token: '…', stepAction: 'index_step', cancelAction: 'index_cancel',
 *       extra: { mode: 'rebuild' }, onDone: function (job) {}, onProgress: function (job) {}
 *   });
 *   runner.start(jobId);
 */
(function () {
    'use strict';

    var DEFAULT_LABELS = {
        pending: 'Wartet …',
        running: 'Läuft …',
        done: 'Abgeschlossen',
        failed: 'Fehlgeschlagen',
        cancelled: 'Abgebrochen',
        paused: 'Pausiert',
        busy: 'Ein anderer Schritt läuft noch – warte …',
        pause: 'Pausieren',
        resume: 'Fortsetzen',
        cancel: 'Abbrechen',
        cancelTitle: 'Vorgang abbrechen?',
        cancelMessage: 'Bereits verarbeitete Einträge bleiben erhalten. Der Vorgang kann später neu gestartet werden.',
        networkError: 'Verbindung unterbrochen. Der Vorgang ist pausiert und kann fortgesetzt werden.',
        errors: 'Meldungen',
        progress: 'Fortschritt'
    };

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

    function create(options) {
        var opts = options && typeof options === 'object' ? options : {};
        var labels = Object.assign({}, DEFAULT_LABELS, opts.labels || {});
        var container = opts.container;
        var state = { jobId: 0, running: false, paused: false, retries: 0, timer: null, job: null };

        if (!container || !opts.endpoint || !opts.token) {
            throw new Error('cmsBatchRunner: container, endpoint und token sind erforderlich.');
        }

        container.innerHTML = '';
        container.classList.add('cms-batch');
        var bar = el('div', 'progress progress-sm cms-batch__progress');
        bar.setAttribute('role', 'progressbar');
        bar.setAttribute('aria-label', labels.progress);
        bar.setAttribute('aria-valuemin', '0');
        bar.setAttribute('aria-valuemax', '100');
        bar.setAttribute('aria-valuenow', '0');
        var fill = el('div', 'progress-bar');
        fill.style.width = '0%';
        bar.appendChild(fill);
        var meta = el('div', 'cms-batch__meta');
        var statusText = el('span', 'cms-batch__status', '');
        statusText.setAttribute('aria-live', 'polite');
        var countText = el('span', 'cms-batch__count', '');
        meta.appendChild(statusText);
        meta.appendChild(countText);
        var actions = el('div', 'cms-batch__actions');
        var pauseBtn = el('button', 'btn btn-sm btn-outline-secondary', labels.pause);
        pauseBtn.type = 'button';
        var cancelBtn = el('button', 'btn btn-sm btn-outline-danger', labels.cancel);
        cancelBtn.type = 'button';
        actions.appendChild(pauseBtn);
        if (opts.cancelAction) {
            actions.appendChild(cancelBtn);
        }
        var errorsBox = el('details', 'cms-batch__errors');
        errorsBox.hidden = true;
        var errorsSummary = el('summary', '', labels.errors);
        var errorsList = el('ul', 'mb-0');
        errorsBox.appendChild(errorsSummary);
        errorsBox.appendChild(errorsList);
        container.appendChild(bar);
        container.appendChild(meta);
        container.appendChild(actions);
        container.appendChild(errorsBox);

        function render(job, overrideStatus) {
            var percent = Math.max(0, Math.min(100, parseInt(job && job.percent, 10) || 0));
            fill.style.width = percent + '%';
            bar.setAttribute('aria-valuenow', String(percent));
            fill.classList.toggle('bg-danger', !!job && job.status === 'failed');
            fill.classList.toggle('bg-success', !!job && job.status === 'done');
            var status = overrideStatus || (job && job.busy ? 'busy' : (job ? job.status : 'pending'));
            var message = job && job.message ? ' – ' + job.message : '';
            statusText.textContent = (labels[status] || status) + message;
            if (job) {
                var count = job.total > 0 ? job.processed + ' / ' + job.total : String(job.processed || 0);
                countText.textContent = count + (job.failed > 0 ? ' (' + job.failed + ' ✕)' : '') + ' · ' + percent + ' %';
            }
            var errors = job && Array.isArray(job.errors) ? job.errors : [];
            errorsList.innerHTML = '';
            errors.forEach(function (message) {
                errorsList.appendChild(el('li', '', String(message)));
            });
            errorsBox.hidden = errors.length === 0;
            errorsSummary.textContent = labels.errors + ' (' + errors.length + ')';
            var active = !!job && (job.status === 'pending' || job.status === 'running');
            actions.hidden = !active;
            pauseBtn.textContent = state.paused ? labels.resume : labels.pause;
        }

        function post(action) {
            var body = new FormData();
            body.append('action', action);
            body.append('job_id', String(state.jobId));
            body.append('csrf_token', opts.token);
            Object.keys(opts.extra || {}).forEach(function (key) {
                body.append(key, String(opts.extra[key]));
            });

            return fetch(opts.endpoint, {
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

        function schedule(delay) {
            clearTimeout(state.timer);
            state.timer = setTimeout(step, delay);
        }

        function finish(job) {
            state.running = false;
            render(job);
            if (job.status === 'done' && typeof opts.onDone === 'function') {
                opts.onDone(job);
            } else if (job.status !== 'done' && typeof opts.onError === 'function') {
                opts.onError(job);
            }
        }

        function step() {
            if (!state.running || state.paused) {
                return;
            }

            post(opts.stepAction || 'step').then(function (payload) {
                if (!payload || payload.success !== true || !payload.job) {
                    state.running = false;
                    var errorText = payload && payload.error ? String(payload.error) : labels.failed;
                    statusText.textContent = errorText;
                    if (typeof window.cmsToast === 'function') {
                        window.cmsToast('danger', errorText);
                    }
                    if (typeof opts.onError === 'function') {
                        opts.onError(payload || {});
                    }
                    return;
                }

                state.retries = 0;
                state.job = payload.job;
                render(payload.job);
                if (typeof opts.onProgress === 'function') {
                    opts.onProgress(payload.job);
                }

                if (payload.job.status === 'pending' || payload.job.status === 'running') {
                    schedule(payload.job.busy ? 1500 : 50);
                    return;
                }

                finish(payload.job);
            }).catch(function () {
                state.retries += 1;
                if (state.retries <= 3) {
                    schedule(1000 * Math.pow(2, state.retries));
                    return;
                }
                state.paused = true;
                render(state.job, 'paused');
                statusText.textContent = labels.networkError;
            });
        }

        pauseBtn.addEventListener('click', function () {
            state.paused = !state.paused;
            render(state.job, state.paused ? 'paused' : null);
            if (!state.paused) {
                state.running = true;
                state.retries = 0;
                schedule(0);
            }
        });

        cancelBtn.addEventListener('click', function () {
            var doCancel = function () {
                state.paused = true;
                clearTimeout(state.timer);
                post(opts.cancelAction).then(function (payload) {
                    if (payload && payload.job) {
                        finish(payload.job);
                    }
                });
            };
            if (typeof window.cmsConfirm === 'function') {
                window.cmsConfirm({ title: labels.cancelTitle, message: labels.cancelMessage, confirmText: labels.cancel, onConfirm: doCancel });
            }
        });

        return {
            start: function (jobId, initialJob) {
                state.jobId = parseInt(jobId, 10) || 0;
                state.running = state.jobId > 0;
                state.paused = false;
                state.retries = 0;
                state.job = initialJob || null;
                render(state.job || { status: 'pending', percent: 0, processed: 0, total: 0, errors: [] });
                schedule(0);
            },
            pause: function () {
                state.paused = true;
                render(state.job, 'paused');
            },
            resume: function () {
                state.paused = false;
                state.running = true;
                schedule(0);
            },
            isRunning: function () {
                return state.running && !state.paused;
            }
        };
    }

    window.cmsBatchRunner = { create: create };
})();
