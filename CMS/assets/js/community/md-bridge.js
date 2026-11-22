/*
 * Community-Editor (4.2.0): Brücke vom Visuellen Editor (SunEditor, HTML) zur Markdown-Quelle des Forums.
 *
 * Das Forum speichert Markdown (CommonMark + GFM, ohne Roh-HTML); der Server rendert und bereinigt. Der visuelle Editor ist nur eine
 * Eingabehilfe im Browser: `htmlToMarkdown()` bildet den kleinen Umfang ab, den die Werkzeugleiste anbietet (Absätze, Überschriften,
 * fett, kursiv, durchgestrichen, Code, Links, Listen, Zitate, Codeblöcke, Trennlinie). Alles andere wird entfaltet (nur der Text bleibt),
 * Skripte, Stile und Frames entfallen. Links nur mit http, https, mailto oder relativ. Die Funktionen sind rein und ohne Abhängigkeiten.
 */

const SKIP = new Set(['SCRIPT', 'STYLE', 'IFRAME', 'OBJECT', 'EMBED', 'TEMPLATE', 'NOSCRIPT', 'IMG', 'VIDEO', 'AUDIO', 'SVG']);
const BLOCKS = new Set(['P', 'DIV', 'SECTION', 'ARTICLE', 'HEADER', 'FOOTER', 'FIGURE', 'FIGCAPTION', 'TABLE', 'THEAD', 'TBODY', 'TFOOT', 'TR', 'DL', 'DT', 'DD']);
const URL_PATTERN = /https?:\/\/[^\s<>]+/g;

/** Maskiert Markdown-Zeichen in Fließtext; Web-Adressen bleiben unverändert (Autolinks). */
export function escapeText(text) {
    const parts = [];
    let last = 0;
    for (const match of text.matchAll(URL_PATTERN)) {
        parts.push(escapePlain(text.slice(last, match.index)), match[0]);
        last = match.index + match[0].length;
    }
    parts.push(escapePlain(text.slice(last)));

    return parts.join('');
}

