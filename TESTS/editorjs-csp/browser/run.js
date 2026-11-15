/**
 * Browser-Test (Playwright/Chromium, ohne Datenbank): Editor.js unter erzwungener Produktiv-CSP
 * (`style-src 'self' 'nonce-…'` ohne 'unsafe-inline').
 *
 * Geprüft wird mit einem `securitypolicyviolation`-Listener, dass keine style-Attribute blockiert werden
 * (`style-src-attr`), und dass das Layout den bisherigen Inline-Werten entspricht:
 *   - EditorJsAssetService::render() mit den Einstellungen von Beitrags-/Seiteneditor, Plugin-Editor
 *     (365NET-eventsandspeaker), Mitgliederprofil und Block-Vorlagen; danach Blöcke über die Schnellleiste einfügen,
 *   - die Editor-Hüllen der Ansichten posts/edit.php und pages/edit.php mit admin-content-editor.js,
 *   - die öffentliche Ausgabe von EditorJsRenderer (alle Block-Varianten mit Stil-Optionen) im Theme cms-default.
 *
 * Aufruf aus dem Repository-Wurzelverzeichnis:
 *   node TESTS/editorjs-csp/browser/run.js [Verzeichnis für Screenshots]
 * Playwright wird über NODE_PATH oder PLAYWRIGHT_MODULE (Pfad zum Paket) gefunden. Exit-Code 0 = alles grün.
 * Nicht Teil von `php TESTS/run-all.php` (braucht Node und Chromium); `TESTS/editorjs-csp/run.php` prüft das Markup ohne Browser.
 */
'use strict';

const crypto = require('crypto');
const fs = require('fs');
const http = require('http');
const path = require('path');
const { execFileSync } = require('child_process');

function loadPlaywright() {
    const candidates = [process.env.PLAYWRIGHT_MODULE, 'playwright', '/opt/node-tools/node_modules/playwright'].filter(Boolean);
    for (const candidate of candidates) {
        try {
            return require(candidate);
        } catch (error) {
            // nächster Kandidat
        }
    }
    console.error('Playwright nicht gefunden (NODE_PATH oder PLAYWRIGHT_MODULE setzen).');
    process.exit(2);
}

const { chromium } = loadPlaywright();
const ROOT = path.resolve(__dirname, '..', '..', '..');
const CMS = path.join(ROOT, 'CMS');
const RENDER = path.join(__dirname, 'render.php');
const MIME = { '.js': 'text/javascript', '.css': 'text/css', '.svg': 'image/svg+xml', '.woff2': 'font/woff2', '.woff': 'font/woff', '.png': 'image/png', '.ttf': 'font/ttf' };
const screens = process.argv[2] ? path.resolve(process.argv[2]) : '';
let failed = 0;
let passed = 0;

function check(condition, label) {
    if (condition) {
        passed++;
        console.log('  ok   ' + label);
    } else {
        failed++;
        console.log('  FAIL ' + label);
    }
}

function php(args) {
    return execFileSync('php', [RENDER].concat(args), { encoding: 'utf8', maxBuffer: 32 * 1024 * 1024 });
}

function escapeAttr(value) {
    return String(value).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;');
}

function svg(width, height, color) {
    return '<svg xmlns="http://www.w3.org/2000/svg" width="' + width + '" height="' + height + '" viewBox="0 0 ' + width + ' ' + height + '"><rect width="' + width + '" height="' + height + '" fill="' + color + '"/></svg>';
}

function editorJsFiles() {
    const source = fs.readFileSync(path.join(CMS, 'core/Services/EditorJs/EditorJsAssetService.php'), 'utf8');
    const match = source.match(/EDITOR_JS_FILES = \[([\s\S]*?)\];/);
    return match ? Array.from(match[1].matchAll(/'([^']+)'/g)).map((m) => 'editorjs/' + m[1]) : [];
}

const ADMIN_CSS = ['tabler/css/tabler.min.css', 'css/admin-tabler.css', 'css/admin.css'];
const publicHtml = php(['public']);
const fixtureJson = php(['fixture']);
const editorContexts = {};
['post', 'page', 'plugin', 'member', 'reusable'].forEach((context) => {
    editorContexts[context] = JSON.parse(php(['editor', context]));
});

