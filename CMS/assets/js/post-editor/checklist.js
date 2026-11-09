/**
 * 365CMS Beitragseditor – Veröffentlichungs-Checkliste.
 *
 * Prüft live dieselben drei Punkte wie PostPublishChecklistService (serverseitig maßgeblich):
 *   1. Beitragsbild gesetzt
 *   2. Meta-Beschreibung 120–160 Zeichen
 *   3. Kategorie gesetzt (Hauptkategorie oder erste weitere Kategorie)
 *
 * Solange ein Punkt fehlt, tragen „Veröffentlichen“ und „Planen“ (bzw. „Speichern“ mit Status
 * „Veröffentlicht“, wenn der Beitrag dadurch live ginge) aria-disabled="true". Die Buttons bleiben
 * fokussierbar; ein Klick öffnet einen Hinweisdialog mit den fehlenden Punkten und einem
 * Sprung zum ersten fehlenden Feld.
 */

import { clearChildren, createElement, setupDialog } from 'cms-editor/dialog.js';

export const META_DESCRIPTION_MIN = 120;
export const META_DESCRIPTION_MAX = 160;

const GATE_HINT_ID = 'postChecklistGateHint';
const SCHEDULE_DATE_HINT = 'Zum Planen bitte Datum und Uhrzeit in der Zukunft wählen.';

const FIELD_TARGETS = {
    featured_image: { panel: 'postPanelFeatured', focus: 'btnSelectImage' },
    meta_description: { panel: 'postPanelSeoDescription', focus: 'metaDesc' },
    category_id: { panel: 'postPanelCategory', focus: 'categoryId' },
    publish_date: { panel: 'postPanelPublication', focus: 'publishDate' },
};

/**
 * Zeichenzahl wie PostPublishChecklistService::metaDescriptionLength()
 * (Leerraum zusammengefasst, getrimmt, Unicode-Zeichen statt UTF-16-Einheiten).
 */
export function metaDescriptionLength(value) {
    return Array.from(String(value ?? '').replace(/\s+/gu, ' ').trim()).length;
}

function metaHint(length) {
    if (length === 0) {
        return 'Meta-Beschreibung fehlt (120–160 Zeichen).';
    }
    if (length < META_DESCRIPTION_MIN) {
        return 'Meta-Beschreibung zu kurz: ' + length + ' von mindestens 120 Zeichen.';
    }
    if (length > META_DESCRIPTION_MAX) {
        return 'Meta-Beschreibung zu lang: ' + length + ' von höchstens 160 Zeichen.';
    }

    return 'Meta-Beschreibung hat ' + length + ' Zeichen.';
}

function metaState(length) {
    if (length === 0) {
        return 'is-empty';
    }
    if (length < META_DESCRIPTION_MIN) {
        return 'is-short';
    }

    return length > META_DESCRIPTION_MAX ? 'is-long' : 'is-ok';
}

/**
 * Datum + Uhrzeit aus den Formularfeldern (lokale Zeit), analog zu normalizePublishedAtInput().
 *
 * @returns {Date|null}
 */
function parseLocalDateTime(dateValue, timeValue) {
    const date = String(dateValue ?? '').trim();
    const time = String(timeValue ?? '').trim();
    const dateMatch = /^(\d{4})-(\d{2})-(\d{2})$/.exec(date);
    const timeMatch = /^(\d{2}):(\d{2})(?::\d{2}(?:\.\d+)?)?$/.exec(time === '' ? '00:00' : time);
    if (!dateMatch || !timeMatch) {
        return null;
    }

    const result = new Date(
        Number(dateMatch[1]),
        Number(dateMatch[2]) - 1,
        Number(dateMatch[3]),
        Number(timeMatch[1]),
        Number(timeMatch[2]),
        0,
        0
    );

    return Number.isNaN(result.getTime()) ? null : result;
}

function parseIsoDate(value) {
    const text = String(value ?? '').trim();
    if (text === '') {
        return null;
    }
    const result = new Date(text);

    return Number.isNaN(result.getTime()) ? null : result;
}

function isFuture(date) {
    return date instanceof Date && date.getTime() > Date.now();
}

function setText(element, text) {
    if (element && element.textContent !== text) {
        element.textContent = text;
    }
}