function escapePlain(text) {
    return text.replace(/([\\`*_[\]<>~|])/g, '\\$1');
}

/** Maskiert Zeilenanfänge, die Markdown sonst als Block deutet (Überschrift, Liste, Zitat). */
function escapeLineStarts(markdown) {
    return markdown.split('\n').map((line) => line.replace(/^(\s{0,3})(#{1,6}(?=\s)|[-+](?=\s)|\d{1,9}[.)](?=\s)|=+\s*$)/, '$1\\$2')).join('\n');
}

function safeUrl(value) {
    const raw = String(value || '').trim();
    if (raw === '' || /[\u0000-\u001f\u007f]/.test(raw)) {
        return null;
    }
    if (raw.startsWith('#') || (raw.startsWith('/') && !raw.startsWith('//'))) {
        return raw;
    }
    try {
        const url = new URL(raw);
        return ['http:', 'https:', 'mailto:'].includes(url.protocol) ? raw : null;
    } catch (error) {
        return null;
    }
}

function encodeUrl(value) {
    return value.replace(/ /g, '%20').replace(/\(/g, '%28').replace(/\)/g, '%29');
}

function fenceFor(code) {
    let longest = 0;
    for (const run of code.match(/`+/g) || []) {
        longest = Math.max(longest, run.length);
    }

    return '`'.repeat(Math.max(3, longest + 1));
}

function inlineCode(code) {
    const text = code.replace(/\n/g, ' ');
    const fence = '`'.repeat(Math.max(1, ...(text.match(/`+/g) || ['']).map((run) => run.length + 1)));
    const pad = text.startsWith('`') || text.endsWith('`') ? ' ' : '';

    return fence + pad + text + pad + fence;
}

function inline(node, state) {
    let out = '';
    for (const child of node.childNodes) {
        out += walk(child, state);
    }

    return out;
}

function wrap(mark, content) {
    // Ein Umbruch am Rand (Platzhalter von SunEditor) gehört nicht in die Auszeichnung.
    content = content.replace(/^(?:\\\n)+|(?:\\\n)+(?=\s*$)/g, '');
    const trimmed = content.replace(/^\s+|\s+$/g, '');
    if (trimmed === '') {
        return content;
    }
    const lead = content.slice(0, content.length - content.trimStart().length);
    const tail = content.slice(content.trimEnd().length);

    return lead + mark + trimmed + mark + tail;
}

function list(node, state, ordered) {
    const items = [];
    let index = Number.parseInt(node.getAttribute('start') || '1', 10);
    index = Number.isFinite(index) && index > 0 ? index : 1;
    for (const child of node.children) {
        if (child.tagName !== 'LI') {
            continue;
        }
        const marker = ordered ? `${index++}. ` : '- ';
        const body = blocks(child, { ...state, inList: true }).trim();
        const indent = ' '.repeat(marker.length);
        items.push(marker + body.split('\n').map((line, i) => (i === 0 ? line : (line === '' ? '' : indent + line))).join('\n'));
    }

    return items.join('\n');
}

/** Block-Ebene: liefert Markdown mit Leerzeilen zwischen den Blöcken. */
function blocks(node, state) {
    const chunks = [];
    const kinds = [];
    let run = '';
    const add = (text, kind) => {
        chunks.push(text);
        kinds.push(kind);
    };
    const flush = () => {
        const text = run.replace(/[ \t]+\n/g, '\n').replace(/^(?:\\\n)+|(?:\\\n)+$/g, '').trim();
        if (text !== '') {
            add(escapeLineStarts(text), 'text');
        }
        run = '';
    };
    for (const child of node.childNodes) {
        if (child.nodeType === 1 && (child.tagName === 'UL' || child.tagName === 'OL')) {
            flush();
            add(list(child, state, child.tagName === 'OL'), 'list');
        } else if (child.nodeType === 1 && child.tagName === 'PRE') {
            flush();
            const code = child.textContent.replace(/\n+$/, '');
            const fence = fenceFor(code);
            add(`${fence}\n${code}\n${fence}`, 'block');
        } else if (child.nodeType === 1 && child.tagName === 'BLOCKQUOTE') {
            flush();
            const quoted = blocks(child, state).trim();
            if (quoted !== '') {
                add(quoted.split('\n').map((line) => (line === '' ? '>' : `> ${line}`)).join('\n'), 'block');
            }
        } else if (child.nodeType === 1 && /^H[1-6]$/.test(child.tagName)) {
            flush();
            // Das Forum zeigt Überschriften ab Ebene 2 (die Themenüberschrift ist h1).
            const level = Math.min(4, Math.max(2, Number(child.tagName[1])));
            const text = inline(child, state).replace(/\s+/g, ' ').trim();
            if (text !== '') {
                add('#'.repeat(level) + ' ' + text, 'block');
            }
        } else if (child.nodeType === 1 && child.tagName === 'HR') {
            flush();
            add('---', 'block');
        } else if (child.nodeType === 1 && BLOCKS.has(child.tagName)) {
            flush();
            const inner = blocks(child, state);
            if (inner.trim() !== '') {
                add(inner.trim(), 'block');
            }
        } else {
            run += walk(child, state);
        }
    }
    flush();

    // Innerhalb eines Listenpunkts hängt eine verschachtelte Liste ohne Leerzeile am Text (enge Liste).
    return chunks.map((chunk, i) => (i === 0 ? chunk : (state.inList && kinds[i] === 'list' && kinds[i - 1] === 'text' ? '\n' : '\n\n') + chunk)).join('');
}

function walk(node, state) {
    if (node.nodeType === 3) {
        // SunEditor setzt Nullbreiten-Zeichen als Platzhalter für den Cursor (U+200B); sie gehören nicht in die Quelle.
        const clean = node.nodeValue.replace(/[\u200b-\u200d\u2060\ufeff]/g, '');
        const text = state.pre ? clean : clean.replace(/\u00a0/g, ' ').replace(/[ \t\r\n]+/g, ' ');

        return escapeText(text);
    }
    if (node.nodeType !== 1 || SKIP.has(node.tagName.toUpperCase())) {
        return '';
    }
    switch (node.tagName) {
        case 'BR':
            // Harter Umbruch mit Backslash (CommonMark): überlebt das Kürzen von Zeilenenden.
            return '\\\n';
        case 'STRONG':
        case 'B':
            return wrap('**', inline(node, state));
        case 'EM':
        case 'I':
            return wrap('*', inline(node, state));
        case 'DEL':
        case 'S':
        case 'STRIKE':
            return wrap('~~', inline(node, state));
        case 'CODE':
            return node.textContent.trim() === '' ? '' : inlineCode(node.textContent);
        case 'A': {
            const text = inline(node, state);
            const url = safeUrl(node.getAttribute('href'));
            if (url === null || text.trim() === '') {
                return text;
            }
            const title = (node.getAttribute('title') || '').replace(/"/g, '').trim();

            return `[${text.trim()}](${encodeUrl(url)}${title !== '' ? ` "${title}"` : ''})`;
        }
        case 'UL':
        case 'OL':
        case 'PRE':
        case 'BLOCKQUOTE':
            return '\n\n' + blocks({ childNodes: [node] }, state) + '\n\n';
        default:
            // Blöcke innerhalb von Inline-Läufen (verschachtelte div/p): als eigener Absatz.
            if (BLOCKS.has(node.tagName) || /^H[1-6]$/.test(node.tagName)) {
                return '\n\n' + blocks({ childNodes: [node] }, state) + '\n\n';
            }

            return inline(node, state);
    }
}

/**
 * HTML aus dem Editor → Markdown. Wirft nie: bei einem Fehler kommt der reine Text zurück.
 *
 * @param {string} html
 * @returns {string}
 */
export function htmlToMarkdown(html) {
    try {
        const doc = new DOMParser().parseFromString(String(html || ''), 'text/html');
        const markdown = blocks(doc.body, { pre: false, inList: false });

        return markdown.replace(/\n{3,}/g, '\n\n').trim();
    } catch (error) {
        const probe = document.createElement('div');
        probe.textContent = String(html || '').replace(/<[^>]*>/g, ' ');

        return escapeText(probe.textContent.replace(/\s+/g, ' ').trim());
    }
}

/**
 * Trennt ein führendes Zitat (`> [quote=post:ID]` …, vom Server erzeugt) vom Rest: der Editor bearbeitet nur den Rest, das Zitat
 * bleibt unverändert erhalten (die Markerzeile gehört dem Server).
 *
 * @param {string} source
 * @returns {{prefix: string, rest: string}}
 */
export function splitQuotePrefix(source) {
    const lines = String(source || '').replace(/\r\n?/g, '\n').split('\n');
    let end = 0;
    while (end < lines.length && (lines[end].startsWith('>') || (lines[end] === '' && end > 0 && lines[end + 1]?.startsWith('>')))) {
        end++;
    }
    if (end === 0 || !/^>\s*\[quote=post:\d+\]/.test(lines[0])) {
        return { prefix: '', rest: lines.join('\n') };
    }

    return { prefix: lines.slice(0, end).join('\n').trim(), rest: lines.slice(end).join('\n').replace(/^\n+/, '') };
}

/**
 * Lässt sich die Markdown-Quelle ohne Verlust im visuellen Editor darstellen? Tabellen, Bilder, Aufgabenlisten, Fußnoten, Roh-HTML,
 * eingerückte Codeblöcke und Zitatmarker im Text brauchen die Markdown-Ansicht.
 *
 * @param {string} source
 * @returns {boolean}
 */
export function isVisualSafe(source) {
    const text = String(source || '');

    return !/(^|\n)\s*\|.*\|\s*(\n|$)|!\[|(^|\n)\s*[-*+]\s+\[[ xX]\]|\[\^|<[a-zA-Z!\/]|(^|\n) {4,}\S|\[quote=post:|(^|\n)\s*(\d+\.|[-*+])\s+(\d+\.|[-*+])\s/.test(text);
}
