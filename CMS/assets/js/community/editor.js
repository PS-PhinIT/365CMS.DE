/*
 * Community-Editor (4.2.0): visueller Editor (SunEditor, lokal gebündelt) für das Schreibformular des Forums.
 *
 * Progressive Erweiterung: ohne dieses Skript bleibt das Textfeld `#cc-body` mit Markdown. Das Forum speichert weiter Markdown; der visuelle
 * Editor schreibt über `md-bridge.js` in dasselbe Textfeld zurück, der Server rendert und bereinigt wie bisher (Vorschau, Zitate,
 * Bearbeitungsverlauf, Suche ändern sich nicht). Zwei Ansichten: „Visuell“ und „Markdown“ (Quelle, ohne Verlust). Was die Werkzeugleiste
 * nicht kennt (Tabellen, Bilder, Zitatmarker, Roh-HTML), bleibt in der Markdown-Ansicht; ein führendes Zitat (`?zitat=`) bleibt unverändert
 * und wird über dem Editor angezeigt. Beim Absenden wird der Stand zurückgeschrieben. Fehlt SunEditor oder schlägt etwas fehl, bleibt das
 * Textfeld bedienbar. Kein Inline-Skript, keine externen Ressourcen; Skripte und Stile kommen vom eigenen Server.
 */
import { htmlToMarkdown, isVisualSafe, splitQuotePrefix } from './md-bridge.js';

const root = document.querySelector('[data-cc-editor]');
const textarea = document.getElementById('cc-body');
const form = textarea ? textarea.closest('form') : null;

let editor = null;
let mode = 'markdown';
let prefix = '';
let ui = null;
const wasRequired = textarea ? textarea.required : false;

function say(text, isError) {
    const status = document.getElementById('cc-status');
    if (status) {
        status.textContent = text || '';
        status.setAttribute('role', isError ? 'alert' : 'status');
        status.hidden = !text;
    }
}

/** Stellt das einfache Textfeld wieder her (Fehler oder Wechsel in die Markdown-Ansicht). */
function restore() {
    textarea.hidden = false;
    textarea.required = wasRequired;
    textarea.removeAttribute('aria-hidden');
    if (ui) {
        ui.visualHost.hidden = true;
        ui.prefixBox.hidden = true;
    }
    mode = 'markdown';
    if (ui) {
        ui.tabs.forEach((tab) => tab.setAttribute('aria-pressed', tab.dataset.mode === 'markdown' ? 'true' : 'false'));
    }
}

function loadStyle(href) {
    return new Promise((resolve, reject) => {
        const link = document.createElement('link');
        link.rel = 'stylesheet';
        link.href = href;
        link.addEventListener('load', () => resolve());
        link.addEventListener('error', () => reject(new Error('css')));
        document.head.appendChild(link);
    });
}

function loadScript(src) {
    return new Promise((resolve, reject) => {
        const script = document.createElement('script');
        script.src = src;
        script.addEventListener('load', () => resolve());
        script.addEventListener('error', () => reject(new Error('js')));
        document.head.appendChild(script);
    });
}

async function loadSunEditor() {
    if (window.SUNEDITOR) {
        return window.SUNEDITOR;
    }
    const { cssUrl, jsUrl, langUrl } = root.dataset;
    await Promise.all([loadStyle(cssUrl), loadScript(jsUrl)]);
    if (langUrl) {
        await loadScript(langUrl).catch(() => undefined);
    }
    if (!window.SUNEDITOR || typeof window.SUNEDITOR.create !== 'function') {
        throw new Error('SunEditor fehlt');
    }

    return window.SUNEDITOR;
}

async function previewHtml(markdown) {
    const api = root.dataset.ccApi;
    const token = root.dataset.ccToken;
    const body = new URLSearchParams({ body: markdown, [root.dataset.targetKey]: root.dataset.targetId, csrf_token: token });
    const response = await fetch(`${api}vorschau`, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'X-CSRF-Token': token, 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
        body,
    });
    const json = await response.json();
    if (!json.success) {
        throw new Error(json.message || 'Vorschau nicht möglich');
    }

    return String(json.html || '');
}

function buildUi() {
    const wrapper = document.createElement('div');
    wrapper.className = 'cms-community-editor';
    const tabsBar = document.createElement('div');
    tabsBar.className = 'cms-community-editor__tabs';
    tabsBar.setAttribute('role', 'group');
    tabsBar.setAttribute('aria-label', 'Ansicht des Editors');
    const tabs = [['visual', 'Visuell'], ['markdown', 'Markdown']].map(([key, label]) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.dataset.mode = key;
        button.textContent = label;
        button.setAttribute('aria-pressed', 'false');
        tabsBar.appendChild(button);

        return button;
    });
    const prefixBox = document.createElement('blockquote');
    prefixBox.className = 'cms-community-editor__prefix';
    prefixBox.hidden = true;
    const visualHost = document.createElement('div');
    visualHost.className = 'cms-community-editor__visual';
    visualHost.hidden = true;
    const visualArea = document.createElement('textarea');
    visualArea.id = 'cc-body-visual';
    visualArea.setAttribute('aria-label', 'Text');
    visualHost.appendChild(visualArea);
    wrapper.append(tabsBar, prefixBox, visualHost);
    textarea.parentNode.insertBefore(wrapper, textarea);

    return { wrapper, tabs, prefixBox, visualHost, visualArea };
}

