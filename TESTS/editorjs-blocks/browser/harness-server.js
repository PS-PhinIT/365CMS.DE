/**
 * Statischer Testserver für den Browser-Test der Core-Blöcke „Download“ und „Formular“ (ohne Datenbank).
 *
 * - /assets/*                liefert CMS/assets/* aus,
 * - /editor?type=post|page   Seite mit denselben Skripten in derselben Reihenfolge wie im Admin
 *                            (EditorJsAssetService::EDITOR_JS_FILES, Block-Skripte, editor-init.js,
 *                            admin-content-editor.js; alle per defer) und den Konfigurationsfeldern der Editor-Views,
 *                            `&blocks=0` lässt die Block-Skripte weg, `&cap=0` simuliert fehlende Download-Rechte,
 *                            `&manage=0` das fehlende Recht „Downloads verwalten“,
 * - /layouts                 die vier Download-Templates mit Beispieldaten,
 * - /admin/post-editor-api   Attrappe der Editor-API (Token, Suche, Upload, Formularliste, Vorschau).
 *
 * Die Vorschau rendert die echten Partials über render-partials.php mit Beispieldaten.
 */
'use strict';

const http = require('http');
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');

const ROOT = path.resolve(__dirname, '..', '..', '..');
const CMS = path.join(ROOT, 'CMS');
const MIME = { '.js': 'text/javascript', '.css': 'text/css', '.svg': 'image/svg+xml', '.woff2': 'font/woff2', '.woff': 'font/woff', '.png': 'image/png', '.html': 'text/html' };

function editorJsFiles() {
    const source = fs.readFileSync(path.join(CMS, 'core/Services/EditorJs/EditorJsAssetService.php'), 'utf8');
    const match = source.match(/EDITOR_JS_FILES = \[([\s\S]*?)\];/);
    return match ? Array.from(match[1].matchAll(/'([^']+)'/g)).map((m) => m[1]) : [];
}

function escapeAttr(value) {
    return String(value).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;');
}

function editorPage(type, withBlocks) {
    const page = type === 'page';
    const ids = page
        ? { form: 'pageForm', input: 'editorContent', plain: 'pagePlainEditorDe', wrap: 'pagePlainEditorWrapDe' }
        : { form: 'postForm', input: 'contentInput', plain: 'postPlainEditorDe', wrap: 'postPlainEditorWrapDe' };
    const scripts = editorJsFiles().map((file) => 'editorjs/' + file);
    if (withBlocks) {
        scripts.push('js/editor-blocks/block-preview.js', 'js/editor-blocks/download-block.js', 'js/editor-blocks/form-block.js');
    }
    scripts.push('js/editor-init.js', 'js/admin-content-editor.js');
    const editorConfig = {
        formId: ids.form,
        mediaUploadUrl: '/api/media',
        csrfToken: 'test',
        themeTypography: {},
        editors: [{ key: 'de', holderId: 'editorjs', inputId: ids.input, lazy: false, plainTextareaId: ids.plain, plainWrapperId: ids.wrap }]
    };
    const css = ['tabler/css/tabler.min.css', 'css/admin-tabler.css', 'css/admin.css', 'css/admin-post-editor.css'];

    return '<!doctype html><html lang="de"><head><meta charset="utf-8"><title>Editor-Test (' + type + ')</title>'
        + css.map((file) => '<link rel="stylesheet" href="/assets/' + file + '">').join('')
        + '</head><body><div class="page-body"><div class="container-xl" style="max-width:1100px;padding:24px">'
        + '<form id="' + ids.form + '" method="post" action="#">'
        + '<input type="text" id="title" name="title" value="Testinhalt" class="form-control mb-3">'
        + '<input type="hidden" id="' + ids.input + '" name="content" value="">'
        + '<div class="cms-editor-plain-wrap" id="' + ids.wrap + '" hidden><textarea id="' + ids.plain + '" disabled></textarea></div>'
        + '<div class="editorjs-wrap" id="editorjs_wrap" data-editor-state="loading"><div id="editorjs" class="editorjs-holder"></div></div>'
        + '</form>'
        + '<input type="hidden" id="contentEditorUiConfig" value="' + escapeAttr(JSON.stringify({ formId: ids.form })) + '">'
        + '<input type="hidden" id="contentEditorEditorJsConfig" value="' + escapeAttr(JSON.stringify(editorConfig)) + '">'
        + '</div></div>'
        + scripts.map((file) => '<script src="/assets/' + file + '" defer></script>').join('\n')
        + '</body></html>';
}

