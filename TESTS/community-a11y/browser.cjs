/*
 * Browser-Prüfung der Barrierefreiheit der Community (Playwright, Chromium, axe-core): die Seiten stammen aus den echten Vorlagen
 * (von run.php gerendert), die Dateien aus CMS/assets. Je Seite, Darstellung (hell, dunkel) und Fenstergröße (Desktop 1280 px, Mobil 375 px)
 * läuft axe-core mit den Regelgruppen WCAG 2.0/2.1/2.2 A und AA sowie den Empfehlungen (best-practice). Schwere „serious“ und „critical“
 * lassen die Prüfung scheitern (TEST-PLAN: keine ernsten oder kritischen Verstöße); „moderate“ und „minor“ stehen als Hinweis in der Ausgabe.
 * Dazu: Sprunglink, Tastaturreihenfolge mit sichtbarem Fokus, Reflow bei 320 px (WCAG 1.4.10), Melden-Dialog (Fokus hinein, Escape,
 * Fokus zurück) und der Editor mit geladenem SunEditor.
 *
 *   node TESTS/community-a11y/browser.cjs <Verzeichnis mit manifest.json und den Seiten>
 */
const http = require('http');
const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');
const axeSource = fs.readFileSync(require.resolve('axe-core/axe.min.js'), 'utf8');

const dir = process.argv[2];
const manifest = JSON.parse(fs.readFileSync(path.join(dir, 'manifest.json'), 'utf8'));
const assets = path.resolve(__dirname, '../../CMS/assets');
const types = { '.js': 'text/javascript', '.css': 'text/css', '.html': 'text/html; charset=utf-8', '.json': 'application/json', '.woff2': 'font/woff2', '.svg': 'image/svg+xml', '.png': 'image/png' };
const AXE_TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa', 'best-practice'];
const BLOCKING = ['serious', 'critical'];

let checks = 0;
let failures = 0;
const lines = [];
const notes = [];
function check(ok, label, detail) {
    checks++;
    if (!ok) {
        failures++;
        lines.push('  FAIL  ' + label + (detail !== undefined ? '\n        ' + detail : ''));
    }
}

/** Datei lesen oder null (ohne vorherige Prüfung, damit zwischen Prüfen und Lesen nichts dazwischenkommt). */
function readOrNull(file) {
    try {
        return fs.readFileSync(file);
    } catch (error) {
        return null;
    }
}

const server = http.createServer((req, res) => {
    const url = new URL(req.url, 'http://127.0.0.1');
    if (req.method === 'POST') {
        req.resume();
        req.on('end', () => {
            res.writeHead(200, { 'Content-Type': 'application/json' });
            res.end(JSON.stringify({ success: true, html: '<p>Vorschau</p>' }));
        });
        return;
    }
    if (url.pathname.startsWith('/assets/')) {
        const file = path.join(assets, url.pathname.slice('/assets/'.length));
        const body = file.startsWith(assets) ? readOrNull(file) : null;
        if (body !== null) {
            res.writeHead(200, { 'Content-Type': types[path.extname(file)] || 'application/octet-stream' });
            res.end(body);
            return;
        }
        res.writeHead(404);
        res.end();
        return;
    }
    const file = path.join(dir, path.basename(url.pathname));
    const page = readOrNull(file);
    if (page !== null) {
        res.writeHead(200, { 'Content-Type': types[path.extname(file)] || 'text/html; charset=utf-8' });
        res.end(page);
        return;
    }
    res.writeHead(404);
    res.end();
});

/** Kurzform eines axe-Befunds: Regel (Schwere), Zahl der Knoten, erster Ort und die Meldung. */
function describe(violation) {
    const node = violation.nodes[0];
    return violation.id + ' (' + violation.impact + ', ' + violation.nodes.length + ' Knoten): ' + (node.target || []).join(' ') + ' – ' + (node.failureSummary || violation.help).split('\n').slice(0, 2).join(' ').slice(0, 220);
}

async function axe(page, label, options = {}) {
    await page.evaluate(axeSource);
    const result = await page.evaluate((opts) => window.axe.run(document, opts), { runOnly: { type: 'tag', values: AXE_TAGS }, ...options });
    const blocking = result.violations.filter((violation) => BLOCKING.includes(violation.impact));
    check(blocking.length === 0, label + ': keine ernsten oder kritischen axe-Verstöße', blocking.map(describe).join('\n        '));
    for (const violation of result.violations.filter((v) => !BLOCKING.includes(v.impact))) {
        notes.push(label + ': ' + describe(violation));
    }
    return result;
}

