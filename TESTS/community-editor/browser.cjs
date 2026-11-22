/*
 * Browser-Prüfung des Community-Editors (Playwright, Chromium): Markdown-Brücke, visueller Editor (SunEditor), Moduswechsel,
 * Zitat-Präfix, Absenden. Die Seite stammt aus der echten Vorlage `views/community/compose.php` (von run.php gerendert) und wird mit
 * dem CSP des CMS ausgeliefert (script-src/style-src mit Nonce, Trusted Types mit der Default-Policy aus cms-csp-runtime.js).
 *
 *   node TESTS/community-editor/browser.cjs <Verzeichnis der gerenderten Seiten>
 */
const http = require('http');
const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');

const pagesDir = process.argv[2];
const assets = path.resolve(__dirname, '../../CMS/assets');
const types = { '.js': 'text/javascript', '.css': 'text/css', '.html': 'text/html; charset=utf-8', '.json': 'application/json', '.woff2': 'font/woff2', '.svg': 'image/svg+xml' };

let failures = 0;
let checks = 0;
const results = [];
function check(ok, label, detail) {
    checks++;
    if (!ok) {
        failures++;
        results.push('  FAIL  ' + label + (detail !== undefined ? '\n        ' + detail : ''));
    }
}

/** Minimaler Ersatz für die Vorschau des Servers: nur die Fälle der Prüfung. */
function previewFor(markdown) {
    const map = { 'Hallo **Welt**': '<p>Hallo <strong>Welt</strong></p>', 'Erste Zeile\n\nZweite *Zeile*': '<p>Erste Zeile</p><p>Zweite <em>Zeile</em></p>' };
    return map[markdown] ?? '<p>' + markdown.replace(/[<>&]/g, '') + '</p>';
}

/**
 * SunEditor setzt für sein Fokus-Hilfsfeld ein style-Attribut; das CSP des CMS (style-src mit Nonce) blockiert es und meldet es in der
 * Konsole. Die Meldung ist bekannt (auch im Admin) und folgenlos (community.css versteckt das Feld); jeder andere Fehler zählt.
 */
const realErrors = (errors) => errors.filter((text) => !/Applying inline style violates/.test(text));