const CATEGORIES = [{ id: 3, name: 'Handbücher' }, { id: 5, name: 'Software' }];
const PROPERTY_FIELDS = [
    { key: 'plattform', label: 'Plattform', type: 'select', options: ['Windows', 'macOS', 'Linux'], required: false },
    { key: 'sprache', label: 'Sprache', type: 'text', options: [], required: false },
    { key: 'geprueft', label: 'Geprüft', type: 'boolean', options: [], required: false },
    { key: 'stand', label: 'Stand', type: 'date', options: [], required: false }
];
const ITEMS = [
    { id: 11, title: 'Benutzerhandbuch', status: 'published', access: 'public', type: 'pdf', version: '2.1', size: '1,4 MB', count: 128, versions: ['2.1', '2.0'], category: 3, edit_url: '/admin/downloads?action=edit&id=11' },
    { id: 12, title: 'Installationspaket', status: 'published', access: 'public', type: 'zip', version: '4.0.0', size: '18,2 MB', count: 947, versions: ['4.0.0'], category: 5, edit_url: '/admin/downloads?action=edit&id=12' },
    { id: 13, title: 'Preisliste', status: 'draft', access: 'logged_in', type: 'pdf', version: '', size: '', count: 0, versions: [], category: 0, edit_url: '/admin/downloads?action=edit&id=13' }
];

function renderPreview(data) {
    try {
        return execFileSync('php', [path.join(__dirname, 'render-partials.php'), 'block', JSON.stringify(data)], { encoding: 'utf8' });
    } catch (error) {
        return '<div class="cms-dl-notice cms-dl-notice--warning">Vorschau-Attrappe fehlgeschlagen: ' + escapeAttr(error.message) + '</div>';
    }
}

function readBody(request) {
    return new Promise((resolve) => {
        const chunks = [];
        request.on('data', (chunk) => chunks.push(chunk));
        request.on('end', () => resolve(Buffer.concat(chunks)));
    });
}

function multipartFields(buffer, contentType) {
    // Ohne regulären Ausdruck: der Header kommt aus der Anfrage.
    const marker = 'boundary=';
    const markerAt = String(contentType || '').indexOf(marker);
    const boundary = markerAt === -1 ? '' : String(contentType).slice(markerAt + marker.length).split(';')[0].trim().replace(/^"|"$/g, '');
    const fields = {};
    if (!boundary) {
        return fields;
    }
    buffer.toString('latin1').split('--' + boundary).forEach((part) => {
        const name = (part.match(/name="([^"]+)"/) || [])[1];
        if (!name) {
            return;
        }
        const value = Buffer.from(part.split('\r\n\r\n').slice(1).join('\r\n\r\n').replace(/\r\n$/, ''), 'latin1').toString('utf8');
        const fileName = (part.match(/filename="([^"]*)"/) || [])[1];
        fields[name] = fileName !== undefined ? { fileName: fileName, size: value.length } : value;
    });
    return fields;
}

