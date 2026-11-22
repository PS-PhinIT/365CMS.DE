/*
 * Lighthouse-Prüfung der Community (Chromium aus Playwright, Lighthouse-Modul): Startseite, Forum und Thema als Mobilgerät (Moto G Power,
 * langsames 4G mit vierfach gedrosselter CPU, so die Standardeinstellung von Lighthouse), je Theme. Gefordert: mindestens `min` Punkte in allen vier Kategorien
 * (Leistung, Barrierefreiheit, Best Practices, SEO; TEST-PLAN: mobil ≥ 90). Die Seiten stammen aus den echten Vorlagen (von run.php gerendert),
 * die Dateien aus CMS/assets, gzip-komprimiert wie auf einem Server; die Seite selbst mit `Cache-Control: private, no-store` wie im Betrieb.
 * Gemessen wird der Beitrag der Community zur Seite (Dokument, community.css, community.js) im Rahmen eines schlanken Themes; Schriften,
 * Bilder und Skripte des echten Themes kommen dazu und sind Sache des Theme-Tests.
 *
 *   node TESTS/community-lighthouse/browser.cjs <Verzeichnis mit manifest.json und den Seiten> [Mindestpunkte, Standard 90]
 */
const http = require('http');
const fs = require('fs');
const path = require('path');
const zlib = require('zlib');
const { chromium } = require('playwright');
const chromeLauncher = require('chrome-launcher');

const dir = process.argv[2];
const min = Number(process.argv[3] || 90);
const manifest = JSON.parse(fs.readFileSync(path.join(dir, 'manifest.json'), 'utf8'));
const assets = path.resolve(__dirname, '../../CMS/assets');
const types = { '.js': 'text/javascript; charset=utf-8', '.css': 'text/css; charset=utf-8', '.html': 'text/html; charset=utf-8', '.json': 'application/json' };
const CATEGORIES = { performance: 'Leistung', accessibility: 'Barrierefreiheit', 'best-practices': 'Best Practices', seo: 'SEO' };

let checks = 0;
let failures = 0;
const lines = [];
const table = [];
const notes = [];
function check(ok, label, detail) {
    checks++;
    if (!ok) {
        failures++;
        lines.push('  FAIL  ' + label + (detail !== undefined ? '\n        ' + detail : ''));
    }
}

function send(req, res, body, type, headers) {
    const accepts = /\bgzip\b/.test(String(req.headers['accept-encoding'] || ''));
    const out = accepts ? zlib.gzipSync(body, { level: 9 }) : body;
    res.writeHead(200, { 'Content-Type': type, 'Content-Length': out.length, ...(accepts ? { 'Content-Encoding': 'gzip' } : {}), Vary: 'Accept-Encoding', ...headers });
    res.end(out);
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
    if (url.pathname === '/favicon.ico') {
        res.writeHead(204); // das Theme liefert das Symbol; ohne Antwort meldete die Konsole einen 404 (Best Practices)
        res.end();
        return;
    }
    if (url.pathname === '/robots.txt') {
        send(req, res, Buffer.from('User-agent: *\nAllow: /\n'), 'text/plain; charset=utf-8', { 'Cache-Control': 'public, max-age=3600' });
        return;
    }
    if (url.pathname.startsWith('/assets/')) {
        const file = path.join(assets, url.pathname.slice('/assets/'.length));
        const body = file.startsWith(assets) ? readOrNull(file) : null;
        if (body !== null) {
            send(req, res, body, types[path.extname(file)] || 'application/octet-stream', { 'Cache-Control': 'public, max-age=31536000, immutable' });
            return;
        }
        res.writeHead(404);
        res.end();
        return;
    }
    const file = path.join(dir, path.basename(url.pathname === '/' ? 'index.html' : url.pathname));
    const page = readOrNull(file);
    if (page !== null) {
        send(req, res, page, 'text/html; charset=utf-8', { 'Cache-Control': 'private, no-store' });
        return;
    }
    res.writeHead(404);
    res.end();
});

/** Befunde mit Punktzahl unter 1 (nur die, die zählen): Kurzfassung für die Fehlermeldung. */
function failing(lhr, category, below = 0.9) {
    return lhr.categories[category].auditRefs
        .filter((ref) => ref.weight > 0)
        .map((ref) => lhr.audits[ref.id])
        .filter((audit) => audit.score !== null && audit.score < below)
        .map((audit) => audit.id + '=' + audit.score + (audit.displayValue ? ' (' + audit.displayValue + ')' : ''))
        .join(', ');
}

async function main() {
    await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
    const base = 'http://127.0.0.1:' + server.address().port;
    // Lighthouse ist ein ES-Modul; `import()` kennt NODE_PATH nicht, die Auflösung über `require.resolve` schon
    const lighthouse = (await import(require('url').pathToFileURL(path.join(path.dirname(require.resolve('lighthouse/package.json')), 'core/index.js')).href)).default;
    const chrome = await chromeLauncher.launch({ chromePath: chromium.executablePath(), chromeFlags: ['--headless=new', '--no-sandbox', '--disable-gpu', '--disable-dev-shm-usage'] });
    try {
        for (const item of manifest) {
            const result = await lighthouse(base + '/' + item.file, { port: chrome.port, output: 'json', logLevel: 'error', onlyCategories: Object.keys(CATEGORIES) });
            const lhr = result.lhr;
            const row = [item.name];
            for (const category of Object.keys(CATEGORIES)) {
                const score = Math.round((lhr.categories[category].score ?? 0) * 100);
                row.push(score);
                check(score >= min, item.name + ': ' + CATEGORIES[category] + ' mobil ≥ ' + min + ' (' + score + ')', 'unter 0,9: ' + failing(lhr, category));
                const missing = failing(lhr, category, 1);
                if (missing !== '') {
                    notes.push(item.name + ' – ' + CATEGORIES[category] + ': ' + missing);
                }
            }
            const metric = (id) => (lhr.audits[id] && lhr.audits[id].displayValue) || '–';
            row.push('FCP ' + metric('first-contentful-paint') + ', LCP ' + metric('largest-contentful-paint') + ', TBT ' + metric('total-blocking-time') + ', CLS ' + metric('cumulative-layout-shift'));
            table.push(row);
            check(!lhr.runtimeError, item.name + ': Lighthouse ohne Laufzeitfehler', lhr.runtimeError ? lhr.runtimeError.message : '');
        }
    } finally {
        await chrome.kill();
        server.close();
    }
}

main().then(() => {
    console.log(lines.join('\n'));
    console.log('Ergebnis (Leistung / Barrierefreiheit / Best Practices / SEO; mobil):\n  ' + table.map((row) => row[0] + ': ' + row.slice(1, 5).join(' / ') + '  [' + row[5] + ']').join('\n  '));
    if (notes.length > 0) {
        console.log('Hinweise (Befunde unter 100 %, nicht blockierend):\n  ' + notes.join('\n  '));
    }
    console.log(checks + ' Prüfungen, ' + failures + ' Fehler');
    process.exit(failures === 0 ? 0 : 1);
}).catch((error) => {
    console.log(lines.join('\n'));
    console.log('Abbruch: ' + (error && error.stack ? error.stack : error));
    process.exit(2);
});