const posted = [];
const server = http.createServer((req, res) => {
    const url = new URL(req.url, 'http://127.0.0.1');
    if (req.method === 'POST') {
        let body = '';
        req.on('data', (chunk) => { body += chunk; });
        req.on('end', () => {
            const params = new URLSearchParams(body);
            if (url.pathname.endsWith('/vorschau')) {
                res.writeHead(200, { 'Content-Type': 'application/json' });
                res.end(JSON.stringify({ success: true, html: previewFor(params.get('body') || '') }));
                return;
            }
            posted.push(Object.fromEntries(params));
            res.writeHead(200, { 'Content-Type': 'text/plain; charset=utf-8' });
            res.end('gespeichert');
        });
        return;
    }
    if (url.pathname.startsWith('/assets/')) {
        const file = path.join(assets, url.pathname.slice('/assets/'.length));
        if (!file.startsWith(assets) || !fs.existsSync(file)) {
            res.writeHead(404);
            res.end();
            return;
        }
        res.writeHead(200, { 'Content-Type': types[path.extname(file)] || 'application/octet-stream' });
        fs.createReadStream(file).pipe(res);
        return;
    }
    const name = url.pathname.replace(/^\//, '') || 'new.html';
    const file = path.join(pagesDir, path.basename(name));
    if (!fs.existsSync(file)) {
        res.writeHead(404);
        res.end();
        return;
    }
    const nonce = require('crypto').randomBytes(12).toString('base64');
    const csp = [
        "default-src 'self'", `script-src 'self' 'nonce-${nonce}'`, `style-src 'self' 'nonce-${nonce}'`, "img-src 'self' data: blob:", "connect-src 'self'",
        "object-src 'none'", "base-uri 'self'", "form-action 'self'",
        'trusted-types cms365 default sanitize-html dompurify', "require-trusted-types-for 'script'",
    ].join('; ');
    const head = `<link rel="stylesheet" href="/assets/css/community.css">
<script src="/assets/dompurify/purify.min.js" nonce="${nonce}"></script>
<script src="/assets/js/cms-csp-runtime.js" nonce="${nonce}"></script>
<script type="module" src="/assets/js/community/community.js"></script>
<script type="module" src="/assets/js/community/editor.js"></script>`;
    const html = '<!doctype html><html lang="de"><head><meta charset="utf-8">' + head + '</head><body>' + fs.readFileSync(file, 'utf8') + '</body></html>';
    res.writeHead(200, { 'Content-Type': types['.html'], 'Content-Security-Policy': csp });
    res.end(html);
});

async function newPage(browser, name, errors) {
    const page = await browser.newPage();
    page.on('console', (m) => { if (m.type() === 'error') { errors.push(m.text()); } });
    page.on('pageerror', (e) => errors.push('pageerror: ' + e.message));
    await page.addInitScript(() => {
        window.__violations = [];
        document.addEventListener('securitypolicyviolation', (e) => window.__violations.push(e.violatedDirective + ' ' + e.blockedURI));
    });
    await page.goto(server.url + '/' + name);
    return page;
}

(async () => {
    await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
    server.url = 'http://127.0.0.1:' + server.address().port;
    const browser = await chromium.launch();

    // ── Markdown-Brücke (reine Funktionen im Browser) ──
    {
        const errors = [];
        const page = await newPage(browser, 'new.html', errors);
        const cases = await page.evaluate(async () => {
            const { htmlToMarkdown, splitQuotePrefix, isVisualSafe, escapeText } = await import('/assets/js/community/md-bridge.js');
            const t = (html) => htmlToMarkdown(html);
            return {
                bold: t('<p>Hallo <strong>Welt</strong> und <em>du</em> <s>nicht</s></p>'),
                para: t('<p>Eins</p><p>Zwei</p>'),
                br: t('<p>A<br>B</p>'),
                heading: t('<h1>Titel</h1><h3>Klein</h3><h6>Winzig</h6>'),
                list: t('<ul><li>a</li><li>b<ul><li>c</li></ul></li></ul><ol><li>x</li><li>y</li></ol>'),
                quote: t('<blockquote><p>Zitat</p><p>Zwei</p></blockquote><p>danach</p>'),
                pre: t('<pre>a\n  b ``` c</pre>'),
                code: t('<p>nutze <code>x &lt; y</code> hier</p>'),
                hr: t('<p>a</p><hr><p>b</p>'),
                link: t('<p><a href="https://example.org/a_b(1)" title="T">Seite</a> <a href="javascript:alert(1)">böse</a> <a href="/forum/neu">rel</a> <a href="//evil.example">proto</a></p>'),
                escape: t('<p>1 * 2 = [x] _a_ `b` <i>c</i> a~b | # kein</p>'),
                lineStart: t('<p># nicht\n- Liste\n1. eins\n&gt; zitat</p>'),
                url: t('<p>siehe https://example.org/x_y_z bitte</p>'),
                drop: t('<p>vor<script>alert(1)</script><style>p{}</style><img src=x onerror=alert(1)><iframe src="//x"></iframe>nach</p>'),
                unwrap: t('<p><span style="color:red">rot</span> <u>unterstrichen</u> <font>f</font></p>'),
                table: t('<table><tr><td>a</td><td>b</td></tr></table>'),
                nbsp: t('<p>a&nbsp;&nbsp;b</p>'),
                empty: t('<p><br></p><p></p>'),
                nested: t('<div><div><p>x</p></div><p>y</p></div>'),
                strongEmpty: t('<p>a<strong> </strong>b</p>'),
                split1: splitQuotePrefix('> [quote=post:5]\n> Text\n> mehr\n\nAntwort hier'),
                split2: splitQuotePrefix('Kein Zitat\n> [quote=post:5]'),
                split3: splitQuotePrefix('> normales Zitat\n\nAntwort'),
                safe: [isVisualSafe('Einfacher *Text*\n\n- a\n- b'), isVisualSafe('| a | b |\n|---|---|'), isVisualSafe('![bild](x)'), isVisualSafe('- [ ] todo'), isVisualSafe('<b>x</b>'), isVisualSafe('    code'), isVisualSafe('Text [quote=post:3]'), isVisualSafe('Satz mit 3 < 4 und a > b')],
                esc: escapeText('a_b *c* https://x.y/a_b'),
            };
        });
        check(cases.bold === 'Hallo **Welt** und *du* ~~nicht~~', 'bold/italic/strike', cases.bold);
        check(cases.para === 'Eins\n\nZwei', 'Absätze', JSON.stringify(cases.para));
        check(cases.br === 'A\\\nB', 'Zeilenumbruch (Backslash)', JSON.stringify(cases.br));
        check(cases.heading === '## Titel\n\n### Klein\n\n#### Winzig', 'Überschriften auf Ebene 2 bis 4 geklemmt', JSON.stringify(cases.heading));
        check(cases.list === '- a\n- b\n  - c\n\n1. x\n2. y', 'Listen mit Verschachtelung', JSON.stringify(cases.list));
        check(cases.quote === '> Zitat\n>\n> Zwei\n\ndanach', 'Zitat', JSON.stringify(cases.quote));
        check(cases.pre === '````\na\n  b ``` c\n````', 'Codeblock mit längerem Zaun', JSON.stringify(cases.pre));
        check(cases.code === 'nutze `x < y` hier', 'Inline-Code', JSON.stringify(cases.code));
        check(cases.hr === 'a\n\n---\n\nb', 'Trennlinie', JSON.stringify(cases.hr));
        check(cases.link.includes('[Seite](https://example.org/a_b%281%29 "T")') && cases.link.includes('[rel](/forum/neu)') && !cases.link.includes('javascript') && !cases.link.includes('evil') && cases.link.includes('böse') && cases.link.includes('proto'), 'Links: nur http/https/mailto/relativ, Klammern kodiert, böse Adressen nur als Text', JSON.stringify(cases.link));
        check(cases.escape === '1 \\* 2 = \\[x\\] \\_a\\_ \\`b\\` *c* a\\~b \\| # kein', 'Sonderzeichen maskiert', JSON.stringify(cases.escape));
        check(cases.lineStart === '\\# nicht\n\\- Liste\n\\1. eins\n\\> zitat' || cases.lineStart.split('\n').every((l) => /^\\/.test(l)), 'Zeilenanfänge maskiert', JSON.stringify(cases.lineStart));
        check(cases.url === 'siehe https://example.org/x_y_z bitte', 'Web-Adressen bleiben unmaskiert', JSON.stringify(cases.url));
        check(cases.drop === 'vornach', 'Skript, Stil, Bild und Frame entfallen', JSON.stringify(cases.drop));
        check(cases.unwrap === 'rot unterstrichen f', 'Unbekannte Inline-Tags werden entfaltet', JSON.stringify(cases.unwrap));
        check(cases.table === 'a b' || cases.table === 'a\n\nb' || (cases.table.includes('a') && cases.table.includes('b') && !cases.table.includes('|')), 'Tabellen werden zu Text', JSON.stringify(cases.table));
        check(cases.nbsp === 'a b', 'geschützte Leerzeichen', JSON.stringify(cases.nbsp));
        check(cases.empty === '', 'leere Absätze ergeben nichts', JSON.stringify(cases.empty));
        check(cases.nested === 'x\n\ny', 'verschachtelte Blöcke', JSON.stringify(cases.nested));
        check(cases.strongEmpty === 'a b', 'leeres Fett ohne Markierung', JSON.stringify(cases.strongEmpty));
        check(cases.split1.prefix === '> [quote=post:5]\n> Text\n> mehr' && cases.split1.rest === 'Antwort hier', 'Zitat-Präfix wird abgetrennt', JSON.stringify(cases.split1));
        check(cases.split2.prefix === '' && cases.split2.rest.startsWith('Kein Zitat'), 'Zitatmarker mitten im Text ist kein Präfix', JSON.stringify(cases.split2));
        check(cases.split3.prefix === '', 'normales Zitat ohne Marker bleibt im Text', JSON.stringify(cases.split3));
        check(JSON.stringify(cases.safe) === JSON.stringify([true, false, false, false, false, false, false, true]), 'isVisualSafe', JSON.stringify(cases.safe));
        check(cases.esc === 'a\\_b \\*c\\* https://x.y/a_b', 'escapeText', cases.esc);
        // Die Fälle „drop“ füttern absichtlich feindliches HTML: DOMPurify parst es in einem inerten Dokument, dabei meldet der Browser die
        // blockierten Handler und Stile des CSP. Das ist Rauschen des Tests, kein Verhalten der Seite.
        const bridgeErrors = realErrors(errors).filter((text) => !/Executing inline event handler violates/.test(text));
        check(bridgeErrors.length === 0, 'Brücke: keine Konsolenfehler', bridgeErrors.join(' | '));
        await page.close();
    }

    // ── Visueller Editor: neues Thema ──
    {
        const errors = [];
        const page = await newPage(browser, 'new.html', errors);
        await page.waitForSelector('.cms-community-editor__visual .sun-editor', { timeout: 15000 });
        check(await page.locator('#cc-body').isHidden(), 'visuell: Markdown-Textfeld ist verborgen');
        check((await page.locator('.cms-community-editor__tabs button[aria-pressed="true"]').textContent()) === 'Visuell', 'visuell: Reiter „Visuell“ aktiv');
        check(await page.locator('#cc-body').evaluate((el) => el.required) === false, 'visuell: verborgenes Textfeld ist nicht required (sonst blockiert die Formularprüfung)');
        await page.fill('input[name="title"]', 'Mein Titel');
        check(await page.evaluate(() => { const el = document.querySelector('.__se__focus__temp__'); return !el || getComputedStyle(el).opacity === '0'; }), 'visuell: das Fokus-Hilfsfeld von SunEditor ist unsichtbar (trotz blockiertem style-Attribut)');
        await page.click('.se-wrapper-wysiwyg');
        await page.keyboard.type('Erster Absatz');
        await page.keyboard.press('Enter');
        await page.keyboard.type('Zweiter a_b ');
        await page.keyboard.press('Control+b');
        await page.keyboard.type('fett');
        await page.waitForTimeout(400);
        const value = (await page.locator('#cc-body').inputValue()).replace(/\r\n/g, '\n');
        check(value === 'Erster Absatz\n\nZweiter a\\_b **fett**', 'visuell: Eingabe landet als Markdown im Textfeld', JSON.stringify(value));
        // Wechsel in die Markdown-Ansicht
        await page.click('.cms-community-editor__tabs button[data-mode="markdown"]');
        check(await page.locator('#cc-body').isVisible(), 'Markdown-Ansicht zeigt das Textfeld');
        check(await page.locator('.cms-community-editor__visual .sun-editor').isHidden(), 'Markdown-Ansicht blendet den Editor aus');
        check(await page.locator('#cc-body').evaluate((el) => el.required) === true, 'Markdown-Ansicht: required wieder gesetzt');
        await page.fill('#cc-body', 'Hallo **Welt**');
        await page.click('.cms-community-editor__tabs button[data-mode="visual"]');
        await page.waitForSelector('.cms-community-editor__visual .sun-editor', { state: 'visible' });
        const html = await page.locator('.se-wrapper-wysiwyg').innerHTML();
        check(/<strong>Welt<\/strong>/.test(html), 'Zurück zu Visuell: Markdown wird über die Vorschau des Servers geladen', html);
        // Absenden
        await page.click('.se-wrapper-wysiwyg');
        await page.keyboard.press('Control+Home');
        await page.keyboard.type('Vorab ');
        posted.length = 0;
        await page.click('button[type="submit"]');
        await page.waitForLoadState('load');
        check(posted.length === 1 && posted[0].body.replace(/\r\n/g, '\n') === 'Vorab Hallo **Welt**' && posted[0].title === 'Mein Titel' && typeof posted[0].csrf_token === 'string', 'Absenden schickt Titel, Token und den Markdown-Text', JSON.stringify(posted));
        // Außer dem bekannten style-Attribut von SunEditor darf das CSP nichts blockieren (Skripte, Trusted Types, Verbindungen).
        const violations = await page.evaluate(() => window.__violations || []);
        const foreign = violations.filter((entry) => !/^style-src/.test(entry));
        check(foreign.length === 0, 'visuell: keine CSP-Verletzung außer dem style-Attribut von SunEditor', foreign.join(' | '));
        check(realErrors(errors).length === 0, 'visuell: keine Konsolenfehler (inklusive Trusted Types)', realErrors(errors).join(' | '));
        await page.close();
    }

    // ── Leeres Absenden im visuellen Modus ──
    {
        const errors = [];
        const page = await newPage(browser, 'new.html', errors);
        await page.waitForSelector('.cms-community-editor__visual .sun-editor', { timeout: 15000 });
        await page.fill('input[name="title"]', 'Nur Titel');
        posted.length = 0;
        await page.click('button[type="submit"]');
        await page.waitForTimeout(300);
        check(posted.length === 0, 'leerer Text wird nicht abgeschickt');
        check(await page.locator('#cc-status').isVisible(), 'leerer Text: Hinweis erscheint');
        check(realErrors(errors).length === 0, 'leer: keine Konsolenfehler', realErrors(errors).join(' | '));
        await page.close();
    }

    // ── Antwort mit Zitat-Präfix ──
    {
        const errors = [];
        const page = await newPage(browser, 'reply.html', errors);
        await page.waitForSelector('.cms-community-editor__visual .sun-editor', { timeout: 15000 });
        check(await page.locator('.cms-community-editor__prefix').isVisible(), 'Antwort: Zitat wird über dem Editor angezeigt');
        const shown = await page.locator('.cms-community-editor__prefix').textContent();
        check(!shown.includes('[quote=post:') && shown.includes('Zitierter Text'), 'Antwort: Markerzeile ist nicht sichtbar, Text schon', shown);
        await page.click('.se-wrapper-wysiwyg');
        await page.keyboard.type('Meine Antwort');
        posted.length = 0;
        await page.click('button[type="submit"]');
        await page.waitForLoadState('load');
        const body = posted[0] && posted[0].body.replace(/\r\n/g, '\n');
        check(body === '> [quote=post:5]\n> Zitierter Text\n\nMeine Antwort', 'Antwort: Zitat-Marker bleibt unverändert vor der Antwort', JSON.stringify(body));
        check(posted[0] && posted[0].quote_of_post_id === '5' && posted[0].seen_last_post_id === '9', 'Antwort: quote_of_post_id und seen_last_post_id bleiben erhalten', JSON.stringify(posted[0]));
        check(realErrors(errors).length === 0, 'Antwort: keine Konsolenfehler', realErrors(errors).join(' | '));
        await page.close();
    }

    // ── Bearbeiten: startet in Markdown, komplexe Quelle bleibt unangetastet ──
    {
        const errors = [];
        const page = await newPage(browser, 'edit.html', errors);
        await page.waitForSelector('.cms-community-editor__tabs');
        check(await page.locator('#cc-body').isVisible(), 'Bearbeiten: startet mit der Markdown-Quelle');
        check((await page.locator('#cc-body').inputValue()).includes('| a | b |'), 'Bearbeiten: Quelle unverändert (Tabelle)');
        await page.click('.cms-community-editor__tabs button[data-mode="visual"]');
        await page.waitForTimeout(500);
        check(await page.locator('#cc-body').isVisible(), 'Bearbeiten: Quelle mit Tabelle wechselt nicht in den visuellen Editor');
        check(await page.locator('#cc-status').isVisible() && (await page.locator('#cc-status').textContent()).includes('Markdown-Ansicht'), 'Bearbeiten: Hinweis auf die Markdown-Ansicht');
        check((await page.locator('#cc-body').inputValue()).includes('| a | b |'), 'Bearbeiten: Text nach dem Versuch unverändert');
        check(realErrors(errors).length === 0, 'Bearbeiten: keine Konsolenfehler', realErrors(errors).join(' | '));
        await page.close();
    }

    // ── Ausfall von SunEditor: Textfeld bleibt bedienbar ──
    {
        const errors = [];
        const page = await browser.newPage();
        await page.route('**/suneditor.min.js', (route) => route.abort());
        page.on('pageerror', (e) => errors.push(e.message));
        await page.goto(server.url + '/new.html');
        await page.waitForSelector('#cc-status:not([hidden])', { timeout: 15000 });
        check(await page.locator('#cc-body').isVisible() && (await page.locator('#cc-body').evaluate((el) => el.required)) === true, 'Ausfall: Markdown-Textfeld bleibt sichtbar und required');
        await page.fill('#cc-body', 'trotzdem');
        posted.length = 0;
        await page.fill('input[name="title"]', 'T');
        await page.click('button[type="submit"]');
        await page.waitForLoadState('load');
        check(posted.length === 1 && posted[0].body === 'trotzdem', 'Ausfall: Absenden funktioniert mit dem Textfeld', JSON.stringify(posted));
        await page.close();
    }

    await browser.close();
    server.close();
    console.log(results.join('\n'));
    console.log(`Prüfungen: ${checks} | Fehlgeschlagen: ${failures}`);
    process.exit(failures === 0 ? 0 : 1);
})().catch((error) => {
    console.error('Abbruch: ' + (error && error.stack ? error.stack : error));
    process.exit(2);
});