function createEditor(sun) {
    const aliases = { hiliteColor: 'backgroundColor', formatBlock: 'blockStyle', horizontalRule: 'hr' };
    const list = [['undo', 'redo'], ['blockStyle'], ['bold', 'italic', 'strike', 'removeFormat'], ['list_bulleted', 'list_numbered'], ['blockquote', 'codeBlock'], ['link', 'hr']];
    const plugins = {};
    const buttonList = list.map((group) => group.map((name) => {
        const resolved = aliases[name] || name;
        if (sun.plugins && sun.plugins[resolved]) {
            plugins[resolved] = sun.plugins[resolved];
        }

        return resolved;
    }));

    return sun.create(ui.visualArea, {
        lang: window.SUNEDITOR_LANG && window.SUNEDITOR_LANG.de ? window.SUNEDITOR_LANG.de : undefined,
        height: '18rem',
        width: '100%',
        plugins,
        buttonList,
        charCounter: false,
        statusbar: false,
        // Nur das, was das Markdown des Forums kennt; alles andere filtert SunEditor schon beim Einfügen.
        attributeWhitelist: { a: 'href|title' },
        attributeBlacklist: { '*': 'style|class|id|data-se-iframe-holder|data-se-iframe-holder-attrs' },
        elementBlacklist: 'iframe|script|style|object|embed|img|video|audio|table',
        blockStyle: { items: ['p', 'h2', 'h3', 'h4'] },
        image: { createFileInput: false },
        events: {
            onChange() {
                sync();
            },
        },
    });
}

function html() {
    return editor ? editor.$.html.get() : '';
}

/** Schreibt den Stand des visuellen Editors als Markdown in das Textfeld. */
function sync() {
    if (mode !== 'visual' || !editor) {
        return;
    }
    const markdown = htmlToMarkdown(html());
    textarea.value = prefix !== '' ? (markdown !== '' ? `${prefix}\n\n${markdown}` : `${prefix}\n\n`) : markdown;
}

function setTabs() {
    ui.tabs.forEach((tab) => tab.setAttribute('aria-pressed', tab.dataset.mode === mode ? 'true' : 'false'));
}

async function showVisual(source) {
    const parts = splitQuotePrefix(source);
    if (!isVisualSafe(parts.rest)) {
        say('Dieser Text enthält Formatierungen, die der visuelle Editor nicht darstellt (Tabelle, Bild, Zitatmarker oder HTML). Bitte die Markdown-Ansicht verwenden.', true);
        return false;
    }
    let content = '';
    if (parts.rest.trim() !== '') {
        content = await previewHtml(parts.rest);
    }
    prefix = parts.prefix;
    ui.prefixBox.textContent = prefix !== '' ? prefix.replace(/^>\s?/gm, '').replace(/^\[quote=post:\d+\]\n?/, '') : '';
    ui.prefixBox.hidden = prefix === '';
    editor.$.html.set(content);
    textarea.hidden = true;
    textarea.setAttribute('aria-hidden', 'true');
    textarea.required = false;
    ui.visualHost.hidden = false;
    mode = 'visual';
    setTabs();
    say('', false);
    sync();

    return true;
}

function showMarkdown() {
    if (mode === 'visual') {
        sync();
    }
    restore();
    prefix = '';
    setTabs();
    say('', false);
}

async function boot() {
    ui = buildUi();
    const initial = root.dataset.ccEditor === 'visual';
    textarea.value = textarea.value.replace(/\r\n?/g, '\n');
    ui.tabs.forEach((tab) => tab.addEventListener('click', async () => {
        if (tab.dataset.mode === mode) {
            return;
        }
        if (tab.dataset.mode === 'markdown') {
            showMarkdown();

            return;
        }
        tab.disabled = true;
        try {
            if (!editor) {
                editor = createEditor(await loadSunEditor());
            }
            if (!(await showVisual(textarea.value))) {
                setTabs();
            }
        } catch (error) {
            say('Der visuelle Editor konnte nicht geladen werden. Die Markdown-Ansicht bleibt nutzbar.', true);
            restore();
        } finally {
            tab.disabled = false;
        }
    }));
    form.addEventListener('submit', (event) => {
        sync();
        if (mode === 'visual' && textarea.value.replace(prefix, '').trim() === '') {
            event.preventDefault();
            say('Bitte einen Text eingeben.', true);
            editor.$.focusManager.focus();
        }
    });
    document.addEventListener('pointerdown', (event) => {
        // Die Vorschau liest das Textfeld: vorher synchronisieren.
        if (event.target instanceof Element && event.target.closest('[data-cc-action="preview"]')) {
            sync();
        }
    }, true);

    setTabs();
    if (initial) {
        try {
            editor = createEditor(await loadSunEditor());
            if (!(await showVisual(textarea.value))) {
                restore();
                setTabs();
            }
        } catch (error) {
            say('Der visuelle Editor konnte nicht geladen werden. Die Markdown-Ansicht bleibt nutzbar.', true);
            restore();
        }
    } else {
        restore();
    }
}

if (root && textarea && form) {
    boot().catch(() => restore());
}
