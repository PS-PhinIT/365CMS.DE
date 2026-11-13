/**
 * Browser-Test (Playwright/Chromium, ohne Datenbank): Core-Blöcke „Download“ und „Formular“ im Beitrags- und
 * Seiteneditor – Blockauswahl, Einfügen, Auswahl-Tab mit Filtern, Upload-Tab mit Kategorie und Eigenschaften,
 * vier Templates.
 *
 * Aufruf aus dem Repository-Wurzelverzeichnis:
 *   node TESTS/editorjs-blocks/browser/run.js [Verzeichnis für Screenshots]
 * Playwright wird über NODE_PATH oder PLAYWRIGHT_MODULE (Pfad zum Paket) gefunden. Exit-Code 0 = alles grün.
 * Nicht Teil von `php TESTS/run-all.php` (braucht Node und Chromium).
 */
'use strict';

const fs = require('fs');
const os = require('os');
const path = require('path');
const harness = require('./harness-server.js');

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

async function shot(target, name) {
    if (screens) {
        fs.mkdirSync(screens, { recursive: true });
        await target.screenshot({ path: path.join(screens, name + '.png') });
    }
}

async function openEditor(browser, base, query) {
    const page = await browser.newPage({ viewport: { width: 1280, height: 1100 }, deviceScaleFactor: 2 });
    page.errors = [];
    page.on('pageerror', (error) => { page.errors.push(error.message); if (process.env.DEBUG_ERRORS) { console.log('  [pageerror nach ' + passed + ' Prüfungen] ' + error.stack); } });
    await page.goto(base + '/editor?' + query);
    await page.waitForSelector('#editorjs .codex-editor', { timeout: 20000 });
    await page.waitForSelector('#editorjs_wrap .cms-editor-commandbar', { timeout: 20000 });
    return page;
}

async function blockChoices(page) {
    return page.evaluate(() => ({
        panel: Array.from(document.querySelectorAll('.cms-editor-inserter-panel [data-block]')).map((b) => b.dataset.block),
        quick: Array.from(document.querySelectorAll('.cms-editor-toolbar-grouped [data-block]')).map((b) => b.dataset.block),
        registered: (window.CMS_EDITORJS_BLOCKS || []).map((b) => b.name)
    }));
}

// Die Blockauswahl bleibt nach dem Einfügen offen und überdeckt den Editor.
async function closeInserter(page) {
    if (await page.locator('.cms-editor-inserter-panel').isVisible()) {
        await page.click('.cms-editor-commandbar [data-role="inserter"]');
    }
}

async function savedBlocks(page) {
    await page.waitForTimeout(400);
    return page.evaluate(() => {
        const input = document.querySelector('#contentInput, #editorContent');
        try {
            return JSON.parse(input.value || '{}').blocks || [];
        } catch (error) {
            return [];
        }
    });
}