function create(state) {
    state = state || {};
    state.uploads = [];
    state.fields = PROPERTY_FIELDS.slice();
    state.requests = [];

    return http.createServer(async (request, response) => {
        const url = new URL(request.url, 'http://127.0.0.1');
        const json = (status, payload) => {
            response.writeHead(status, { 'Content-Type': 'application/json; charset=UTF-8' });
            response.end(JSON.stringify(payload));
        };

        if (url.pathname === '/editor') {
            state.cap = url.searchParams.get('cap') !== '0';
            state.manage = url.searchParams.get('manage') !== '0';
            state.uploads = [];
            state.fields = PROPERTY_FIELDS.slice();
            response.writeHead(200, { 'Content-Type': 'text/html; charset=UTF-8' });
            response.end(editorPage(url.searchParams.get('type') === 'page' ? 'page' : 'post', url.searchParams.get('blocks') !== '0'));
            return;
        }
        if (url.pathname === '/layouts') {
            response.writeHead(200, { 'Content-Type': 'text/html; charset=UTF-8' });
            response.end(execFileSync('php', [path.join(__dirname, 'render-partials.php'), 'page'], { encoding: 'utf8' }));
            return;
        }
        if (url.pathname === '/admin/post-editor-api') {
            const action = url.searchParams.get('action') || '';
            state.requests.push(request.method + ' ' + (action || 'POST'));
            if (request.method === 'GET' && action === 'token') {
                return json(200, { success: true, token: 'tok' });
            }
            if (request.method === 'GET' && action === 'form_choices') {
                return json(200, { success: true, forms: [{ key: 'kontakt', title: 'Kontaktformular' }], manage_url: '/admin/forms' });
            }
            if (request.method === 'GET' && action === 'download_search') {
                if (!state.cap) {
                    return json(403, { success: false, error: 'Keine Berechtigung für Downloads.' });
                }
                const q = (url.searchParams.get('q') || '').toLowerCase();
                const category = Number(url.searchParams.get('category') || 0);
                const type = url.searchParams.get('type') || '';
                const ids = url.searchParams.get('ids');
                const all = ITEMS.concat(state.uploads);
                const items = all.filter((item) => (ids ? ids.split(',').map(Number).includes(item.id)
                    : (!q || item.title.toLowerCase().includes(q)) && (!category || item.category === category) && (!type || item.type === type)));
                return json(200, { success: true, items: items, total: items.length, categories: CATEGORIES, file_types: ['pdf', 'zip'], can_manage: state.manage, property_fields: state.fields, allowed_types: ['pdf', 'zip'], max_size: '64 MB' });
            }
            if (request.method === 'POST') {
                const body = await readBody(request);
                const contentType = String(request.headers['content-type'] || '');
                if (contentType.startsWith('multipart/form-data')) {
                    const fields = multipartFields(body, contentType);
                    state.lastUpload = fields;
                    const id = 100 + state.uploads.length;
                    const fileName = fields.file && fields.file.fileName ? fields.file.fileName : 'datei.bin';
                    const item = { id: id, title: fields.title || fileName, status: 'published', access: 'public', type: fileName.split('.').pop(), version: fields.version || '1.0', size: '1 KB', count: 0, versions: [fields.version || '1.0'], category: Number(fields.category_id || 0), edit_url: '/admin/downloads?action=edit&id=' + id };
                    const keys = Object.keys(fields).map((name) => (name.match(/^properties\[([a-z0-9_]+)\]$/) || [])[1]).filter(Boolean);
                    JSON.parse(fields.new_properties || '[]').forEach((entry) => {
                        const key = String(entry.label).toLowerCase().replace(/[^a-z0-9]+/g, '_');
                        state.fields.push({ key: key, label: entry.label, type: entry.type, options: [] });
                        keys.push(key);
                    });
                    item.property_keys = keys;
                    state.uploads.push(item);
                    return json(200, { success: true, item: item, property_fields: state.fields });
                }
                const params = new URLSearchParams(body.toString('utf8'));
                if (params.get('action') === 'block_preview') {
                    const type = params.get('type');
                    const data = JSON.parse(params.get('data') || '{}');
                    state.lastPreview = { type: type, data: data };
                    return json(200, { success: true, html: type === 'download' ? renderPreview(data) : '<div class="cms-form-preview">Formular: ' + escapeAttr(data.formKey || '') + '</div>' });
                }
            }
            return json(400, { success: false, error: 'Unbekannte Aktion.' });
        }
        if (url.pathname.startsWith('/assets/')) {
            const file = path.normalize(path.join(CMS, decodeURIComponent(url.pathname)));
            if (file.startsWith(path.join(CMS, 'assets')) && fs.existsSync(file) && fs.statSync(file).isFile()) {
                response.writeHead(200, { 'Content-Type': MIME[path.extname(file)] || 'application/octet-stream' });
                fs.createReadStream(file).pipe(response);
                return;
            }
        }
        response.writeHead(404, { 'Content-Type': 'text/plain' });
        response.end('not found');
    });
}

module.exports = { create: create };