function htmlDocument(title, css, body, nonce) {
    // Wie cms_csp_runtime_tags() im Admin-Header: DOMPurify und CSP-Runtime zuerst, mit Nonce.
    return '<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        + '<script src="/assets/dompurify/purify.min.js" nonce="' + nonce + '"></script>'
        + '<script src="/assets/js/cms-csp-runtime.js" nonce="' + nonce + '"></script>'
        + '<title>' + title + '</title>'
        + css.map((href) => '<link rel="stylesheet" href="' + href + '">').join('')
        + '</head><body>' + body.replace(/__CSP_NONCE__/g, nonce) + '</body></html>';
}

/** Editor-Hülle wie in admin/views/posts/edit.php bzw. pages/edit.php, initialisiert durch admin-content-editor.js. */
function editorViewPage(type, nonce) {
    const page = type === 'page';
    const ids = page
        ? { form: 'pageForm', input: 'editorContent', plain: 'pagePlainEditorDe', wrap: 'pagePlainEditorWrapDe' }
        : { form: 'postForm', input: 'contentInput', plain: 'postPlainEditorDe', wrap: 'postPlainEditorWrapDe' };
    const holder = page
        ? '<div class="editorjs-wrap mb-3" id="editorjs_wrap" data-editor-state="loading"><div id="editorjs" class="editorjs-holder editorjs-holder--page"></div></div>'
        : '<div class="editorjs-wrap cms-editor-writing__editor" id="editorjs_wrap" data-editor-state="loading"><div id="editorjs" class="editorjs-holder"></div></div>';
    const editorConfig = {
        formId: ids.form,
        mediaUploadUrl: '/api/media',
        csrfToken: 'test',
        themeTypography: {},
        editors: [{ key: 'de', holderId: 'editorjs', inputId: ids.input, lazy: false, plainTextareaId: ids.plain, plainWrapperId: ids.wrap }]
    };
    const scripts = editorJsFiles().concat(['js/editor-blocks/block-preview.js', 'js/editor-blocks/download-block.js', 'js/editor-blocks/form-block.js', 'js/editor-init.js', 'js/admin-content-editor.js']);
    const css = ADMIN_CSS.map((file) => '/assets/' + file).concat(['/assets/css/admin-post-editor.css']);
    const body = '<div class="page-body"><div class="container-xl">'
        + '<form id="' + ids.form + '" method="post" action="#">'
        + '<input type="hidden" id="' + ids.input + '" name="content" value="' + escapeAttr(fixtureJson) + '">'
        + '<div class="cms-editor-plain-wrap" id="' + ids.wrap + '" hidden><textarea id="' + ids.plain + '" disabled></textarea></div>'
        + holder
        + '</form>'
        + '<input type="hidden" id="contentEditorUiConfig" value="' + escapeAttr(JSON.stringify({ formId: ids.form })) + '">'
        + '<input type="hidden" id="contentEditorEditorJsConfig" value="' + escapeAttr(JSON.stringify(editorConfig)) + '">'
        + '</div></div>'
        + scripts.map((file) => '<script src="/assets/' + file + '" defer></script>').join('');

    return htmlDocument('Editor-Ansicht (' + type + ')', css, body, nonce);
}