(async () => {
    const state = {};
    const server = harness.create(state);
    await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
    const base = 'http://127.0.0.1:' + server.address().port;
    const browser = await chromium.launch();
    // Eigenes Verzeichnis mit zufälligem Namen (0700) statt einer vorhersagbaren Datei im gemeinsamen Temp-Verzeichnis.
    const uploadDir = fs.mkdtempSync(path.join(os.tmpdir(), 'cms-dl-block-'));
    const upload = path.join(uploadDir, 'cms-dl-block-test.pdf');
    fs.writeFileSync(upload, '%PDF-1.4 test');

    try {
        for (const type of ['post', 'page']) {
            console.log('\n== Editor: ' + (type === 'post' ? 'Beitrag' : 'Seite'));
            const page = await openEditor(browser, base, 'type=' + type);
            const choices = await blockChoices(page);
            check(choices.registered.includes('download') && choices.registered.includes('form'), 'Tool-Skripte haben sich angemeldet');
            check(choices.panel.includes('download') && choices.panel.includes('form'), '„+ Block hinzufügen“ enthält Download und Formular');
            check(choices.quick.includes('download'), 'Schnellleiste enthält Download');

            // Natives „+“-Menü von Editor.js
            await page.click('#editorjs .codex-editor__redactor');
            await page.click('#editorjs .ce-toolbar__plus', { force: true });
            await page.waitForSelector('.ce-popover--opened .ce-popover-item', { state: 'attached' });
            const native = await page.evaluate(() => Array.from(document.querySelectorAll('.ce-popover--opened .ce-popover-item')).map((i) => i.dataset.itemName));
            check(native.includes('download') && native.includes('form'), 'Editor.js-Toolbox enthält download und form');
            await page.keyboard.press('Escape');

            // Blockauswahl öffnen und Download einfügen
            await page.click('.cms-editor-commandbar [data-role="inserter"]');
            await page.waitForSelector('.cms-editor-inserter-panel:not([hidden]) [data-block="download"]');
            if (type === 'post') {
                await shot(page, '01-blockauswahl-beitrag');
            } else {
                await shot(page, '01-blockauswahl-seite');
            }
            await page.click('.cms-editor-inserter-panel [data-block="download"]');
            await closeInserter(page);
            await page.waitForSelector('.cms-editorjs-tool--download .cms-dl-block__results li');
            check(true, 'Download-Block eingefügt, Trefferliste geladen');
            const block = page.locator('.cms-editorjs-tool--download').first();

            // Auswahl-Tab: Suche, Kategorie, Dateityp
            const rows = () => block.locator('.cms-dl-block__results li').count();
            check(await rows() === 3, 'alle drei Beispiel-Downloads gelistet');
            await block.locator('select[aria-label="Kategorie"]').selectOption('5');
            await page.waitForFunction(() => document.querySelectorAll('.cms-dl-block__results li').length === 1);
            check((await block.locator('.cms-dl-block__results li').first().textContent()).includes('Installationspaket'), 'Kategorie-Filter wirkt');
            await block.locator('select[aria-label="Kategorie"]').selectOption('');
            await block.locator('select[aria-label="Dateityp"]').selectOption('pdf');
            await page.waitForFunction(() => document.querySelectorAll('.cms-dl-block__results li').length === 2);
            check(true, 'Dateityp-Filter wirkt');
            await block.locator('select[aria-label="Dateityp"]').selectOption('');
            await block.locator('input[type="search"]').fill('handbuch');
            await page.waitForFunction(() => document.querySelectorAll('.cms-dl-block__results li').length === 1);
            check(true, 'Suche wirkt');
            await block.locator('.cms-dl-block__results input').first().check();
            await page.waitForSelector('.cms-editorjs-download-block__preview .cms-dl--card');
            check(true, 'Auswahl rendert die Server-Vorschau (Karte)');
            if (type === 'post') {
                await shot(page, '02-auswahl-tab');
            }

            // Vier Templates
            const templates = await block.locator('select[aria-label="Template"] option').evaluateAll((options) => options.map((o) => o.value));
            check(JSON.stringify(templates) === JSON.stringify(['compact', 'card', 'featured', 'button']), 'vier Templates wählbar: ' + templates.join(', '));
            for (const template of ['compact', 'featured', 'button']) {
                await block.locator('select[aria-label="Template"]').selectOption(template);
                await page.waitForSelector('.cms-editorjs-download-block__preview .cms-dl--' + template);
                check(true, 'Vorschau im Template ' + template);
                if (type === 'post') {
                    await shot(block, '04-vorschau-im-editor-' + template);
                }
            }
            let blocks = await savedBlocks(page);
            let saved = blocks.find((b) => b.type === 'download');
            check(!!saved && saved.data.template === 'button' && JSON.stringify(saved.data.downloadIds) === '[11]', 'gespeicherte Blockdaten: Template button, Download 11');

            // Upload-Tab mit Kategorie und Eigenschaften
            await block.locator('button[role="tab"]', { hasText: 'Hochladen' }).click();
            const pane = block.locator('.cms-dl-block__pane').nth(1);
            await pane.locator('input[type="file"]').setInputFiles(upload);
            await pane.getByLabel('Titel').fill('Testdatei');
            await pane.getByLabel('Kurzbeschreibung').fill('Kurz beschrieben.');
            const categories = await pane.getByLabel('Kategorie').locator('option').allTextContents();
            check(categories.join('|') === 'Ohne Kategorie|Handbücher|Software', 'Kategorie-Auswahl im Upload: ' + categories.join(', '));
            await pane.getByLabel('Kategorie').selectOption('3');
            check(await pane.locator('.cms-dl-block__props .cms-dl-block__field').count() === 4, 'vier vorhandene Eigenschaftsfelder angeboten');
            await pane.getByLabel('Plattform').selectOption('Linux');
            await pane.getByLabel('Sprache').fill('Deutsch');
            await pane.locator('button', { hasText: 'Neue Eigenschaft' }).click();
            await pane.getByLabel('Bezeichnung').fill('Lizenznummer');
            await pane.getByLabel('Wert').fill('AB-123');
            if (type === 'post') {
                await shot(page, '03-upload-tab-kategorie-eigenschaften');
            }
            await pane.locator('button', { hasText: 'Hochladen und einfügen' }).click();
            await page.waitForFunction(() => /wurde angelegt/.test(document.querySelector('.cms-editorjs-tool--download').textContent));
            const sent = state.lastUpload || {};
            check(sent.title === 'Testdatei' && sent.summary === 'Kurz beschrieben.' && sent.category_id === '3', 'Upload sendet Titel, Kurzbeschreibung, Kategorie');
            check(sent['properties[plattform]'] === 'Linux' && sent['properties[sprache]'] === 'Deutsch' && sent['properties[geprueft]'] === undefined, 'Upload sendet nur ausgefüllte Eigenschaften');
            check(sent.new_properties === JSON.stringify([{ label: 'Lizenznummer', type: 'text', value: 'AB-123' }]), 'Upload sendet die neue Eigenschaft');
            check(!!sent.csrf_token && !!sent.file && sent.file.fileName === 'cms-dl-block-test.pdf', 'Upload sendet Datei und CSRF-Token');
            blocks = await savedBlocks(page);
            saved = blocks.find((b) => b.type === 'download');
            check(!!saved && saved.data.downloadIds[0] >= 100 && JSON.stringify(saved.data.options.showProperties) === '["plattform","sprache","lizenznummer"]', 'hochgeladener Download ist ausgewählt, Eigenschaften werden angezeigt');

            // Formular-Block
            await page.click('.cms-editor-commandbar [data-role="inserter"]');
            await page.click('.cms-editor-inserter-panel [data-block="form"]');
            await page.waitForSelector('.cms-editorjs-tool--form, [class*="form-block"]');
            check(true, 'Formular-Block eingefügt');
            // Bekannt und unabhängig von diesen Blöcken (tritt z. B. auch mit „Abstand“ auf): Editor.js ermittelt den
            // aktuellen Block über die DOM-Position; die „+“-Schaltflächen zwischen den Blöcken verschieben sie.
            const errors = page.errors.filter((message) => !/updateCurrentInput/.test(message));
            check(errors.length === 0, 'keine JavaScript-Fehler der Blöcke' + (errors.length ? ': ' + errors.join(' | ') : ''));
            await page.close();
        }

        console.log('\n== Sonderfälle');
        let page = await openEditor(browser, base, 'type=post&manage=0');
        await page.click('.cms-editor-commandbar [data-role="inserter"]');
        await page.click('.cms-editor-inserter-panel [data-block="download"]');
        await closeInserter(page);
        await page.waitForSelector('.cms-editorjs-tool--download .cms-dl-block__results li');
        await page.locator('.cms-editorjs-tool--download button[role="tab"]', { hasText: 'Hochladen' }).click();
        check(!(await page.locator('.cms-editorjs-tool--download button', { hasText: 'Neue Eigenschaft' }).isVisible()), 'ohne „Downloads verwalten“ kein Anlegen neuer Eigenschaften');
        await page.close();

        page = await openEditor(browser, base, 'type=post&blocks=0');
        const without = await blockChoices(page);
        check(!without.panel.includes('download') && !without.panel.includes('form'), 'ohne Tool-Skripte bietet die Blockauswahl Download/Formular nicht an');
        await page.close();

        console.log('\n== Layouts');
        page = await browser.newPage({ viewport: { width: 900, height: 1200 }, deviceScaleFactor: 2 });
        await page.goto(base + '/layouts');
        for (const template of ['compact', 'card', 'featured', 'button']) {
            const section = page.locator('section[data-template="' + template + '"]');
            check(await section.locator('.cms-dl--' + template).count() === 1, 'Template ' + template + ' gerendert');
            await shot(section, '05-layout-' + template);
        }
        const buttonBox = await page.locator('.cms-dl--button .cms-dl__button').boundingBox();
        const buttonText = await page.locator('.cms-dl--button .cms-dl__button').innerText();
        check(/Herunterladen/.test(buttonText) && /benutzerhandbuch-2\.1\.pdf/.test(buttonText) && /1,4 MB/.test(buttonText), 'Schaltfläche zeigt Beschriftung, Dateiname und Größe');
        check(!!buttonBox && buttonBox.height < 80 && await page.locator('.cms-dl--button .cms-dl__title, .cms-dl--button .cms-dl__meta').count() === 0, 'Schaltfläche besteht nur aus dem Button');
        if (screens) {
            await page.screenshot({ path: path.join(screens, '05-layouts-alle.png'), fullPage: true });
        }
        await page.close();
    } catch (error) {
        failed++;
        console.log('  FAIL Abbruch: ' + error.message.split('\n')[0]);
    } finally {
        await browser.close();
        server.close();
        fs.rmSync(uploadDir, { recursive: true, force: true });
    }

    console.log('\n' + passed + ' bestanden, ' + failed + ' fehlgeschlagen');
    process.exit(failed === 0 ? 0 : 1);
})();
