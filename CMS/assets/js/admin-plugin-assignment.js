/**
 * Einstellungen › Plugins › Plugin-Zuweisung
 * „Alle“/„Keine“ je Site-Spalte, Hinweis bei Plugins ohne Site und Warnung bei ungespeicherten Änderungen.
 */

const form = document.querySelector('[data-plugin-assignment]');

if (form instanceof HTMLFormElement) {
    const status = form.querySelector('[data-assignment-status]');
    const checkboxes = () => Array.from(form.querySelectorAll('input[type="checkbox"][data-site]'));
    const snapshot = (boxes) => boxes.map((box) => `${box.name}=${box.value}:${box.checked ? 1 : 0}`).join('|');
    const initialState = snapshot(checkboxes());
    let dirty = false;
    let submitting = false;

    const updateRows = () => {
        form.querySelectorAll('[data-assignment-row]').forEach((row) => {
            const warning = row.querySelector('[data-assignment-warning]');
            if (warning) {
                warning.hidden = row.querySelector('input[type="checkbox"][data-site]:checked') !== null;
            }
        });
    };

    const updateStatus = () => {
        const nowDirty = snapshot(checkboxes()) !== initialState;
        if (nowDirty === dirty) {
            return;
        }
        dirty = nowDirty;
        if (status) {
            status.textContent = dirty ? 'Nicht gespeicherte Änderungen' : '';
            status.classList.toggle('is-dirty', dirty);
        }
    };

    const refresh = () => {
        updateRows();
        updateStatus();
    };

    form.addEventListener('change', (event) => {
        if (event.target instanceof HTMLInputElement && event.target.type === 'checkbox') {
            refresh();
        }
    });

    form.addEventListener('click', (event) => {
        const button = event.target instanceof Element ? event.target.closest('[data-assignment-bulk]') : null;
        if (!(button instanceof HTMLButtonElement)) {
            return;
        }
        const checked = button.dataset.assignmentBulk === 'all';
        const site = button.dataset.site ?? '';
        checkboxes()
            .filter((box) => box.dataset.site === site)
            .forEach((box) => {
                box.checked = checked;
            });
        refresh();
    });

    form.addEventListener('submit', () => {
        submitting = true;
    });

    window.addEventListener('beforeunload', (event) => {
        if (dirty && !submitting) {
            event.preventDefault();
            event.returnValue = '';
        }
    });

    updateRows();
}