function createServer() {
    return http.createServer((request, response) => {
        const url = new URL(request.url, 'http://127.0.0.1');
        const nonce = crypto.randomBytes(16).toString('base64');
        const html = (body) => {
            response.writeHead(200, {
                'Content-Type': 'text/html; charset=utf-8',
                // Direktiven wie Security::getBaseCspDirectives() + Trusted Types (erzwungen, kein 'unsafe-inline');
                // nur frame-src ist ergänzt, damit die Embeds des Beispielinhalts laden.
                'Content-Security-Policy': [
                    "default-src 'self'",
                    "script-src 'self' 'nonce-" + nonce + "'",
                    "style-src 'self' 'nonce-" + nonce + "'",
                    "img-src 'self' data: blob:",
                    "font-src 'self' data:",
                    "connect-src 'self'",
                    "media-src 'self' data: blob:",
                    "frame-src https://embed.test",
                    "object-src 'none'",
                    "frame-ancestors 'none'",
                    "base-uri 'self'",
                    "form-action 'self'",
                    'trusted-types cms365 default sanitize-html dompurify',
                    "require-trusted-types-for 'script'"
                ].join('; ')
            });
            response.end(body);
        };

        if (url.pathname.startsWith('/editor/')) {
            const context = url.pathname.slice('/editor/'.length);
            const entry = editorContexts[context];
            if (entry) {
                const body = '<div class="page-body"><div class="container-xl"><form id="editorForm" method="post" action="#">'
                    + entry.assets + entry.markup + '</form></div></div>';
                return html(htmlDocument('Editor (' + context + ')', ADMIN_CSS.map((file) => '/assets/' + file), body, nonce));
            }
        }
        if (url.pathname === '/editor-view/post' || url.pathname === '/editor-view/page') {
            return html(editorViewPage(url.pathname.split('/').pop(), nonce));
        }
        if (url.pathname === '/public') {
            const body = '<main class="site-main"><div class="page-content-wrap page-content-wrap--detail"><article class="page-article page-article--detail">'
                + '<div class="post-body page-content">' + publicHtml + '</div></article></div></main>';
            return html(htmlDocument('Öffentliche Ausgabe', ['/themes/cms-default/style.css', '/assets/css/editorjs-content.css'], body, nonce));
        }
        if (url.pathname === '/uploads/csp-fixture/photo.svg' || url.pathname === '/uploads/csp-fixture/portrait.svg') {
            response.writeHead(200, { 'Content-Type': 'image/svg+xml' });
            response.end(url.pathname.endsWith('photo.svg') ? svg(1200, 800, '#3b82f6') : svg(600, 900, '#16a34a'));
            return;
        }
        if (url.pathname.startsWith('/assets/') || url.pathname === '/themes/cms-default/style.css') {
            const file = path.normalize(path.join(CMS, decodeURIComponent(url.pathname)));
            if (file.startsWith(CMS + path.sep) && fs.existsSync(file) && fs.statSync(file).isFile()) {
                response.writeHead(200, { 'Content-Type': MIME[path.extname(file)] || 'application/octet-stream' });
                fs.createReadStream(file).pipe(response);
                return;
            }
        }
        response.writeHead(404, { 'Content-Type': 'text/plain' });
        response.end('not found');
    });
}

async function shot(page, name) {
    if (screens) {
        fs.mkdirSync(screens, { recursive: true });
        await page.screenshot({ path: path.join(screens, name + '.png'), fullPage: true });
    }
}

async function violations(page) {
    return page.evaluate(() => window.__cspViolations.slice());
}

function describe(list) {
    return list.slice(0, 5).map((v) => v.directive + ' ' + v.target + ' ' + v.sample + (v.source ? ' @' + v.source : '')).join(' | ');
}

async function checkNoViolations(page, label) {
    const all = await violations(page);
    const styleAttr = all.filter((v) => v.directive === 'style-src-attr');
    const styleElem = all.filter((v) => v.directive === 'style-src-elem' || v.directive === 'style-src');
    check(styleAttr.length === 0, label + ': keine style-src-attr-Verstöße (' + styleAttr.length + ')' + (styleAttr.length ? ' – ' + describe(styleAttr) : ''));
    check(styleElem.length === 0, label + ': keine style-src-elem-Verstöße (' + styleElem.length + ')' + (styleElem.length ? ' – ' + describe(styleElem) : ''));
    const other = all.filter((v) => !styleAttr.includes(v) && !styleElem.includes(v));
    if (other.length) {
        // Nicht Gegenstand dieses Tests (Styles); nur zur Information ausgeben.
        console.log('  info ' + label + ': ' + other.length + ' weitere CSP-Meldung(en): ' + describe(other));
    }
}