function setDescribedBy(element, id, active) {
    const tokens = String(element.getAttribute('aria-describedby') || '').split(/\s+/).filter(Boolean);
    const has = tokens.includes(id);
    if (active && !has) {
        tokens.push(id);
    } else if (!active && has) {
        tokens.splice(tokens.indexOf(id), 1);
    }

    if (tokens.length > 0) {
        element.setAttribute('aria-describedby', tokens.join(' '));
    } else {
        element.removeAttribute('aria-describedby');
    }
}

function prefersReducedMotion() {
    return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
}

function buttonLabel(button) {
    const label = button.querySelector('.cms-editor-header__label');

    return String((label ? label.textContent : button.textContent) || '').trim();
}

/**
 * @param {HTMLFormElement} form
 * @param {{beforeReveal?: () => void, announce?: (message: string) => void}} options
 */
export function initChecklist(form, { beforeReveal = null, announce = null } = {}) {
    const section = document.getElementById('postPublishChecklist');
    const summary = document.getElementById('postChecklistSummary');
    const featuredInput = document.getElementById('featuredInput');
    const featuredTempInput = document.getElementById('featuredInput_temp_path');
    const featuredPreview = document.getElementById('featuredPreview');
    const removeImageButton = document.getElementById('btnRemoveImage');
    const selectImageButton = document.getElementById('btnSelectImage');
    const metaInput = document.getElementById('metaDesc');
    const metaCounter = document.getElementById('metaDescCounter');
    const metaCount = document.getElementById('metaDescCount');
    const categorySelect = document.getElementById('categoryId');
    const additionalCategorySelect = document.getElementById('additionalCategoryIds');
    const kickerText = document.getElementById('postCategoryLabelText');
    const statusSelect = document.getElementById('status');
    const publishDate = document.getElementById('publishDate');
    const publishTime = document.getElementById('publishTime');
    const contentUpdatedDate = document.getElementById('contentUpdatedDate');
    const contentUpdatedTime = document.getElementById('contentUpdatedTime');
    const treatUpdateAsNew = document.getElementById('treatUpdateAsNew');
    const dialog = document.getElementById('postChecklistDialog');
    const dialogText = document.getElementById('postChecklistDialogText');
    const dialogList = document.getElementById('postChecklistDialogList');
    const dialogJump = dialog ? dialog.querySelector('[data-dialog-jump]') : null;

    const isNew = form.dataset.isNew === '1';
    const storedStatus = String(form.dataset.storedStatus || '');
    const storedPublishedAt = parseIsoDate(form.dataset.storedPublishedAt);
    const items = section ? Array.from(section.querySelectorAll('[data-checklist-item]')) : [];
    const gatedButtons = Array.from(document.querySelectorAll('[data-checklist-gate]'))
        .filter((button) => button instanceof HTMLButtonElement && button.form === form);

    let lastResult = { passed: true, items: [] };
    let pendingJumpField = '';
    let lastFeaturedValue = '';

    const dialogController = dialog instanceof HTMLDialogElement
        ? setupDialog(dialog, {
            onClose() {
                const field = pendingJumpField;
                pendingJumpField = '';
                if (field !== '') {
                    revealField(field);
                    return true;
                }
                return false;
            },
        })
        : null;

    function readFeaturedValue() {
        const value = featuredInput ? String(featuredInput.value || '').trim() : '';
        if (value !== '') {
            return value;
        }

        return featuredTempInput ? String(featuredTempInput.value || '').trim() : '';
    }

    function selectedAdditionalOptions() {
        if (!(additionalCategorySelect instanceof HTMLSelectElement)) {
            return [];
        }

        return Array.from(additionalCategorySelect.selectedOptions)
            .filter((option) => Number.parseInt(option.value, 10) > 0);
    }

    function resolveCategory() {
        const primaryId = categorySelect ? Number.parseInt(categorySelect.value || '0', 10) || 0 : 0;
        if (primaryId > 0 && categorySelect instanceof HTMLSelectElement) {
            const option = categorySelect.options[categorySelect.selectedIndex] || null;

            return { id: primaryId, name: option ? String(option.dataset.categoryName || option.textContent || '').trim() : '' };
        }

        const firstAdditional = selectedAdditionalOptions()[0] || null;
        if (firstAdditional) {
            return {
                id: Number.parseInt(firstAdditional.value, 10) || 0,
                name: String(firstAdditional.dataset.categoryName || firstAdditional.textContent || '').trim(),
            };
        }

        return { id: 0, name: '' };
    }

    function evaluate() {
        const featuredSet = readFeaturedValue() !== '';
        const metaLength = metaDescriptionLength(metaInput ? metaInput.value : '');
        const metaPassed = metaLength >= META_DESCRIPTION_MIN && metaLength <= META_DESCRIPTION_MAX;
        const category = resolveCategory();

        const result = {
            metaLength,
            category,
            items: [
                {
                    id: 'featured_image',
                    field: 'featured_image',
                    passed: featuredSet,
                    hint: featuredSet ? 'Beitragsbild ist gesetzt.' : 'Beitragsbild fehlt.',
                },
                {
                    id: 'meta_description',
                    field: 'meta_description',
                    passed: metaPassed,
                    hint: metaHint(metaLength),
                },
                {
                    id: 'category',
                    field: 'category_id',
                    passed: category.id > 0,
                    hint: category.id > 0 ? 'Kategorie ist gesetzt.' : 'Kategorie fehlt.',
                },
            ],
        };
        result.passed = result.items.every((item) => item.passed);

        return result;
    }

    function isStoredLive() {
        return storedStatus === 'published' && !isFuture(storedPublishedAt);
    }

    /**
     * Spiegel von PostsModule::resolveTargetPublishedAt() + PostPublishChecklistService::requiresChecklist().
     *
     * @param {string} intent '' (Speichern), 'publish' oder 'schedule'
     */
    function requiresChecklist(intent) {
        const status = intent !== '' ? 'published' : String(statusSelect ? statusSelect.value : '');
        if (status !== 'published') {
            return false;
        }

        let target = parseLocalDateTime(publishDate?.value, publishTime?.value) || storedPublishedAt || new Date();
        if (!isNew && treatUpdateAsNew instanceof HTMLInputElement && treatUpdateAsNew.checked) {
            target = parseLocalDateTime(contentUpdatedDate?.value, contentUpdatedTime?.value) || target;
        }
        if (intent === 'publish' && isFuture(target)) {
            target = new Date();
        }

        return intent === 'schedule' || isFuture(target) || !isStoredLive();
    }

    function buttonIntent(button) {
        return button.name === 'publish_intent' ? String(button.value || '') : '';
    }

    function updateItems(result) {
        items.forEach((element) => {
            const data = result.items.find((item) => item.id === element.dataset.checklistItem);
            if (!data) {
                return;
            }

            element.classList.toggle('is-passed', data.passed);
            element.classList.toggle('is-missing', !data.passed);

            const icon = element.querySelector('[data-checklist-icon]');
            if (icon) {
                icon.classList.toggle('ti-circle-check', data.passed);
                icon.classList.toggle('ti-circle-x', !data.passed);
            }

            setText(element.querySelector('[data-checklist-state]'), data.passed ? ' – erfüllt' : ' – fehlt');
            setText(element.querySelector('[data-checklist-hint]'), data.hint);

            const jump = element.querySelector('.cms-editor-checklist__jump');
            if (jump instanceof HTMLElement) {
                jump.hidden = data.passed;
            }
        });

        const passedCount = result.items.filter((item) => item.passed).length;
        setText(summary, passedCount + ' von ' + result.items.length + ' erfüllt');
        section?.classList.toggle('is-complete', result.passed);
    }

    function updateMetaCounter(result) {
        setText(metaCount, String(result.metaLength));
        if (metaCounter) {
            const state = metaState(result.metaLength);
            ['is-empty', 'is-short', 'is-ok', 'is-long'].forEach((name) => {
                metaCounter.classList.toggle(name, name === state);
            });
        }
    }

    function updateKicker(result) {
        setText(kickerText, result.category.id > 0 && result.category.name !== '' ? result.category.name : 'Kategorie wählen');
    }

    function updateGates(result) {
        gatedButtons.forEach((button) => {
            const gated = !result.passed && requiresChecklist(buttonIntent(button));
            if (gated) {
                button.setAttribute('aria-disabled', 'true');
            } else {
                button.removeAttribute('aria-disabled');
            }
            button.classList.toggle('cms-editor-gated', gated);
            setDescribedBy(button, GATE_HINT_ID, gated);
        });
    }

    function refresh() {
        lastResult = evaluate();
        updateItems(lastResult);
        updateMetaCounter(lastResult);
        updateKicker(lastResult);
        updateGates(lastResult);

        return lastResult;
    }

    function revealField(field) {
        const target = FIELD_TARGETS[field];
        if (!target) {
            return;
        }

        beforeReveal?.();

        const panel = document.getElementById(target.panel);
        if (panel instanceof HTMLDetailsElement && !panel.open) {
            panel.open = true;
        }

        const element = document.getElementById(target.focus) || panel;
        if (!element) {
            return;
        }

        element.scrollIntoView({ block: 'center', behavior: prefersReducedMotion() ? 'auto' : 'smooth' });
        element.focus({ preventScroll: true });
    }

    function collectProblems(button) {
        const problems = [];
        if (requiresChecklist(buttonIntent(button))) {
            lastResult.items.filter((item) => !item.passed).forEach((item) => {
                problems.push({ field: item.field, hint: item.hint });
            });
        }

        if (buttonIntent(button) === 'schedule' && !isFuture(parseLocalDateTime(publishDate?.value, publishTime?.value))) {
            problems.push({ field: 'publish_date', hint: SCHEDULE_DATE_HINT });
        }

        return problems;
    }

    function openDialog(button, problems) {
        const label = buttonLabel(button) || 'Speichern';

        if (!dialogController) {
            announce?.('„' + label + '“ ist noch nicht möglich: ' + problems.map((problem) => problem.hint).join(' '));
            revealField(problems[0].field);
            return;
        }

        setText(dialogText, '„' + label + '“ ist noch nicht möglich. Bitte zuerst diese Punkte ergänzen:');
        clearChildren(dialogList);
        problems.forEach((problem) => {
            dialogList?.appendChild(createElement('li', '', problem.hint));
        });

        pendingJumpField = '';
        if (dialogJump instanceof HTMLElement) {
            dialogJump.dataset.dialogJump = problems[0].field;
        }
        dialogController.open(button);
    }

    dialogJump?.addEventListener('click', () => {
        pendingJumpField = String(dialogJump.dataset.dialogJump || '');
        dialogController?.close();
    });

    gatedButtons.forEach((button) => {
        button.addEventListener('click', (event) => {
            refresh();
            const problems = collectProblems(button);
            if (problems.length === 0) {
                return;
            }

            event.preventDefault();
            event.stopImmediatePropagation();
            openDialog(button, problems);
        });
    });

    document.addEventListener('click', (event) => {
        const trigger = event.target instanceof Element ? event.target.closest('[data-jump-field]') : null;
        if (!trigger) {
            return;
        }

        event.preventDefault();
        revealField(String(trigger.getAttribute('data-jump-field') || ''));
    });

    // Das Beitragsbild wird von picker/initUi ohne Events gesetzt bzw. entfernt:
    // Änderungen an der Vorschau beobachten und als change-Event am Feld melden
    // (aktualisiert auch die SEO-Analyse und markiert ungespeicherte Änderungen).
    lastFeaturedValue = readFeaturedValue();
    if (featuredPreview) {
        new MutationObserver(() => {
            const value = readFeaturedValue();
            if (value === lastFeaturedValue) {
                return;
            }
            lastFeaturedValue = value;
            featuredInput?.dispatchEvent(new Event('change', { bubbles: true }));
        }).observe(featuredPreview, { childList: true, subtree: true, attributes: true, attributeFilter: ['class', 'src'] });
    }

    removeImageButton?.addEventListener('click', () => {
        // Der Entfernen-Button verschwindet nach dem Klick – Fokus nicht ins Leere fallen lassen.
        window.setTimeout(() => {
            const active = document.activeElement;
            if (removeImageButton.classList.contains('d-none') && (active === removeImageButton || active === document.body || active === null)) {
                selectImageButton?.focus();
            }
        }, 0);
    });

    // Nur Formularfelder neu bewerten – Tastenanschläge im EditorJS-Inhalt sind für die Checkliste irrelevant.
    const handleFieldEvent = (event) => {
        if (event.target instanceof Element && event.target.matches('input, select, textarea')) {
            refresh();
        }
    };
    form.addEventListener('input', handleFieldEvent);
    form.addEventListener('change', handleFieldEvent);
    // Termine laufen ab bzw. werden erreicht – Sperren für „Speichern“ minütlich neu bewerten.
    window.setInterval(() => updateGates(lastResult), 60000);

    refresh();

    return {
        refresh,
        revealField,
        getResult: () => lastResult,
    };
}