async function run() {
    await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
    const base = 'http://127.0.0.1:' + server.address().port;
    const browser = await chromium.launch();
    try {
        for (const scheme of ['light', 'dark']) {
            for (const viewport of [{ label: 'Desktop', width: 1280, height: 800 }, { label: 'Mobil', width: 375, height: 667 }]) {
                const context = await browser.newContext({ viewport: { width: viewport.width, height: viewport.height }, colorScheme: scheme, bypassCSP: true });
                for (const item of manifest) {
                    const page = await context.newPage();
                    const errors = [];
                    page.on('pageerror', (error) => errors.push(String(error)));
                    await page.goto(base + '/' + item.file, { waitUntil: 'load' });
                    if (item.kind === 'editor') {
                        await page.waitForSelector('.sun-editor', { timeout: 10000 }).catch(() => {});
                    }
                    const label = item.name + ' [' + (scheme === 'light' ? 'hell' : 'dunkel') + ', ' + viewport.label + ']';
                    await axe(page, label);
                    check(errors.length === 0, label + ': keine Skriptfehler', errors.join(' | '));
                    await page.close();
                }
                await context.close();
            }
        }

        // Tastatur, Sprunglink, Fokus, Reflow und Dialog an den Seiten, die sie betreffen
        const context = await browser.newContext({ viewport: { width: 1280, height: 800 }, bypassCSP: true });
        for (const item of manifest.filter((entry) => entry.keyboard)) {
            const page = await context.newPage();
            await page.goto(base + '/' + item.file, { waitUntil: 'load' });
            await page.keyboard.press('Tab');
            const first = await page.evaluate(() => {
                const el = document.activeElement;
                const rect = el.getBoundingClientRect();
                return { skip: el.classList.contains('skip-link'), visible: rect.width > 1 && rect.height > 1 && rect.bottom > 0 && rect.right > 0 && rect.top < window.innerHeight };
            });
            check(first.skip, item.name + ': erster Tab-Stopp ist der Sprunglink');
            check(first.visible, item.name + ': der Sprunglink ist bei Fokus sichtbar');
            await page.keyboard.press('Enter');
            await page.keyboard.press('Tab');
            const inMain = await page.evaluate(() => !!document.activeElement && !!document.activeElement.closest('main'));
            check(inMain, item.name + ': nach dem Sprunglink landet der Fokus im Hauptbereich');

            // Fokus ist an den ersten Tab-Stopps sichtbar (Umriss oder Schatten)
            const stops = [];
            for (let i = 0; i < 14; i++) {
                await page.keyboard.press('Tab');
                stops.push(await page.evaluate(() => {
                    const el = document.activeElement;
                    if (!el || el === document.body) {
                        return null;
                    }
                    const style = getComputedStyle(el);
                    const outline = style.outlineStyle !== 'none' && parseFloat(style.outlineWidth) > 0;
                    const shadow = style.boxShadow !== 'none';
                    return { tag: el.tagName.toLowerCase() + (el.id ? '#' + el.id : '') + (el.className && typeof el.className === 'string' ? '.' + el.className.split(' ')[0] : ''), visible: outline || shadow };
                }));
            }
            const hidden = stops.filter((stop) => stop && !stop.visible).map((stop) => stop.tag);
            check(hidden.length === 0, item.name + ': sichtbarer Fokus an den ersten 14 Tab-Stopps', 'ohne Fokusanzeige: ' + hidden.join(', '));

            // Reflow bei 320 px: kein waagerechtes Scrollen (WCAG 1.4.10)
            await page.setViewportSize({ width: 320, height: 640 });
            const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
            check(overflow <= 1, item.name + ': kein waagerechtes Scrollen bei 320 px', 'Überstand ' + overflow + ' px');
            await page.close();
        }

        // Melden-Dialog: Fokus hinein, axe im offenen Dialog, Escape schließt, Fokus kehrt zurück
        for (const item of manifest.filter((entry) => entry.dialog)) {
            const page = await context.newPage();
            await page.goto(base + '/' + item.file, { waitUntil: 'load' });
            const trigger = page.locator('[data-cc-action="report"]').first();
            await trigger.focus();
            await trigger.click();
            await page.waitForSelector('dialog[open]', { timeout: 5000 }).catch(() => {});
            const open = await page.evaluate(() => !!document.querySelector('dialog[open]'));
            check(open, item.name + ': Melden öffnet einen Dialog');
            if (open) {
                const state = await page.evaluate(() => {
                    const dialog = document.querySelector('dialog[open]');
                    return { inside: dialog.contains(document.activeElement), modal: dialog.matches(':modal'), named: !!(dialog.getAttribute('aria-label') || dialog.getAttribute('aria-labelledby') || dialog.querySelector('h1,h2,h3')?.textContent.trim()) };
                });
                check(state.inside, item.name + ': der Fokus liegt im geöffneten Dialog');
                check(state.modal, item.name + ': der Dialog ist modal (Hintergrund inert)');
                check(state.named, item.name + ': der Dialog hat einen Namen');
                await axe(page, item.name + ' (Dialog offen)');
                await page.keyboard.press('Escape');
                await page.waitForFunction(() => !document.querySelector('dialog[open]'), null, { timeout: 3000 }).catch(() => {});
                const closed = await page.evaluate(() => !document.querySelector('dialog[open]'));
                check(closed, item.name + ': Escape schließt den Dialog');
                const back = await page.evaluate(() => document.activeElement && document.activeElement.getAttribute('data-cc-action'));
                check(back === 'report', item.name + ': der Fokus kehrt zur Schaltfläche „Melden“ zurück', 'Fokus: ' + back);
            }
            await page.close();
        }
        await context.close();
    } finally {
        await browser.close();
        server.close();
    }
}

run().then(() => {
    console.log(lines.join('\n'));
    if (notes.length > 0) {
        console.log('Hinweise (nicht blockierend: moderate/minor):\n  ' + [...new Set(notes)].slice(0, 40).join('\n  '));
    }
    console.log(checks + ' Prüfungen, ' + failures + ' Fehler');
    process.exit(failures === 0 ? 0 : 1);
}).catch((error) => {
    console.log(lines.join('\n'));
    console.log('Abbruch: ' + (error && error.stack ? error.stack : error));
    process.exit(2);
});