(async () => {
    const server = createServer();
    await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
    const base = 'http://127.0.0.1:' + server.address().port;
    const browser = await chromium.launch();
    const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
    await context.route('https://embed.test/**', (route) => route.fulfill({ status: 200, contentType: 'text/html', body: '<!doctype html><title>Embed</title>' }));
    await context.addInitScript(() => {
        window.__cspViolations = [];
        document.addEventListener('securitypolicyviolation', (event) => {
            const target = event.target && event.target.nodeType === 1
                ? event.target.tagName.toLowerCase() + (event.target.id ? '#' + event.target.id : '') + (event.target.className && typeof event.target.className === 'string' ? '.' + event.target.className.trim().split(/\s+/).join('.') : '')
                : String(event.target && event.target.nodeName);
            window.__cspViolations.push({ directive: event.violatedDirective, disposition: event.disposition, sample: String(event.sample || '').replace(/\s+/g, ' ').slice(0, 80), target: target, source: String(event.sourceFile || '').replace(/^https?:\/\/[^/]+/, '') + (event.lineNumber ? ':' + event.lineNumber : '') });
        });
    });
    const page = await context.newPage();
    const pageErrors = [];
    page.on('pageerror', (error) => pageErrors.push(error.message));

    try {
        // Gegenprobe: Der Listener sieht ein blockiertes style-Attribut wirklich (sonst wäre „0 Verstöße“ wertlos).
        console.log('\n# Gegenprobe des Listeners');
        await page.goto(base + '/public');
        await page.evaluate(() => {
            const probe = document.createElement('div');
            probe.innerHTML = '<span style="color:red">x</span>';
            document.body.appendChild(probe);
        });
        await page.waitForFunction(() => window.__cspViolations.length > 0, null, { timeout: 5000 }).catch(() => {});
        const probe = await violations(page);
        check(probe.some((v) => v.directive === 'style-src-attr' && v.disposition === 'enforce'), 'CSP wird erzwungen und ein style-Attribut löst style-src-attr aus');

        // 1) EditorJsAssetService::render()
        for (const name of Object.keys(editorContexts)) {
            const settings = editorContexts[name].settings;
            console.log('\n# Editor über EditorJsAssetService::render() – ' + name);
            await page.goto(base + '/editor/' + name);
            await page.waitForSelector('.editorjs-holder .codex-editor', { timeout: 20000 });
            await page.waitForFunction(() => document.querySelectorAll('.editorjs-holder .ce-block').length > 10, null, { timeout: 20000 });
            const layout = await page.evaluate(() => {
                const wrap = document.querySelector('.editorjs-wrap');
                const holder = document.querySelector('.editorjs-holder');
                const cs = getComputedStyle(wrap);
                const hs = getComputedStyle(holder);
                const block = holder.querySelector('.ce-block__content');
                return {
                    styleAttributes: document.querySelectorAll('[style]').length,
                    width: cs.getPropertyValue('--editorjs-content-width').trim(),
                    expanded: cs.getPropertyValue('--editorjs-content-width-expanded').trim(),
                    padding: cs.getPropertyValue('--editorjs-content-padding-x').trim(),
                    minHeight: hs.minHeight,
                    holderPaddingLeft: hs.paddingLeft,
                    blockMaxWidth: block ? getComputedStyle(block).maxWidth : ''
                };
            });
            const width = Math.max(320, settings.content_width || 1100);
            const expanded = Math.max(width, settings.content_width_expanded || width);
            const padding = Math.max(0, settings.content_padding_x === undefined ? 50 : settings.content_padding_x);
            const minHeight = settings.height || 400;
            check(layout.width === width + 'px', name + ': --editorjs-content-width = ' + width + 'px (' + layout.width + ')');
            check(layout.expanded === expanded + 'px', name + ': --editorjs-content-width-expanded = ' + expanded + 'px (' + layout.expanded + ')');
            check(layout.padding === padding + 'px' && layout.holderPaddingLeft === padding + 'px', name + ': Innenabstand ' + padding + 'px (' + layout.padding + ', ' + layout.holderPaddingLeft + ')');
            check(layout.minHeight === minHeight + 'px', name + ': Mindesthöhe ' + minHeight + 'px (' + layout.minHeight + ')');
            check(layout.blockMaxWidth === width + 'px', name + ': Blockbreite ' + width + 'px (' + layout.blockMaxWidth + ')');

            // Blöcke mit Stil-Optionen über die Schnellleiste einfügen – auch die Tools dürfen keine style-Attribute setzen.
            for (const block of ['image', 'mediaText', 'imageGallery', 'spacer', 'delimiter', 'alert', 'table', 'embed', 'accordion']) {
                const button = page.locator('.editorjs-toolbar button[data-block="' + block + '"]').first();
                if (await button.count()) {
                    await button.click();
                }
            }
            await page.waitForTimeout(500);
            const embeds = await page.evaluate(() => Array.from(document.querySelectorAll('.editorjs-holder iframe.embed-tool__content')).map((frame) => ({
                fullWidth: frame.classList.contains('cms-embed-frame--full-width'),
                ratio: frame.getBoundingClientRect().width / frame.parentElement.getBoundingClientRect().width
            })));
            check(embeds.length >= 8 && embeds.every((embed) => embed.fullWidth && Math.abs(embed.ratio - 1) < 0.01), name + ': Embeds im Editor in voller Breite wie mit style="width:100%" (' + embeds.length + ')');
            await checkNoViolations(page, name);
            await shot(page, 'editor-' + name);
        }

        // 2) Editor-Hüllen der Beitrags- und Seitenansicht
        for (const type of ['post', 'page']) {
            console.log('\n# Editor-Ansicht ' + type + ' (admin-content-editor.js)');
            await page.goto(base + '/editor-view/' + type);
            await page.waitForSelector('#editorjs .codex-editor', { timeout: 20000 });
            await page.waitForFunction(() => document.querySelectorAll('#editorjs .ce-block').length > 10, null, { timeout: 20000 });
            const minHeight = await page.evaluate(() => getComputedStyle(document.getElementById('editorjs')).minHeight);
            if (type === 'page') {
                check(minHeight === '320px', 'page: Mindesthöhe des Editors 320px wie bisher (' + minHeight + ')');
            }
            await page.waitForTimeout(500);
            await checkNoViolations(page, type + '-Ansicht');
            await shot(page, 'editor-view-' + type);
        }

        // 3) Öffentliche Ausgabe
        for (const width of [1280, 390]) {
            console.log('\n# Öffentliche Ausgabe (EditorJsRenderer), ' + width + 'px');
            await page.setViewportSize({ width: width, height: 1000 });
            await page.goto(base + '/public', { waitUntil: 'load' });
            const result = await page.evaluate(() => {
                const px = (value) => Math.round(parseFloat(value) * 100) / 100;
                const root = document.querySelector('.page-content');
                const rem = parseFloat(getComputedStyle(document.documentElement).fontSize);
                const out = { styleAttributes: root.querySelectorAll('[style]').length, spacers: [], embeds: [], indents: [], delimiters: [], images: [], media: [], text: [] };
                root.querySelectorAll('.editorjs-spacer').forEach((el) => out.spacers.push([parseInt(el.dataset.height, 10), px(el.getBoundingClientRect().height)]));
                root.querySelectorAll('.editorjs-embed__frame').forEach((el) => {
                    const r = el.getBoundingClientRect();
                    const iframe = el.querySelector('iframe').getBoundingClientRect();
                    const w = parseInt(el.dataset.width || '640', 10);
                    const h = parseInt(el.dataset.height || '360', 10);
                    out.embeds.push({ expected: Math.round(r.width * h / w * 100) / 100, height: px(r.height), iframe: px(iframe.height) });
                });
                root.querySelectorAll('[data-editorjs-indent]').forEach((el) => out.indents.push([parseInt(el.dataset.editorjsIndent, 10), px(getComputedStyle(el).marginLeft) / rem, el.classList.contains('editorjs-media-text')]));
                root.querySelectorAll('.editorjs-delimiter[data-style="line"]').forEach((el) => {
                    const hr = el.querySelector('hr');
                    out.delimiters.push({ width: parseInt(el.dataset.lineWidth, 10), thickness: parseInt(el.dataset.lineThickness, 10), ratio: Math.round(hr.getBoundingClientRect().width / el.getBoundingClientRect().width * 1000) / 10, border: px(getComputedStyle(hr).borderTopWidth) });
                });
                root.querySelectorAll('.editorjs-image').forEach((el) => {
                    const img = el.querySelector('img');
                    const cs = getComputedStyle(img);
                    out.images.push({ align: el.dataset.align, size: el.dataset.size, fit: el.dataset.imageFit, maxHeight: parseInt(el.dataset.maxHeight, 10), border: el.dataset.border, figureAlign: getComputedStyle(el).textAlign, objectFit: cs.objectFit, cssMaxHeight: cs.maxHeight, height: px(img.getBoundingClientRect().height), borderWidth: px(cs.borderTopWidth), borderColor: cs.borderTopColor, figureWidth: px(el.getBoundingClientRect().width), width: px(img.getBoundingClientRect().width), left: px(img.getBoundingClientRect().left - el.getBoundingClientRect().left - parseFloat(getComputedStyle(el).paddingLeft)), right: px(el.getBoundingClientRect().right - parseFloat(getComputedStyle(el).paddingRight) - img.getBoundingClientRect().right) });
                });
                root.querySelectorAll('.editorjs-media-text').forEach((el) => {
                    const media = el.querySelector('.editorjs-media-text__media');
                    out.media.push({ width: parseInt(el.dataset.imageWidth, 10), ratio: media ? Math.round(media.getBoundingClientRect().width / (el.clientWidth - parseFloat(getComputedStyle(el).paddingLeft) - parseFloat(getComputedStyle(el).paddingRight)) * 1000) / 10 : 0, gap: getComputedStyle(el).columnGap, top: parseInt(el.dataset.spacingTop, 10), marginTop: px(getComputedStyle(el).marginTop), bottom: parseInt(el.dataset.spacingBottom, 10), marginBottom: px(getComputedStyle(el).marginBottom) });
                });
                root.querySelectorAll('.editorjs-paragraph, .editorjs-header').forEach((el) => out.text.push([el.dataset.cmsEditorjsAlign, getComputedStyle(el).textAlign, el.dataset.cmsEditorjsSpacing, px(getComputedStyle(el).marginBottom) / rem]));
                return out;
            });
            const label = 'öffentlich ' + width + 'px';
            check(result.styleAttributes === 0, label + ': keine style-Attribute im gerenderten Inhalt (' + result.styleAttributes + ')');
            check(result.spacers.length === 12 && result.spacers.every(([h, actual]) => Math.abs(Math.min(200, h) - actual) < 0.01), label + ': Abstände in exakter Höhe, auch freie Werte (' + result.spacers.map((s) => s.join('→')).join(', ') + ')');
            check(result.embeds.length === 8 && result.embeds.every((e) => Math.abs(e.expected - e.height) < 0.05 && Math.abs(e.height - e.iframe) < 0.05), label + ': Embeds im Seitenverhältnis width/height, iframe füllt den Rahmen');
            check(result.indents.filter((i) => !i[2]).every(([level, margin]) => Math.abs(level * 1.5 - margin) < 0.01) && result.indents.length === 13, label + ': Einzug 1,5rem je Stufe (' + result.indents.filter((i) => !i[2]).map((i) => i[0] + ':' + i[1]).join(', ') + ')');
            check(result.indents.filter((i) => i[2]).every(([, margin]) => margin === 0), label + ': Bild + Text ignoriert den Einzug wie bisher (Rand per !important)');
            check(result.delimiters.every((d) => Math.abs(d.ratio - d.width) < 0.6 && d.border === d.thickness), label + ': Trennlinien in Breite und Stärke');
            const alignOk = result.images.every((img) => img.figureAlign === img.align
                && (img.align === 'left' ? img.left < 0.5 : img.align === 'right' ? img.right < 0.5 : Math.abs(img.left - img.right) < 1));
            check(alignOk, label + ': Bilder links/mittig/rechts ausgerichtet');
            check(result.images.every((img) => img.objectFit === img.fit), label + ': object-fit je Bild');
            check(result.images.every((img) => img.maxHeight === 0 ? img.cssMaxHeight === '100%' || img.cssMaxHeight === 'none' : img.cssMaxHeight === img.maxHeight + 'px'), label + ': maximale Bildhöhe');
            check(result.images.filter((img) => img.maxHeight > 0 && (img.fit === 'cover' || img.fit === 'fill')).every((img) => Math.abs(img.height - img.maxHeight) < 0.5), label + ': cover/fill mit Höhenbegrenzung in fester Höhe');
            check(result.images.every((img) => img.borderWidth === ({ none: 0, thin: 1, medium: 2, thick: 4 })[img.border]), label + ': Rahmenstärke je Bild');
            check(result.images.filter((img) => img.size === 'full').every((img) => Math.abs(img.width - img.figureWidth) < 1 || img.figureWidth - img.width <= 32.5), label + ': volle Breite');
            const mediaRatioOk = result.media.every((m) => Math.abs(m.ratio - m.width) < 0.6);
            check(mediaRatioOk, label + ': Bild + Text – Bildbreite ' + result.media.map((m) => m.width + '%≈' + m.ratio).join(', '));
            check(result.media.every((m) => m.gap === '24px' && m.marginTop === m.top && m.marginBottom === m.bottom), label + ': Bild + Text – Abstand 24px und Ränder oben/unten');
            const spacing = { compact: 0.25, normal: 0.65, relaxed: 1, loose: 1.6 };
            check(result.text.every(([align, actual, space, margin]) => align === actual && (margin === 0 || Math.abs(margin - spacing[space]) < 0.01)), label + ': Absätze/Überschriften – Ausrichtung und Abstand');
            await checkNoViolations(page, label);
            await shot(page, 'public-' + width);
        }

        check(pageErrors.length === 0, 'keine JavaScript-Fehler (' + pageErrors.slice(0, 3).join(' | ') + ')');
    } catch (error) {
        failed++;
        console.log('  FAIL Abbruch: ' + (error && error.stack ? error.stack : error));
    } finally {
        await browser.close();
        server.close();
    }

    console.log('\n' + passed + ' ok, ' + failed + ' fehlgeschlagen');
    process.exit(failed === 0 ? 0 : 1);
})();
