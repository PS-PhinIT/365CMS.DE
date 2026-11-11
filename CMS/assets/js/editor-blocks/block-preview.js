/**
 * Server-Vorschau für Core-Blöcke aus der EditorJsBlockRegistry (z. B. „Download“, „Formular“).
 *
 * Nutzung im Tool-Skript:
 *   window.CMSBlockPreview.render('download', data, tunes).then(function (html) { … });
 *
 * Sendet POST action=block_preview an /admin/post-editor-api. Der Server bereinigt die Daten mit dem
 * Sanitizer des Blocks und rendert ihn im Kontext „preview“. Das CSRF-Token wird bei Bedarf über
 * GET ?action=token geholt und nach Ablauf einmal erneuert.
 */
(function () {
    'use strict';

    var ENDPOINT = '/admin/post-editor-api';
    var token = '';
    var pendingToken = null;

    function readJson(response) {
        return response.json().then(function (json) {
            return { status: response.status, json: json };
        }, function () {
            return { status: response.status, json: null };
        });
    }

    function fetchToken() {
        if (!pendingToken) {
            pendingToken = fetch(ENDPOINT + '?action=token', {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' }
            }).then(readJson).then(function (result) {
                pendingToken = null;
                if (!result.json || !result.json.success || typeof result.json.token !== 'string') {
                    throw new Error((result.json && result.json.error) || 'Sicherheitstoken konnte nicht geladen werden.');
                }
                token = result.json.token;
                return token;
            }, function (error) {
                pendingToken = null;
                throw error;
            });
        }

        return pendingToken;
    }

    function requestPreview(type, data, tunes) {
        var body = new URLSearchParams();
        body.set('action', 'block_preview');
        body.set('csrf_token', token);
        body.set('type', String(type || ''));
        body.set('data', JSON.stringify(data || {}));
        body.set('tunes', JSON.stringify(tunes || {}));

        return fetch(ENDPOINT, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
            body: body
        }).then(readJson);
    }

    function render(type, data, tunes) {
        var attempt = function (mayRetry) {
            return (token ? Promise.resolve(token) : fetchToken())
                .then(function () {
                    return requestPreview(type, data, tunes);
                })
                .then(function (result) {
                    if (mayRetry && result.json && result.json.code === 'csrf') {
                        token = '';
                        return attempt(false);
                    }
                    if (!result.json || !result.json.success) {
                        throw new Error((result.json && result.json.error) || 'Vorschau konnte nicht geladen werden.');
                    }

                    return String(result.json.html || '');
                });
        };

        return attempt(true);
    }

    window.CMSBlockPreview = { render: render };
}());
